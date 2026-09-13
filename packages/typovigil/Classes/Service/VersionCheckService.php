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
        $name = (string)($data[0]['meta']['composer_name'] ?? '');

        $this->cache->set($cacheKey, $name, [], self::CACHE_LIFETIME);

        return $name;
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

                return [];
            }

            $decoded = json_decode((string)$response->getBody(), true, 64, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            // A failing upstream must never break the scheduler run; the affected
            // packages simply keep their previous state.
            $this->logger->warning('TypoVigil: upstream request failed', [
                'url' => $url,
                'exception' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
