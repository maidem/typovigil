<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Task;

use Maidemde\Typovigil\Service\CliCommandService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

/**
 * Scheduler wrapper around CliCommandService's cache warmup.
 *
 * The work itself lives in the command (typovigil:warmup-cli-commands) so
 * cron and this task cannot drift apart.
 */
final class WarmupCliCommandsCacheTask extends AbstractTask
{
    private const MAJORS = ['12', '13', '14'];

    public function execute(): bool
    {
        // Scheduler tasks are unserialized, not built by the container, so nothing
        // injects constructor arguments — it has to be resolved by hand.
        $service = GeneralUtility::getContainer()->get(CliCommandService::class);
        foreach (self::MAJORS as $major) {
            $service->commandsForMajor($major);
        }

        return true;
    }
}
