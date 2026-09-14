<?php

declare(strict_types=1);

namespace Maidemde\TypovigilSitepackage\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * One-off: the /status page was restricted to logged-in visitors only
 * (fe_group -2), left over from before the page showed anything to a visitor
 * without an account. PortalController now decides per-visitor what to show,
 * so the page-level restriction only blocks that logic from ever running.
 */
final class OpenStatusPageCommand extends Command
{
    private const STATUS_PAGE_SLUG = '/status';

    public function __construct(private readonly ConnectionPool $connectionPool)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Removes the login-only restriction from the /status page');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pages = $this->connectionPool->getConnectionForTable('pages');

        $page = $pages->select(['uid', 'fe_group'], 'pages', ['slug' => self::STATUS_PAGE_SLUG])->fetchAssociative();
        if ($page === false) {
            $output->writeln(sprintf('No page with slug "%s" found.', self::STATUS_PAGE_SLUG));

            return Command::FAILURE;
        }

        if ((string)$page['fe_group'] === '0' || $page['fe_group'] === null || $page['fe_group'] === '') {
            $output->writeln('Page is already open — nothing to do.');

            return Command::SUCCESS;
        }

        $pages->update('pages', ['fe_group' => '0'], ['uid' => $page['uid']]);
        $output->writeln(sprintf('Removed restriction (was "%s") from page uid %d.', $page['fe_group'], $page['uid']));

        return Command::SUCCESS;
    }
}
