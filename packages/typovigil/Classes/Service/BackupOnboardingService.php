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
            'storage' => $this->onboardStorage($projectUid, $applicationUuid, (string)($project['coolify_storage_uuid'] ?? ''), $frequency),
            'database' => $this->onboardDatabase($projectUid, $databaseUuid, (string)($project['coolify_db_scheduled_backup_uuid'] ?? ''), $frequency),
        ];
    }

    /**
     * @return 'created'|'skipped'|'failed'|'not-configured'
     */
    private function onboardStorage(int $projectUid, string $applicationUuid, string $storageUuid, string $frequency): string
    {
        if ($storageUuid === '') {
            // Nothing to onboard: the volume UUID itself has to be entered
            // by hand first, TypoVigil cannot guess which volume matters.
            return 'not-configured';
        }

        // A field already holding a value other than the raw volume UUID
        // means a schedule was created before — the hosting platform does
        // not deduplicate schedules for us, so re-running this must not
        // create a second one. The raw volume UUID looks like
        // "{app_uuid}-something"; a schedule UUID the platform hands back
        // does not share that shape.
        if (!str_starts_with($storageUuid, $applicationUuid)) {
            return 'skipped';
        }

        $scheduleUuid = $this->coolify->createStorageBackupSchedule($applicationUuid, $storageUuid, $frequency);
        if ($scheduleUuid === null) {
            return 'failed';
        }

        $this->projects->updateProject($projectUid, ['coolify_storage_uuid' => $scheduleUuid]);

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
