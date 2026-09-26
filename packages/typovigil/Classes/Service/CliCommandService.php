<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Collects TYPO3 console commands per major version, straight from the core
 * source. Two registration styles exist across the supported majors: 12 and
 * 13 tag commands as `console.command` in each sysext's
 * Configuration/Services.yaml; 14 dropped that in favour of Symfony's native
 * `#[AsCommand]` attribute directly on the command class. Both are read, so
 * a major only ever contributes through whichever style it actually uses.
 *
 * One GitHub Trees API call per major finds which sysexts exist and which
 * command classes they contain (small request, avoids GitHub's 60/hour
 * unauthenticated limit); the individual files are then fetched through
 * jsDelivr's GitHub mirror, which is CDN-cached and not subject to that
 * limit.
 */
final readonly class CliCommandService
{
    private const GITHUB_TREE = 'https://api.github.com/repos/typo3/typo3/git/trees/%s?recursive=1';
    private const JSDELIVR_FILE = 'https://cdn.jsdelivr.net/gh/typo3/typo3@%s/%s';

    private const CACHE_LIFETIME = 86400;
    private const TIMEOUT = 15;

    /**
     * Branch to fetch per major — the latest maintained minor, so removed or
     * renamed commands do not show up as still current.
     */
    private const BRANCH_BY_MAJOR = [
        '12' => '12.4',
        '13' => '13.4',
        '14' => '14.3',
    ];

    public function __construct(
        private FrontendInterface $cache,
        private RequestFactory $requestFactory,
        private LoggerInterface $logger,
    ) {}

    /**
     * All console commands of a TYPO3 major version, sorted by command name.
     *
     * @return list<array{command: string, description: string, extension: string}>
     */
    public function commandsForMajor(string $major): array
    {
        $branch = self::BRANCH_BY_MAJOR[$major] ?? '';
        if ($branch === '') {
            return [];
        }

        $cacheKey = 'cli_commands_' . $major;
        $cached = $this->cache->get($cacheKey);
        if ($cached !== false) {
            return (array)$cached;
        }

        $tree = $this->treeOf($branch);
        if ($tree === []) {
            return [];
        }

        $commands = [];
        foreach ($this->servicesYamlPathsOf($tree) as $extension => $path) {
            foreach ($this->commandsFromServicesYaml($branch, $path, $extension) as $command) {
                $commands[] = $command;
            }
        }
        foreach ($this->commandClassPathsOf($tree) as $extension => $paths) {
            foreach ($paths as $path) {
                $command = $this->commandFromClass($branch, $path, $extension);
                if ($command !== null) {
                    $commands[] = $command;
                }
            }
        }

        usort($commands, static fn(array $a, array $b): int => $a['command'] <=> $b['command']);

        $this->cache->set($cacheKey, $commands, [], self::CACHE_LIFETIME);

        return $commands;
    }

    /**
     * @return list<array{path: string}>
     */
    private function treeOf(string $branch): array
    {
        $data = $this->fetchJson(sprintf(self::GITHUB_TREE, $branch));

        return is_array($data['tree'] ?? null) ? $data['tree'] : [];
    }

    /**
     * Configuration/Services.yaml per sysext, the 12/13 registration style.
     *
     * @param list<array{path?: string}> $tree
     * @return array<string, string> extension => repo path
     */
    private function servicesYamlPathsOf(array $tree): array
    {
        $paths = [];
        foreach ($tree as $entry) {
            $path = (string)($entry['path'] ?? '');
            $parts = explode('/', $path);
            if (
                count($parts) === 5
                && $parts[0] === 'typo3'
                && $parts[1] === 'sysext'
                && $parts[3] === 'Configuration'
                && $parts[4] === 'Services.yaml'
            ) {
                $paths[$parts[2]] = $path;
            }
        }

        return $paths;
    }

    /**
     * Classes/Command/*.php per sysext, the 14+ registration style.
     *
     * @param list<array{path?: string}> $tree
     * @return array<string, list<string>> extension => repo paths
     */
    private function commandClassPathsOf(array $tree): array
    {
        $paths = [];
        foreach ($tree as $entry) {
            $path = (string)($entry['path'] ?? '');
            $parts = explode('/', $path);
            if (
                count($parts) === 6
                && $parts[0] === 'typo3'
                && $parts[1] === 'sysext'
                && $parts[3] === 'Classes'
                && $parts[4] === 'Command'
                && str_ends_with($parts[5], '.php')
            ) {
                $paths[$parts[2]][] = $path;
            }
        }

        return $paths;
    }

    /**
     * @return list<array{command: string, description: string, extension: string}>
     */
    private function commandsFromServicesYaml(string $branch, string $path, string $extension): array
    {
        $yaml = $this->fetchFile($branch, $path);
        if ($yaml === '') {
            return [];
        }

        try {
            // TYPO3's Services.yaml files use Symfony DI custom tags like
            // !tagged_iterator, which the parser rejects unless explicitly
            // allowed — their actual values are irrelevant here.
            $parsed = Yaml::parse($yaml, Yaml::PARSE_CUSTOM_TAGS);
        } catch (\Throwable $e) {
            $this->logger->warning('TypoVigil: cannot parse Services.yaml', [
                'extension' => $extension,
                'exception' => $e->getMessage(),
            ]);

            return [];
        }

        $commands = [];
        foreach ((array)($parsed['services'] ?? []) as $service) {
            foreach ((array)($service['tags'] ?? []) as $tag) {
                if (!is_array($tag) || ($tag['name'] ?? '') !== 'console.command') {
                    continue;
                }
                $command = (string)($tag['command'] ?? '');
                $description = (string)($tag['description'] ?? '');
                if ($command === '' || $description === '') {
                    // Aliases (e.g. 'swiftmailer:spool:send') repeat the same
                    // command class with no description of their own — the
                    // canonical entry already carries it.
                    continue;
                }
                $commands[] = [
                    'command' => $command,
                    'description' => $description,
                    'extension' => $extension,
                ];
            }
        }

        return $commands;
    }

    /**
     * A command class registers its name and description one of two ways:
     * the modern `#[AsCommand('name', 'description')]` attribute, or the
     * older `parent::__construct('name')` + `$this->setDescription('...')`
     * pair inside configure(). Core 14 still uses both across different
     * commands (e.g. cache:flush predates the attribute), so both are tried.
     *
     * @return array{command: string, description: string, extension: string}|null
     */
    private function commandFromClass(string $branch, string $path, string $extension): ?array
    {
        $source = $this->fetchFile($branch, $path);
        if ($source === '') {
            return null;
        }

        // Covers both call styles core uses: positional
        // #[AsCommand('name', 'description')] and named
        // #[AsCommand(name: 'name', description: 'description')].
        if (preg_match("/#\\[AsCommand\\(\\s*(?:name:\\s*)?['\"]([^'\"]+)['\"]\\s*,\\s*(?:description:\\s*)?['\"]([^'\"]*)['\"]/", $source, $matches)) {
            return [
                'command' => $matches[1],
                'description' => $matches[2],
                'extension' => $extension,
            ];
        }

        if (
            preg_match("/parent::__construct\\(\\s*['\"]([^'\"]+)['\"]/", $source, $nameMatch)
            && preg_match("/setDescription\\(\\s*['\"](.*?)['\"]\\s*\\)/", $source, $descriptionMatch)
        ) {
            return [
                'command' => $nameMatch[1],
                'description' => $descriptionMatch[1],
                'extension' => $extension,
            ];
        }

        return null;
    }

    private function fetchFile(string $branch, string $repoPath): string
    {
        $url = sprintf(self::JSDELIVR_FILE, $branch, $repoPath);

        try {
            $response = $this->requestFactory->request($url, 'GET', [
                'timeout' => self::TIMEOUT,
                'headers' => ['User-Agent' => 'TypoVigil'],
            ]);
            if ($response->getStatusCode() !== 200) {
                return '';
            }

            return (string)$response->getBody();
        } catch (\Throwable $e) {
            $this->logger->warning('TypoVigil: cannot fetch file', [
                'path' => $repoPath,
                'exception' => $e->getMessage(),
            ]);

            return '';
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
                return [];
            }

            $decoded = json_decode((string)$response->getBody(), true, 64, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            $this->logger->warning('TypoVigil: upstream request failed', [
                'url' => $url,
                'exception' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
