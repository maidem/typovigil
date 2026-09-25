<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Queue\Message;

/**
 * Asks the "backup" transport to back one project up.
 *
 * Just the project uid: everything else the handler needs is looked up
 * fresh when the message is picked up, not carried along stale on the
 * message itself.
 */
final readonly class BackupMessage
{
    public function __construct(public int $projectUid) {}
}
