<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Hook;

use Maidemde\Typovigil\Service\SecretCipher;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Encrypts a project's database password on its way into the database.
 *
 * In processDatamap_postProcessFieldArray rather than preProcess: the
 * DataHandler evaluates the field between the two, and a value encrypted
 * before that ran came back out unencrypted. Here the field array is final
 * and goes straight to the query, so what this writes is what is stored.
 *
 * The TCA sets hashed => false for the same reason this exists at all: a
 * database password has to be readable again, so TYPO3's own password
 * handling (which hashes) would make it useless.
 *
 * A value that is already encrypted passes through untouched — that is every
 * save where the field was not edited.
 */
final class EncryptDatabasePassword
{
    /**
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_postProcessFieldArray(
        string $status,
        string $table,
        int|string $id,
        array &$fieldArray,
        DataHandler $dataHandler
    ): void {
        if ($table !== 'tx_typovigil_project' || !isset($fieldArray['db_password'])) {
            return;
        }

        // Not trimmed: a password is an opaque string, and a generated one
        // can legitimately start or end with whitespace. Trimming it here
        // silently produces a different password than the one on the
        // database — which is exactly what happened before this comment
        // was written: a correct password rejected every time, because this
        // hook had quietly changed it before encrypting it.
        $value = (string)$fieldArray['db_password'];
        if ($value === '' || SecretCipher::isEncrypted($value)) {
            return;
        }

        $fieldArray['db_password'] = GeneralUtility::makeInstance(SecretCipher::class)->encrypt($value);
    }
}
