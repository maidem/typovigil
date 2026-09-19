<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Command;

use Maidemde\Typovigil\Service\AutoBackupOnCriticalService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the update check and immediately backs up any Coolify-linked project
 * that came out of it with a critical package.
 *
 * Meant to replace typovigil:check on the hourly cron — that command still
 * exists on its own for anyone who wants the check without the side
 * effect (e.g. a manual run while investigating something).
 */
final class CheckAndAutoBackupCommand extends Command
{
    public function __construct(private readonly AutoBackupOnCriticalService $autoBackup)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->autoBackup->run();

        $output->writeln(sprintf('%d packages checked', $result['checked']));
        $output->writeln(sprintf(
            '%d project(s) with a critical package, %d backed up successfully',
            $result['criticalProjects'],
            $result['securedProjects']
        ));

        return Command::SUCCESS;
    }
}
