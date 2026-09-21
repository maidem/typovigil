<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Hook;

use Maidemde\Typovigil\Service\SecretCipher;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Encrypts a project's database password on its way into the database.
 *
 * In processDatamap_preProcessFieldArray, so the plaintext never reaches a
 * row at all — not even for the moment it would take a later hook to
 * overwrite it. A value that is already encrypted passes through untouched,
 * which is what happens on every save where the field was not edited.
 */
final class EncryptDatabasePassword
{
    /**
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_preProcessFieldArray(
        array &$fieldArray,
        string $table,
        int|string $id,
        DataHandler $dataHandler
    ): void {
        if ($table !== 'tx_typovigil_project' || !isset($fieldArray['db_password'])) {
            return;
        }

        $value = (string)$fieldArray['db_password'];
        if ($value === '' || SecretCipher::isEncrypted($value)) {
            return;
        }

        $fieldArray['db_password'] = GeneralUtility::makeInstance(SecretCipher::class)->encrypt($value);
    }
}
