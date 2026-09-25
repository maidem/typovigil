<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Queue\Handler;

use Maidemde\Typovigil\Queue\Message\BackupMessage;
use Maidemde\Typovigil\Service\BackupBeforeUpdateService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Picked up by `messenger:consume backup`, running in the background worker
 * loop next to Apache — see docker-entrypoint.sh. Delegates straight to the
 * same service the button used to call inline; only who calls it and when
 * changed, not what a backup does.
 */
#[AsMessageHandler]
final readonly class BackupHandler
{
    public function __construct(private BackupBeforeUpdateService $backup) {}

    public function __invoke(BackupMessage $message): void
    {
        $this->backup->run($message->projectUid);
    }
}
