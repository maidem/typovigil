<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Command;

use Maidemde\Typovigil\Service\BackupBeforeUpdateService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Takes a project's backup — the same thing the backup button does, for
 * anyone who would rather do it from a shell.
 */
final class BackupBeforeUpdateCommand extends Command
{
    public function __construct(private readonly BackupBeforeUpdateService $backup)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('project', InputArgument::REQUIRED, 'UID of the tx_typovigil_project record');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectUid = (int)$input->getArgument('project');
        $result = $this->backup->run($projectUid);

        if (!$result['linked']) {
            $output->writeln('<comment>' . $result['message'] . '</comment>');

            return Command::SUCCESS;
        }

        $output->writeln($result['secured'] ? '<info>' . $result['message'] . '</info>' : '<error>' . $result['message'] . '</error>');

        return $result['secured'] ? Command::SUCCESS : Command::FAILURE;
    }
}
