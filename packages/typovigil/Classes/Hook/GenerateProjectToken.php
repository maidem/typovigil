<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Hook;

use Maidemde\Typovigil\Domain\Repository\ProjectRepository;
use Maidemde\Typovigil\Service\TokenGenerator;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Issues a bearer token the first time a project is saved.
 *
 * The plain token is shown once in a flash message and never stored — only its
 * hash goes into the database, so a leaked dump cannot be replayed against the
 * report endpoint.
 *
 * A DataHandler hook rather than a PSR-14 listener: no event carries the uid of
 * a newly created record.
 */
final class GenerateProjectToken
{
    /**
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_afterDatabaseOperations(
        string $status,
        string $table,
        string|int $id,
        array $fieldArray,
        DataHandler $dataHandler,
    ): void {
        if ($table !== 'tx_typovigil_project' || $status !== 'new') {
            return;
        }

        $uid = (int)($dataHandler->substNEWwithIDs[$id] ?? 0);
        if ($uid <= 0) {
            return;
        }

        $record = BackendUtility::getRecord('tx_typovigil_project', $uid, 'token_hash,site_url');
        if (($record['token_hash'] ?? '') !== '') {
            return;
        }

        $token = GeneralUtility::makeInstance(TokenGenerator::class)->generate();

        // Not $dataHandler->updateDB(): that is protected core API. The
        // repository writes the same table through the ConnectionPool.
        GeneralUtility::makeInstance(ProjectRepository::class)->updateProject($uid, [
            'token_hash' => hash('sha256', $token),
        ]);

        $siteUrl = trim((string)($record['site_url'] ?? ''));
        $setupLink = $siteUrl !== ''
            ? $this->setupLink($siteUrl, $token)
            : null;

        $message = $setupLink !== null
            ? sprintf(
                'Token: %s — open %s on the monitored site to configure the agent extension automatically. The token is not stored and cannot be shown again.',
                $token,
                $setupLink
            )
            : sprintf(
                'Token: %s — copy it now into the agent extension configuration. It is not stored and cannot be shown again.',
                $token
            );

        GeneralUtility::makeInstance(FlashMessageService::class)
            ->getMessageQueueByIdentifier()
            ->addMessage(new FlashMessage(
                $message,
                'TypoVigil access token',
                ContextualFeedbackSeverity::INFO,
                true
            ));
    }

    /**
     * Points at the agent's setup route on the monitored site, carrying this
     * hub's own address so the agent knows where to send its reports.
     */
    private function setupLink(string $siteUrl, string $token): string
    {
        return rtrim($siteUrl, '/') . '/typo3/typovigil-agent/setup?' . http_build_query([
            'hub' => rtrim(GeneralUtility::getIndpEnv('TYPO3_SITE_URL'), '/'),
            'token' => $token,
        ]);
    }
}
