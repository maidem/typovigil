<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Command;

use Maidemde\Typovigil\Service\AutoAnalyzeOnCriticalService;
use Maidemde\Typovigil\Service\UpdateChecker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the update check and generates an AI risk report for each critical
 * package. This is what belongs on the hourly cron.
 *
 * It used to back up critical projects automatically as well. That is gone
 * on purpose: a backup is now something the operator takes deliberately,
 * with the button, and its success is what unlocks the update. An automatic
 * backup running in the background would make it impossible to tell whether
 * the backup standing behind an update was a considered one.
 *
 * typovigil:check still exists for the plain check without the AI calls.
 */
final class CheckAndAnalyzeCommand extends Command
{
    public function __construct(
        private readonly UpdateChecker $updateChecker,
        private readonly AutoAnalyzeOnCriticalService $autoAnalyze,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $checked = $this->updateChecker->run();
        $output->writeln(sprintf('%d packages checked', $checked));

        $analysis = $this->autoAnalyze->run();
        $output->writeln(sprintf(
            '%d critical package(s), %d AI risk report(s) generated',
            $analysis['criticalPackages'],
            $analysis['analyzed']
        ));

        return Command::SUCCESS;
    }
}
