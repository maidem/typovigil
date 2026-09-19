<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Command;

use Maidemde\Typovigil\Service\BackupOnboardingService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Turns a project's raw hosting platform UUIDs into working backup
 * schedules. Run once after entering coolify_application_uuid,
 * coolify_storage_uuid (the volume) and coolify_database_uuid in the
 * project's platform tab.
 */
final class OnboardCoolifyCommand extends Command
{
    public function __construct(private readonly BackupOnboardingService $onboarding)
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
        $result = $this->onboarding->run($projectUid);

        if (!$result['linked']) {
            $output->writeln('<comment>Project has no hosting platform application/database UUID set — nothing to onboard.</comment>');

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Storage backup schedule: %s', $result['storage']));
        $output->writeln(sprintf('Database backup schedule: %s', $result['database']));

        $failed = $result['storage'] === 'failed' || $result['database'] === 'failed';

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
