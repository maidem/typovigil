<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Service;

use Maidemde\Typovigil\Domain\Repository\ProjectRepository;

/**
 * Takes a project's backup — the one the update button waits for.
 *
 * Both halves are actually taken here, nothing is merely checked:
 *   - the database is dumped by TypoVigil itself (DatabaseBackupService),
 *     because the hosting platform's API can only schedule database backups,
 *     never run one now
 *   - the storage volume is triggered on the platform, which does offer that
 *
 * This used to check whether the platform's scheduled database backup was
 * recent enough instead of taking one. That made the button's promise
 * conditional on a cron somewhere else having run, which is not what "press
 * the button, then you may update" means.
 *
 * A project needs the database credentials for this. Without them the backup
 * fails, and with it the update — deliberately: an update with no way back is
 * exactly what this is meant to prevent.
 */
final readonly class BackupBeforeUpdateService
{
    public function __construct(
        private ProjectRepository $projects,
        private CoolifyClient $coolify,
        private DatabaseBackupService $databaseBackup,
    ) {}

    /**
     * @return array{
     *     linked: bool,
     *     storageQueued: bool,
     *     databaseDumped: bool,
     *     secured: bool,
     *     message: string,
     * }
     */
    public function run(int $projectUid): array
    {
        $project = $this->projects->findByUid($projectUid);
        if ($project === null) {
            return self::failure('Project not found.');
        }

        $applicationUuid = trim((string)($project['coolify_application_uuid'] ?? ''));
        $storageUuid = trim((string)($project['coolify_storage_uuid'] ?? ''));

        // The database is what a TYPO3 update can actually break, so a
        // project that cannot be dumped cannot be backed up at all.
        if (!DatabaseBackupService::isConfigured($project)) {
            $this->projects->updateProject($projectUid, ['backup_progress' => '']);

            return self::failure('No database credentials on this project — fill them in on its Database tab.');
        }

        $this->projects->updateProject($projectUid, ['backup_progress' => 'dumping']);
        $dump = $this->databaseBackup->run($project);

        // The volume is optional: a project may have nothing worth keeping in
        // fileadmin, and a composer update does not touch it anyway. Only a
        // configured volume that then fails counts against the backup.
        $storageQueued = true;
        if ($applicationUuid !== '' && $storageUuid !== '') {
            $this->projects->updateProject($projectUid, ['backup_progress' => 'triggering_storage']);
            $storageQueued = $this->coolify->triggerStorageBackup($applicationUuid, $storageUuid);
        }

        $secured = $dump['success'] && $storageQueued;
        $status = self::statusString($dump['success'], $storageQueued);

        $values = [
            'last_backup_at' => time(),
            'last_backup_status' => $status,
            'backup_progress' => $secured ? 'done' : 'failed',
        ];

        // Only a successful backup records the state it covers — that
        // fingerprint is what unlocks the update button, so a half-failed run
        // must not leave one behind. See RequestUpdateService.
        if ($secured) {
            $values['last_backup_state'] = RequestUpdateService::packageState(
                $this->projects->findPackagesByProject($projectUid)
            );
        }

        $this->projects->updateProject($projectUid, $values);

        return [
            'linked' => true,
            'storageQueued' => $storageQueued,
            'databaseDumped' => $dump['success'],
            'secured' => $secured,
            'message' => $secured
                ? $dump['message'] . ' Storage backup queued. Ready to update.'
                : 'Not backed up: ' . $status . '. ' . $dump['message'],
        ];
    }

    /**
     * @return array{linked: bool, storageQueued: bool, databaseDumped: bool, secured: bool, message: string}
     */
    private static function failure(string $message): array
    {
        return [
            'linked' => false,
            'storageQueued' => false,
            'databaseDumped' => false,
            'secured' => false,
            'message' => $message,
        ];
    }

    private static function statusString(bool $databaseDumped, bool $storageQueued): string
    {
        return sprintf(
            'database:%s,storage:%s',
            $databaseDumped ? 'dumped' : 'failed',
            $storageQueued ? 'queued' : 'failed'
        );
    }
}
