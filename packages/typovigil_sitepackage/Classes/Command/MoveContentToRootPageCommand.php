<?php

declare(strict_types=1);

namespace Maidemde\TypovigilSitepackage\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * One-off: the portal plugin lived on the "Status" subpage (/status) rather
 * than the root page, so the domain's own front door showed nothing. Moves
 * the plugin content element there instead.
 */
final class MoveContentToRootPageCommand extends Command
{
    private const ROOT_PID = 1;
    private const PLUGIN_CTYPE = 'typovigilsitepackage_portal';

    public function __construct(private readonly ConnectionPool $connectionPool)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Moves the portal plugin content element to the root page');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $content = $this->connectionPool->getConnectionForTable('tt_content');

        $element = $content->select(['uid', 'pid'], 'tt_content', ['CType' => self::PLUGIN_CTYPE])
            ->fetchAssociative();
        if ($element === false) {
            $output->writeln('No portal plugin content element found.');

            return Command::FAILURE;
        }

        if ((int)$element['pid'] === self::ROOT_PID) {
            $output->writeln('Already on the root page — nothing to do.');

            return Command::SUCCESS;
        }

        $content->update('tt_content', ['pid' => self::ROOT_PID], ['uid' => $element['uid']]);
        $output->writeln(sprintf(
            'Moved content element uid %d from page %d to the root page.',
            $element['uid'],
            $element['pid']
        ));

        return Command::SUCCESS;
    }
}
