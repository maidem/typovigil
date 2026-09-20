<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Service;

use Maidemde\Typovigil\Domain\Repository\ProjectRepository;

/**
 * Runs an AI risk analysis for every package currently at Severity::Critical.
 *
 * Deliberately separate from AutoBackupOnCriticalService, same reasoning as
 * storage vs. database backup in BackupBeforeUpdateService: two independent
 * actions that both run "on a critical finding" but fail independently and
 * have different prerequisites (backup needs a hosting platform link, the
 * AI analysis only needs Eden AI configured). One service per action, one
 * command orchestrates both — see CheckAndAutoBackupCommand.
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
