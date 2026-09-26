<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Command;

use Maidemde\Typovigil\Service\CliCommandService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Pre-fills CliCommandService's cache for every supported major, so the first
 * visitor to the CLI Commands page does not trigger the GitHub tree fetch plus
 * one jsDelivr request per command file synchronously in their own request.
 */
final class WarmupCliCommandsCacheCommand extends Command
{
    private const MAJORS = ['12', '13', '14'];

    public function __construct(private readonly CliCommandService $service)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach (self::MAJORS as $major) {
            $commands = $this->service->commandsForMajor($major);
            $output->writeln(sprintf('TYPO3 %s: %d commands cached', $major, count($commands)));
        }

        return Command::SUCCESS;
    }
}
