<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Hook;

use Maidemde\Typovigil\Service\ProjectTokenService;
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
 * A DataHandler hook rather than a PSR-14 listener: verified against TYPO3 v14's
 * DataHandler::insertDB() — it dispatches no event there, so no listener can
 * receive the new record's uid at that point.
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

        $record = BackendUtility::getRecord('tx_typovigil_project', $uid, 'token_hash');
        if (($record['token_hash'] ?? '') !== '') {
            return;
        }

        $token = GeneralUtility::makeInstance(ProjectTokenService::class)->issue($uid);

        $message = sprintf(
            'Token: %s — set it as TYPOVIGIL_AGENT_TOKEN in the environment of the monitored installation now. The token is not stored and cannot be shown again.',
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
}
