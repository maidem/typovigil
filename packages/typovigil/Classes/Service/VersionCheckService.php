<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Service;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Asks the upstream sources what the newest version of a package is and whether
 * anything is known to be wrong with it.
 *
 * Runs on the hub only — if every agent queried these APIs itself, the same
 * questions would be asked once per monitored instance.
 */
final readonly class VersionCheckService
{
    private const PACKAGIST_PACKAGE = 'https://repo.packagist.org/p2/%s.json';
    private const PACKAGIST_ADVISORIES = 'https://packagist.org/api/security-advisories/';
    private const TYPO3_RELEASES = 'https://get.typo3.org/json';
    private const TER_EXTENSION = 'https://extensions.typo3.org/api/v1/extension/%s';

    private const CACHE_LIFETIME = 3600;
    private const TIMEOUT = 15;

    /**
     * TYPO3 major versions still receiving updates. Reviewed by hand rather than
     * derived from get.typo3.org, which lists every major ever released.
     */
    private const MAINTAINED_MAJORS = ['12', '13', '14'];

    /**
     * Reachability lives in a table, not in the cache: it records what happened
     * rather than a result that can be recomputed, and the container entrypoint
     * flushes every cache on start — a cached status disappeared with each
     * deployment and the footer claimed "not queried yet" although the check
     * had run.
     */
    private const TABLE_SOURCE = 'tx_typovigil_source';

    /**
     * Host => label shown to the customer. Keyed by host so fetchJson() can
     * attribute a URL to a source without every caller passing a name.
     */
    private const SOURCES = [
        'get.typo3.org' => 'get.typo3.org',
        'repo.packagist.org' => 'Packagist',
        'packagist.org' => 'Sicherheitsmeldungen',
        'extensions.typo3.org' => 'TYPO3 Extension Repository',
    ];

    public function __construct(
        private FrontendInterface $cache,
        private RequestFactory $requestFactory,
        private LoggerInterface $logger,
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * Newest stable version of a Composer package, or '' when unknown.
     */
    public function latestVersion(string $composerName): string
    {
        if ($composerName === '') {
            return '';
        }

        $cacheKey = 'pkg_' . md5($composerName);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== false) {
            return (string)$cached;
        }

        $data = $this->fetchJson(sprintf(self::PACKAGIST_PACKAGE, $composerName));
        if ($data === []) {
            return '';
        }

        $versions = $data['packages'][$composerName] ?? [];

        $latest = '';
        foreach ($versions as $entry) {
            $version = (string)($entry['version'] ?? '');
            // Skip branches (dev-main) and pre-releases: nobody should be nudged
            // towards an unstable release by a monitoring tool.
            if ($version === '' || str_starts_with($version, 'dev-') || preg_match('/-(alpha|beta|rc|dev)/i', $version)) {
                continue;
            }
            $normalized = ltrim($version, 'vV');
            if ($latest === '' || version_compare($normalized, $latest, '>')) {
                $latest = $normalized;
            }
        }

        $this->cache->set($cacheKey, $latest, [], self::CACHE_LIFETIME);

        return $latest;
    }

    /**
     * Security advisories for a set of packages, keyed by Composer name.
     *
     * One request for the whole list — Packagist aggregates the FriendsOfPHP
     * database, so there is no need to scrape that repository separately.
     *
     * @param list<string> $composerNames
     * @return array<string, list<array<string, mixed>>>
     */
    public function advisories(array $composerNames): array
    {
        $composerNames = array_values(array_unique(array_filter($composerNames)));
        if ($composerNames === []) {
            return [];
        }

        sort($composerNames);
        $cacheKey = 'adv_' . md5(implode(',', $composerNames));
        $cached = $this->cache->get($cacheKey);
        if ($cached !== false) {
            return (array)$cached;
        }

        $query = http_build_query(['packages' => $composerNames]);
        $data = $this->fetchJson(self::PACKAGIST_ADVISORIES . '?' . $query);
        if ($data === []) {
            return [];
        }

        $advisories = is_array($data['advisories'] ?? null) ? $data['advisories'] : [];

        $this->cache->set($cacheKey, $advisories, [], self::CACHE_LIFETIME);

        return $advisories;
    }

    /**
     * All releases of a TYPO3 major, each with its type ("security" or "regular").
     *
     * @return list<array{version: string, type: string}>
     */
    public function coreReleases(string $installedVersion): array
    {
        $major = explode('.', ltrim($installedVersion, 'vV'))[0] ?? '';
        if (!ctype_digit($major)) {
            return [];
        }

        $cacheKey = 'core_' . $major;
        $cached = $this->cache->get($cacheKey);
        if ($cached !== false) {
            return (array)$cached;
        }

        $data = $this->fetchJson(self::TYPO3_RELEASES);
        if ($data === []) {
            // Do not cache a failure: it would keep the footer showing "no
            // contact" for an hour after the source is back, and would blank
            // real version data in the meantime.
            return [];
        }

        $releases = [];
        foreach ($data[$major]['releases'] ?? [] as $release) {
            $version = (string)($release['version'] ?? '');
            if ($version === '') {
                continue;
            }
            $releases[] = [
                'version' => $version,
                'type' => (string)($release['type'] ?? 'regular'),
            ];
        }

        $this->cache->set($cacheKey, $releases, [], self::CACHE_LIFETIME);

        return $releases;
    }

    /**
     * Currently maintained TYPO3 major versions, newest release each — shown on
     * the public landing page so a visitor can check their own installation
     * without logging in, or an account at all.
     *
     * @return list<array{major: string, version: string, severity: string, severityLabel: string}>
     */
    public function maintainedCoreVersions(): array
    {
        $cacheKey = 'core_public_summary';
        $cached = $this->cache->get($cacheKey);
        if ($cached !== false) {
            return (array)$cached;
        }

        $data = $this->fetchJson(self::TYPO3_RELEASES);
        if ($data === []) {
            return [];
        }

        $summary = [];
        foreach (self::MAINTAINED_MAJORS as $major) {
            $releases = $data[$major]['releases'] ?? [];
            if ($releases === []) {
                continue;
            }
            // get.typo3.org lists releases newest first.
            $newest = reset($releases);
            $isSecurity = ($newest['type'] ?? '') === 'security';
            $summary[] = [
                'major' => $major,
                'version' => (string)($newest['version'] ?? ''),
                // A ready-made string rather than a boolean: Fluid's inline
                // f:if does not interpolate into a class-name expression, so
                // the template would need it as a string either way.
                'severity' => $isSecurity ? 'critical' : 'ok',
                'severityLabel' => $isSecurity ? 'Sicherheitsupdate' : 'Aktuell',
            ];
        }

        $this->cache->set($cacheKey, $summary, [], self::CACHE_LIFETIME);

        return $summary;
    }

    /**
     * Resolves an extension key to its Composer name via the TER.
     *
     * Only needed for extensions the agent reported without one; the TER answer
     * carries the Composer name in meta.packagist.
     */
    public function composerNameForExtensionKey(string $extensionKey): string
    {
        if ($extensionKey === '') {
            return '';
        }

        $cacheKey = 'ter_' . md5($extensionKey);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== false) {
            return (string)$cached;
        }

        $data = $this->fetchJson(sprintf(self::TER_EXTENSION, $extensionKey));
        if ($data === []) {
            return '';
        }

        $name = (string)($data[0]['meta']['composer_name'] ?? '');

        $this->cache->set($cacheKey, $name, [], self::CACHE_LIFETIME);

        return $name;
    }

    /**
     * Reachability of every source at its last attempt, for the footer.
     *
     * A source nobody has queried yet reports 'unknown' rather than a failure —
     * on a fresh installation the scheduler simply has not run, and painting
     * that red would be a false alarm.
     *
     * 'state' collapses the three cases into one value the templates can append
     * to a class name — Fluid cannot nest a conditional inside an inline one.
     *
     * 'checkedAtLabel' is formatted here rather than in the templates: Fluid
     * cannot nest a conditional date inside an attribute value, and both views
     * want the same string.
     *
     * @return list<array{label: string, state: 'ok'|'down'|'unknown', checkedAt: int, checkedAtLabel: string}>
     */
    public function sourceStatus(): array
    {
        return self::mapSourceRows($this->storedSourceRows());
    }

    /**
     * Turns stored rows into what the footer renders, keyed by host.
     *
     * Static and free of dependencies so it can be checked on its own: the
     * three-state mapping and the per-host attribution are the parts worth
     * testing, and neither needs a database to be exercised.
     *
     * @param array<string, array{reachable: int|bool, checked_at: int}> $stored
     * @return list<array{label: string, state: 'ok'|'down'|'unknown', checkedAt: int, checkedAtLabel: string}>
     */
    public static function mapSourceRows(array $stored): array
    {
        $status = [];
        foreach (self::SOURCES as $host => $label) {
            $entry = $stored[$host] ?? null;
            $checkedAt = (int)($entry['checked_at'] ?? 0);
            $status[] = [
                'label' => $label,
                'state' => match (true) {
                    $entry === null => 'unknown',
                    (bool)($entry['reachable'] ?? false) => 'ok',
                    default => 'down',
                },
                'checkedAt' => $checkedAt,
                'checkedAtLabel' => $checkedAt > 0 ? date('d.m.Y H:i', $checkedAt) : '',
            ];
        }

        return $status;
    }

    /**
     * @return array<string, array{reachable: int, checked_at: int}>
     */
    private function storedSourceRows(): array
    {
        try {
            $rows = $this->connectionPool
                ->getConnectionForTable(self::TABLE_SOURCE)
                ->select(['host', 'reachable', 'checked_at'], self::TABLE_SOURCE)
                ->fetchAllAssociative();
        } catch (\Throwable $e) {
            // The footer must never take the page down — before the schema
            // migration has run, this table does not exist yet.
            $this->logger->warning('TypoVigil: cannot read source status', [
                'exception' => $e->getMessage(),
            ]);

            return [];
        }

        $stored = [];
        foreach ($rows as $row) {
            $stored[(string)$row['host']] = [
                'reachable' => (int)$row['reachable'],
                'checked_at' => (int)$row['checked_at'],
            ];
        }

        return $stored;
    }

    /**
     * Notes whether a source answered. Written on every attempt rather than only
     * on failure, so a source that recovers stops being shown as broken.
     */
    private function recordSourceStatus(string $url, bool $ok): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || !isset(self::SOURCES[$host])) {
            return;
        }

        $values = ['reachable' => $ok ? 1 : 0, 'checked_at' => time()];

        try {
            $connection = $this->connectionPool->getConnectionForTable(self::TABLE_SOURCE);
            // update-then-insert rather than a database-specific upsert: one row
            // per host, and a failed check must never abort the whole run.
            $updated = $connection->update(self::TABLE_SOURCE, $values, ['host' => $host]);
            if ($updated === 0) {
                $connection->insert(self::TABLE_SOURCE, $values + ['host' => $host]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('TypoVigil: cannot record source status', [
                'host' => $host,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<mixed>
     */
    private function fetchJson(string $url): array
    {
        try {
            $response = $this->requestFactory->request($url, 'GET', [
                'timeout' => self::TIMEOUT,
                'headers' => ['User-Agent' => 'TypoVigil'],
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger->warning('TypoVigil: unexpected status from upstream', [
                    'url' => $url,
                    'status' => $response->getStatusCode(),
                ]);
                $this->recordSourceStatus($url, false);

                return [];
            }

            $decoded = json_decode((string)$response->getBody(), true, 64, JSON_THROW_ON_ERROR);
            $this->recordSourceStatus($url, true);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            // A failing upstream must never break the scheduler run; the affected
            // packages simply keep their previous state.
            $this->logger->warning('TypoVigil: upstream request failed', [
                'url' => $url,
                'exception' => $e->getMessage(),
            ]);
            $this->recordSourceStatus($url, false);

            return [];
        }
    }
}
