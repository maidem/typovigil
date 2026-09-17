<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Service;

use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Where a new customer record has to be created.
 *
 * Read from the felogin plugin rather than configured separately: felogin only
 * finds users on the pages listed in its own plugin settings, so any second
 * place to configure this can disagree with it — and a customer stored where
 * felogin does not look can be saved but never log in. Deriving it means the
 * two cannot drift apart, and it needs no per-environment configuration: the
 * page ids differ between local and live, a fixed value would be wrong on one
 * of them.
 */
final readonly class CustomerStorageService
{
    private const TABLE_CONTENT = 'tt_content';
    private const CTYPE_FELOGIN = 'felogin_login';

    public function __construct(
        private ConnectionPool $connectionPool,
        private FlexFormTools $flexFormTools,
    ) {}

    /**
     * The page new customers belong on, or 0 when it cannot be determined —
     * no felogin plugin, or one that was never pointed at a storage page.
     */
    public function storagePid(): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE_CONTENT);
        $qb->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $flexForms = $qb->select('pi_flexform')
            ->from(self::TABLE_CONTENT)
            ->where(
                $qb->expr()->eq('CType', $qb->createNamedParameter(self::CTYPE_FELOGIN)),
                $qb->expr()->eq('hidden', $qb->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchFirstColumn();

        foreach ($flexForms as $flexForm) {
            $pid = $this->firstPageFromFlexForm((string)$flexForm);
            if ($pid > 0) {
                return $pid;
            }
        }

        return 0;
    }

    /**
     * felogin accepts a comma separated list; the first entry is where a new
     * record goes, since one record can only live on one page.
     */
    private function firstPageFromFlexForm(string $flexForm): int
    {
        if ($flexForm === '') {
            return 0;
        }

        $settings = $this->flexFormTools->convertFlexFormContentToArray($flexForm);
        $pages = (string)($settings['settings']['pages'] ?? '');

        foreach (explode(',', $pages) as $page) {
            $pid = (int)trim($page);
            if ($pid > 0) {
                return $pid;
            }
        }

        return 0;
    }
}
