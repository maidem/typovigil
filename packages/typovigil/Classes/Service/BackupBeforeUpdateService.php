<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Service;

use Maidemde\Typovigil\Domain\Repository\ProjectRepository;

/**
 * Backs a project up on its hosting platform before an update is applied
 * there.
 *
 * Opt-in per project: only projects with coolify_application_uuid and
 * coolify_database_uuid filled in are touched. A project without those
 * stays plain monitoring — this service does not migrate or require them.
 *
 * Two different guarantees, because the platform's API offers two different
 * things: the storage (volume) backup can be triggered right now, the
 * database backup can only be checked for freshness against its own
 * schedule. See CoolifyClient for why.
 */
final readonly class BackupBeforeUpdateService
{
    /**
     * How old the newest database backup execution may be before it no
     * longer counts as "backed up before this update". Matches a daily
     * schedule with headroom; override per call if a project's schedule
     * runs less often.
     */
    private const DEFAULT_MAX_DATABASE_BACKUP_AGE = 2 * 3600;

    public function __construct(
        private ProjectRepository $projects,
        private CoolifyClient $coolify,
    ) {}

    /**
     * @return array{
     *     linked: bool,
     *     storageQueued: bool,
     *     databaseFresh: bool,
     *     secured: bool,
     *     message: string,
     * }
     */
    public function run(int $projectUid, int $maxDatabaseBackupAge = self::DEFAULT_MAX_DATABASE_BACKUP_AGE): array
    {
        $project = $this->projects->findByUid($projectUid);
        $applicationUuid = trim((string)($project['coolify_application_uuid'] ?? ''));
        $databaseUuid = trim((string)($project['coolify_database_uuid'] ?? ''));

        if ($project === null || $applicationUuid === '' || $databaseUuid === '') {
            return [
                'linked' => false,
                'storageQueued' => false,
                'databaseFresh' => false,
                'secured' => false,
                'message' => 'Project is not linked to a hosting platform.',
            ];
        }

        $storageUuid = trim((string)($project['coolify_storage_uuid'] ?? ''));
        $storageQueued = $storageUuid !== '' && $this->coolify->triggerStorageBackup($applicationUuid, $storageUuid);

        $scheduledBackupUuid = trim((string)($project['coolify_db_scheduled_backup_uuid'] ?? ''));
        $databaseFresh = $scheduledBackupUuid !== '' && $this->isDatabaseBackupFresh($databaseUuid, $scheduledBackupUuid, $maxDatabaseBackupAge);

        $status = self::statusString($storageQueued, $databaseFresh);
        $secured = $storageQueued && $databaseFresh;

        $values = [
            'last_backup_at' => time(),
            'last_backup_status' => $status,
        ];

        // Only a successful backup records the state it covers — that
        // fingerprint is what unlocks the update button, so a half-failed
        // run must not leave one behind. See
        // RequestUpdateService::hasCurrentBackup().
        if ($secured) {
            $values['last_backup_state'] = RequestUpdateService::packageState(
                $this->projects->findPackagesByProject($projectUid)
            );
        }

        $this->projects->updateProject($projectUid, $values);

        return [
            'linked' => true,
            'storageQueued' => $storageQueued,
            'databaseFresh' => $databaseFresh,
            'secured' => $secured,
            'message' => $secured
                ? 'Storage backup queued and database backup is fresh — safe to update.'
                : 'Not fully secured: ' . $status . '. Check the hosting platform before updating.',
        ];
    }

    private function isDatabaseBackupFresh(string $databaseUuid, string $scheduledBackupUuid, int $maxAge): bool
    {
        $execution = $this->coolify->latestDatabaseBackupExecution($databaseUuid, $scheduledBackupUuid);

        return $execution !== null && self::executionIsFresh($execution, $maxAge, time());
    }

    /**
     * Pure decision, pulled out of isDatabaseBackupFresh so the self-check
     * (Tests/BackupBeforeUpdateServiceTest.php) can exercise it without a
     * database connection or an HTTP client — this is the only branch in
     * the class worth testing in isolation.
     *
     * @param array{status: string, created_at: string, filename: string} $execution
     */
    private static function statusString(bool $storageQueued, bool $databaseFresh): string
    {
        return sprintf(
            'storage:%s,database:%s',
            $storageQueued ? 'queued' : 'failed',
            $databaseFresh ? 'fresh' : 'stale'
        );
    }

    public static function executionIsFresh(array $execution, int $maxAge, int $now): bool
    {
        // The hosting platform's own backup status wording; anything else
        // (failed, running from a stale run, …) does not count as a safe backup.
        if (!in_array($execution['status'], ['success', 'ok'], true)) {
            return false;
        }

        $createdAt = strtotime($execution['created_at']);
        if ($createdAt === false) {
            return false;
        }

        return ($now - $createdAt) <= $maxAge;
    }
}
