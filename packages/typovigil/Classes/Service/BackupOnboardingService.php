<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Service;

use Maidemde\Typovigil\Domain\Repository\ProjectRepository;

/**
 * Turns a project's raw hosting platform UUIDs (application, volume,
 * database — all entered by hand, see the project's platform tab) into
 * working backup schedules, so BackupBeforeUpdateService has something to
 * trigger and check.
 *
 * Run once per project, via `typovigil:onboard-coolify`, not automatically:
 * onboarding changes infrastructure (creates schedules on the platform),
 * which should be an explicit action, not a side effect of saving a record.
 */
final readonly class BackupOnboardingService
{
    private const DEFAULT_FREQUENCY = 'daily';

    public function __construct(
        private ProjectRepository $projects,
        private CoolifyClient $coolify,
    ) {}

    /**
     * @return array{
     *     linked: bool,
     *     storage: 'created'|'skipped'|'failed'|'not-configured',
     *     database: 'created'|'skipped'|'failed'|'not-configured',
     * }
     */
    public function run(int $projectUid, string $frequency = self::DEFAULT_FREQUENCY): array
    {
        $project = $this->projects->findByUid($projectUid);
        $applicationUuid = trim((string)($project['coolify_application_uuid'] ?? ''));
        $databaseUuid = trim((string)($project['coolify_database_uuid'] ?? ''));

        if ($project === null || $applicationUuid === '' || $databaseUuid === '') {
            return ['linked' => false, 'storage' => 'not-configured', 'database' => 'not-configured'];
        }

        return [
            'linked' => true,
            'storage' => $this->onboardStorage(
                $projectUid,
                $applicationUuid,
                (string)($project['coolify_storage_uuid'] ?? ''),
                (string)($project['coolify_storage_backup_uuid'] ?? ''),
                $frequency
            ),
            'database' => $this->onboardDatabase($projectUid, $databaseUuid, (string)($project['coolify_db_scheduled_backup_uuid'] ?? ''), $frequency),
        ];
    }

    /**
     * @return 'created'|'skipped'|'failed'|'not-configured'
     */
    private function onboardStorage(
        int $projectUid,
        string $applicationUuid,
        string $storageUuid,
        string $scheduleUuid,
        string $frequency,
    ): string {
        if ($storageUuid === '') {
            // Nothing to onboard: the volume has to be named by hand first,
            // TypoVigil cannot guess which one matters (an application
            // usually has several, and only some are worth backing up).
            return 'not-configured';
        }

        if ($scheduleUuid !== '') {
            // Already onboarded. The platform does not deduplicate
            // schedules, so re-running must not create a second one.
            return 'skipped';
        }

        $created = $this->coolify->createStorageBackupSchedule($applicationUuid, $storageUuid, $frequency);
        if ($created === null) {
            return 'failed';
        }

        // Into its own field, leaving the volume where it is. This used to
        // overwrite the volume with the schedule and tell the two apart by
        // their shape ("does it start with the application uuid?") — which
        // broke as soon as a real volume uuid went in, because the
        // platform's own uuids do not follow that shape either.
        $this->projects->updateProject($projectUid, ['coolify_storage_backup_uuid' => $created]);

        return 'created';
    }

    /**
     * @return 'created'|'skipped'|'failed'|'not-configured'
     */
    private function onboardDatabase(int $projectUid, string $databaseUuid, string $scheduledBackupUuid, string $frequency): string
    {
        if ($scheduledBackupUuid !== '') {
            return 'skipped';
        }

        $scheduleUuid = $this->coolify->createDatabaseBackupSchedule($databaseUuid, $frequency);
        if ($scheduleUuid === null) {
            return 'failed';
        }

        $this->projects->updateProject($projectUid, ['coolify_db_scheduled_backup_uuid' => $scheduleUuid]);

        return 'created';
    }
}
