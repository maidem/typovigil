<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Service;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
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
     * Reachability of each source, kept far longer than the answers themselves:
     * the footer must still be able to say "no contact" long after a failed
     * answer would have expired from the cache.
     */
    private const SOURCE_STATUS_KEY = 'source_status';
    private const SOURCE_STATUS_LIFETIME = 604800; // 7 days

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
        $stored = $this->cache->get(self::SOURCE_STATUS_KEY);
        $stored = is_array($stored) ? $stored : [];

        $status = [];
        foreach (self::SOURCES as $host => $label) {
            $entry = $stored[$host] ?? null;
            $checkedAt = (int)($entry['at'] ?? 0);
            $status[] = [
                'label' => $label,
                'state' => match (true) {
                    $entry === null => 'unknown',
                    (bool)($entry['ok'] ?? false) => 'ok',
                    default => 'down',
                },
                'checkedAt' => $checkedAt,
                'checkedAtLabel' => $checkedAt > 0 ? date('d.m.Y H:i', $checkedAt) : '',
            ];
        }

        return $status;
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

        $stored = $this->cache->get(self::SOURCE_STATUS_KEY);
        $stored = is_array($stored) ? $stored : [];
        $stored[$host] = ['ok' => $ok, 'at' => time()];

        $this->cache->set(self::SOURCE_STATUS_KEY, $stored, [], self::SOURCE_STATUS_LIFETIME);
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
