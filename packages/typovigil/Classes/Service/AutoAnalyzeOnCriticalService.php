<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Service;

use Maidemde\Typovigil\Domain\Repository\ProjectRepository;

/**
 * Runs an AI risk analysis for every package currently at Severity::Critical.
 *
 * The only thing that still happens automatically on a critical finding.
 * Backups used to as well; they are now taken deliberately with the backup
 * button, because a successful backup is what unlocks the update and that
 * decision should be someone's, not a cron job's. Generating a report costs
 * an AI call and blocks nothing, so it stays automatic.
 *
 * Iterates packages, not projects: a project can have several critical
 * packages, and the report is per package, not per project.
 */
final readonly class AutoAnalyzeOnCriticalService
{
    public function __construct(
        private ProjectRepository $projects,
        private AnalyzeCriticalPackageService $analyze,
    ) {}

    /**
     * @return array{criticalPackages: int, analyzed: int}
     */
    public function run(): array
    {
        $criticalPackages = $this->projects->findCriticalPackages();
        $analyzed = 0;

        foreach ($criticalPackages as $package) {
            $result = $this->analyze->run($package);
            if ($result['analyzed']) {
                $analyzed++;
            }
        }

        return [
            'criticalPackages' => count($criticalPackages),
            'analyzed' => $analyzed,
        ];
    }
}
