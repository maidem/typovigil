<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Service;

use Maidemde\Typovigil\Domain\Repository\ProjectRepository;

/**
 * Matches every reported package against the upstream sources.
 *
 * Lives in a service rather than in the scheduler task so the console command
 * and the task share one implementation — two copies of this logic would drift,
 * and then the two ways of running a check would disagree.
 *
 * Runs on the hub only: if every agent queried these APIs itself, the same
 * questions would be asked once per monitored instance.
 */
final readonly class UpdateChecker
{
    public function __construct(
        private ProjectRepository $projects,
        private VersionCheckService $versions,
        private SeverityResolver $resolver,
    ) {}

    /**
     * @param int|null $projectUid check only this project instead of all of them
     * @return int number of packages examined
     */
    public function run(?int $projectUid = null): int
    {
        $packages = $projectUid === null
            ? $this->projects->findAllPackages()
            : $this->projects->findPackagesByProject($projectUid);
        if ($packages === []) {
            return 0;
        }

        $composerNames = [];
        foreach ($packages as $package) {
            if (!(int)($package['is_core'] ?? 0) && ($package['composer_name'] ?? '') !== '') {
                $composerNames[] = (string)$package['composer_name'];
            }
        }

        $advisories = $this->versions->advisories($composerNames);
        $now = time();
        $checked = 0;

        foreach ($packages as $package) {
            $installed = (string)($package['installed_version'] ?? '');
            if ($installed === '') {
                continue;
            }

            if ((int)($package['is_core'] ?? 0) === 1) {
                $releases = $this->versions->coreReleases($installed);
                $severity = $this->resolver->resolveCore($installed, $releases);
                $latest = $this->newestOf($releases, $installed);
                $packageAdvisories = [];
            } else {
                $composerName = (string)($package['composer_name'] ?? '');
                if ($composerName === '') {
                    // TER-only extension: resolve the Composer name once, then treat it normally.
                    $composerName = $this->versions->composerNameForExtensionKey((string)($package['extension_key'] ?? ''));
                    if ($composerName === '') {
                        continue;
                    }
                }

                $latest = $this->versions->latestVersion($composerName);
                $packageAdvisories = $advisories[$composerName] ?? [];
                $severity = $this->resolver->resolve($installed, $latest, $packageAdvisories);
            }

            $this->projects->updatePackage(
                (int)($package['project'] ?? 0),
                (string)($package['composer_name'] ?? ''),
                (string)($package['extension_key'] ?? ''),
                [
                    'latest_version' => $latest,
                    'severity' => $severity->value,
                    'advisory_json' => $packageAdvisories === [] ? '' : json_encode($packageAdvisories, JSON_THROW_ON_ERROR),
                    'checked_at' => $now,
                ]
            );
            $checked++;
        }

        return $checked;
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
}
