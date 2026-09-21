<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Dumps a monitored project's database.
 *
 * TypoVigil does this itself rather than asking the hosting platform,
 * because the platform's API can only schedule database backups, not run
 * one now — and "press the button, get a backup" is the whole point. The
 * storage side is different: there the platform does offer an immediate
 * trigger, so BackupBeforeUpdateService still uses it.
 *
 * Credentials come from the project record, the password encrypted via
 * SecretCipher.
 */
final readonly class DatabaseBackupService
{
    /** Big databases take a while; the default 60s would cut them off. */
    private const TIMEOUT = 600;

    private const DEFAULT_DIRECTORY = 'var/backups';

    public function __construct(
        private SecretCipher $cipher,
        private ExtensionConfiguration $extensionConfiguration,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, mixed> $project
     */
    public static function isConfigured(array $project): bool
    {
        return trim((string)($project['db_host'] ?? '')) !== ''
            && trim((string)($project['db_name'] ?? '')) !== ''
            && trim((string)($project['db_user'] ?? '')) !== '';
    }

    /**
     * @param array<string, mixed> $project
     * @return array{success: bool, file: string, message: string}
     */
    public function run(array $project): array
    {
        if (!self::isConfigured($project)) {
            return ['success' => false, 'file' => '', 'message' => 'No database credentials on this project.'];
        }

        $password = $this->cipher->decrypt((string)($project['db_password'] ?? ''));
        if ($password === '') {
            return ['success' => false, 'file' => '', 'message' => 'The stored database password could not be read.'];
        }

        $directory = $this->directory();
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return ['success' => false, 'file' => '', 'message' => 'Backup directory is not writable: ' . $directory];
        }

        $file = sprintf(
            '%s/project-%d-%s.sql.gz',
            rtrim($directory, '/'),
            (int)($project['uid'] ?? 0),
            date('Ymd-His')
        );

        // mariadb-dump piped through gzip, both as arguments rather than a
        // shell string: a password or database name containing a quote would
        // otherwise break the command, and worse, could inject into it.
        // MYSQL_PWD instead of --password: the latter is visible in the
        // process list to every user on the machine.
        $dump = new Process(
            [
                $this->dumpBinary(),
                '--host=' . trim((string)$project['db_host']),
                '--port=' . (int)($project['db_port'] ?: 3306),
                '--user=' . trim((string)$project['db_user']),
                '--single-transaction',
                '--quick',
                // Without this a dump of a database with routines or events
                // silently omits them.
                '--routines',
                '--events',
                trim((string)$project['db_name']),
            ],
            null,
            ['MYSQL_PWD' => $password],
            null,
            self::TIMEOUT
        );

        $gzip = new Process(['gzip', '-c'], null, null, null, self::TIMEOUT);

        try {
            $handle = fopen($file, 'wb');
            if ($handle === false) {
                return ['success' => false, 'file' => '', 'message' => 'Cannot write ' . $file];
            }

            $gzip->setInput($dump->getIterator(Process::ITER_SKIP_ERR));
            $dump->start();
            $gzip->run(static function (string $type, string $buffer) use ($handle): void {
                if ($type === Process::OUT) {
                    fwrite($handle, $buffer);
                }
            });
            fclose($handle);

            if (!$dump->isSuccessful()) {
                @unlink($file);
                $this->logger->warning('TypoVigil: database dump failed', [
                    'project' => $project['uid'] ?? 0,
                    // The dump's stderr names the real cause (access denied,
                    // unknown database, host unreachable) and contains no
                    // password — MYSQL_PWD keeps it out of both argv and here.
                    'stderr' => substr($dump->getErrorOutput(), 0, 500),
                ]);

                return ['success' => false, 'file' => '', 'message' => 'The database dump failed — see the log.'];
            }

            // An empty or near-empty file means the dump produced nothing
            // useful; reporting success on that would be the worst outcome
            // of all, because the update would then be unlocked.
            if (!is_file($file) || filesize($file) < 100) {
                @unlink($file);

                return ['success' => false, 'file' => '', 'message' => 'The dump came out empty.'];
            }

            return [
                'success' => true,
                'file' => $file,
                'message' => sprintf('Database backed up (%s).', self::humanSize((int)filesize($file))),
            ];
        } catch (ProcessTimedOutException) {
            @unlink($file);

            return ['success' => false, 'file' => '', 'message' => 'The database dump timed out.'];
        } catch (\Throwable $e) {
            @unlink($file);
            $this->logger->warning('TypoVigil: database dump failed', [
                'project' => $project['uid'] ?? 0,
                'exception' => $e->getMessage(),
            ]);

            return ['success' => false, 'file' => '', 'message' => 'The database dump failed — see the log.'];
        }
    }

    /**
     * Where dumps are written. Configurable so the directory can later be a
     * mounted volume without touching this code.
     */
    private function directory(): string
    {
        try {
            $configured = trim((string)$this->extensionConfiguration->get('typovigil', 'backupDirectory'));
        } catch (\Throwable) {
            $configured = '';
        }

        $directory = $configured !== '' ? $configured : self::DEFAULT_DIRECTORY;

        return str_starts_with($directory, '/')
            ? $directory
            : rtrim((string)getenv('TYPO3_PATH_APP') ?: getcwd(), '/') . '/' . $directory;
    }

    /**
     * mariadb-dump on current images, mysqldump on older ones — which one
     * exists is not worth a configuration field.
     */
    private function dumpBinary(): string
    {
        foreach (['mariadb-dump', 'mysqldump'] as $binary) {
            $which = new Process(['which', $binary]);
            $which->run();
            if ($which->isSuccessful()) {
                return trim($which->getOutput());
            }
        }

        return 'mysqldump';
    }

    public static function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        return round($bytes / 1024) . ' KB';
    }
}
