<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Task;

use Maidemde\Typovigil\Domain\Repository\ProjectRepository;
use Maidemde\Typovigil\Domain\Severity;
use Maidemde\Typovigil\Service\SeverityResolver;
use Maidemde\Typovigil\Service\VersionCheckService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

/**
 * Matches every reported package against the upstream sources.
 *
 * Runs on the hub rather than in each agent: otherwise every monitored instance
 * would ask Packagist the same questions independently.
 */
final class UpdateCheckTask extends AbstractTask
{
    public function execute(): bool
    {
        $repository = GeneralUtility::makeInstance(ProjectRepository::class);
        $versions = GeneralUtility::makeInstance(VersionCheckService::class);
        $resolver = GeneralUtility::makeInstance(SeverityResolver::class);

        $packages = $repository->findAllPackages();
        if ($packages === []) {
            return true;
        }

        $composerNames = [];
        foreach ($packages as $package) {
            if (!(int)($package['is_core'] ?? 0) && ($package['composer_name'] ?? '') !== '') {
                $composerNames[] = (string)$package['composer_name'];
            }
        }

        $advisories = $versions->advisories($composerNames);
        $now = time();

        foreach ($packages as $package) {
            $installed = (string)($package['installed_version'] ?? '');
            if ($installed === '') {
                continue;
            }

            if ((int)($package['is_core'] ?? 0) === 1) {
                $releases = $versions->coreReleases($installed);
                $severity = $resolver->resolveCore($installed, $releases);
                $latest = $this->newestOf($releases, $installed);
                $packageAdvisories = [];
            } else {
                $composerName = (string)($package['composer_name'] ?? '');
                if ($composerName === '') {
                    // TER-only extension: resolve the Composer name once, then treat it normally.
                    $composerName = $versions->composerNameForExtensionKey((string)($package['extension_key'] ?? ''));
                    if ($composerName === '') {
                        continue;
                    }
                }

                $latest = $versions->latestVersion($composerName);
                $packageAdvisories = $advisories[$composerName] ?? [];
                $severity = $resolver->resolve($installed, $latest, $packageAdvisories);
            }

            $repository->updatePackage((int)$package['uid'], [
                'latest_version' => $latest,
                'severity' => $severity->value,
                'advisory_json' => $packageAdvisories === [] ? '' : json_encode($packageAdvisories, JSON_THROW_ON_ERROR),
                'checked_at' => $now,
            ]);
        }

        return true;
    }

    /**
     * @param list<array{version: string, type: string}> $releases
     */
    private function newestOf(array $releases, string $fallback): string
    {
        $newest = $fallback;
        foreach ($releases as $release) {
            $version = ltrim($release['version'], 'vV');
            if (version_compare($version, $newest, '>')) {
                $newest = $version;
            }
        }

        return $newest;
    }

    public function getAdditionalInformation(): string
    {
        $repository = GeneralUtility::makeInstance(ProjectRepository::class);

        return sprintf('%d packages tracked', count($repository->findAllPackages()));
    }
}
