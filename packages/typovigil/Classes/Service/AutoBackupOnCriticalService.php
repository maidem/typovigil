<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Service;

use Maidemde\Typovigil\Domain\Repository\ProjectRepository;
use Psr\Log\LoggerInterface;

/**
 * Runs the update check, then immediately backs up every project that came
 * out of it with a critical package — so a critical finding is backed up
 * within the hour, not whenever someone remembers to run typovigil:backup
 * by hand.
 *
 * Deliberately separate from UpdateChecker: that service stays pure
 * observation (see its own docblock), this one adds the side effect on top,
 * so a plain `typovigil:check` still never touches Coolify.
 *
 * Only ever backs projects that are actually linked to Coolify —
 * BackupBeforeUpdateService already refuses silently for the rest, so a
 * critical finding on a plain-monitoring project just does not trigger
 * anything here, same as before this existed.
 */
final readonly class AutoBackupOnCriticalService
{
    public function __construct(
        private UpdateChecker $updateChecker,
        private ProjectRepository $projects,
        private BackupBeforeUpdateService $backup,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array{checked: int, criticalProjects: int, securedProjects: int}
     */
    public function run(): array
    {
        $checked = $this->updateChecker->run();

        $criticalProjectUids = $this->projects->findProjectUidsWithCriticalPackages();
        $secured = 0;

        foreach ($criticalProjectUids as $projectUid) {
            $result = $this->backup->run($projectUid);

            if (!$result['linked']) {
                // Plain monitoring project with a critical package: nothing
                // to back up on Coolify, and nothing wrong either.
                continue;
            }

            if ($result['secured']) {
                $secured++;
            } else {
                $this->logger->warning('TypoVigil: automatic backup for a critical project did not fully succeed', [
                    'project' => $projectUid,
                    'message' => $result['message'],
                ]);
            }
        }

        return [
            'checked' => $checked,
            'criticalProjects' => count($criticalProjectUids),
            'securedProjects' => $secured,
        ];
    }
}
