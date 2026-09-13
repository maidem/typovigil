<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Task;

use Maidemde\Typovigil\Domain\Repository\ProjectRepository;
use Maidemde\Typovigil\Service\UpdateChecker;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

/**
 * Scheduler wrapper around UpdateChecker.
 *
 * The work itself lives in the service so the console command (typovigil:check)
 * and this task cannot drift apart.
 */
final class UpdateCheckTask extends AbstractTask
{
    public function execute(): bool
    {
        // Scheduler tasks are unserialized, not built by the container, so nothing
        // injects constructor arguments — they have to be resolved by hand.
        GeneralUtility::getContainer()->get(UpdateChecker::class)->run();

        return true;
    }

    public function getAdditionalInformation(): string
    {
        $repository = GeneralUtility::getContainer()->get(ProjectRepository::class);

        return sprintf('%d packages tracked', count($repository->findAllPackages()));
    }
}
