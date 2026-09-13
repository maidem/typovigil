<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Command;

use Maidemde\Typovigil\Service\UpdateChecker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Matches every reported package against the upstream sources.
 *
 * A console command rather than only a scheduler task: cron can call this
 * directly, so nothing has to be clicked together in the backend and a fresh
 * deployment starts checking on its own. The scheduler task remains for
 * installations that prefer to drive it from there — both call the same service.
 */
final class CheckUpdatesCommand extends Command
{
    public function __construct(private readonly UpdateChecker $checker)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $checked = $this->checker->run();

        $output->writeln(sprintf('%d packages checked', $checked));

        return Command::SUCCESS;
    }
}
