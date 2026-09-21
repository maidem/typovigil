<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Service;

/**
 * Encrypts and decrypts the one kind of secret TypoVigil has to keep: a
 * monitored project's database password.
 *
 * Everything else (API tokens) lives in the environment, and the agent's
 * token is only ever stored as a hash — see ProjectTokenService. This exists
 * because a database password has to be usable again, so hashing is not an
 * option and plain text would mean one leak of this database exposing every
 * customer's.
 *
 * Keyed off TYPO3's encryptionKey: losing it makes the stored passwords
 * unreadable, and they have to be entered again. That is the trade for not
 * introducing a second secret to manage.
 */
final readonly class SecretCipher
{
    private const CIPHER = 'aes-256-gcm';
    private const PREFIX = 'enc:v1:';
    private const TAG_BYTES = 16;

    // No constructor: this is reached from a DataHandler hook through
    // GeneralUtility::makeInstance(), which cannot resolve arguments there.
    // random_bytes() is the same source TYPO3's Random uses for this anyway.

    /**
     * Whether a stored value is already encrypted — so a password typed into
     * the backend field in plain text is recognised and encrypted on save,
     * and an already-encrypted one is not encrypted twice.
     */
    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $key = self::key();
        if ($key === '') {
            // Refusing beats storing it readable: without a key there is no
            // way to keep the promise the field's description makes.
            throw new \RuntimeException('Cannot encrypt: TYPO3 encryptionKey is not set.', 1758480000);
        }

        $ivLength = (int)openssl_cipher_iv_length(self::CIPHER);
        $iv = random_bytes($ivLength);
        $tag = '';

        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_BYTES);
        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed.', 1758480001);
        }

        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Returns the plaintext, or '' when the value cannot be read — a wrong
     * or rotated key must not take the whole backup down with an exception,
     * it just means this project has no usable password until someone enters
     * it again.
     */
    public function decrypt(string $stored): string
    {
        if ($stored === '') {
            return '';
        }

        // Not encrypted: a value entered before this existed, or written
        // straight into the database. Usable as-is.
        if (!self::isEncrypted($stored)) {
            return $stored;
        }

        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        $key = self::key();
        if ($raw === false || $key === '') {
            return '';
        }

        $ivLength = (int)openssl_cipher_iv_length(self::CIPHER);
        if (strlen($raw) <= $ivLength + self::TAG_BYTES) {
            return '';
        }

        $iv = substr($raw, 0, $ivLength);
        $tag = substr($raw, $ivLength, self::TAG_BYTES);
        $ciphertext = substr($raw, $ivLength + self::TAG_BYTES);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);

        return $plaintext === false ? '' : $plaintext;
    }

    /**
     * TYPO3_ENCRYPTION_KEY first, the configured key only as a fallback.
     *
     * Not interchangeable, however much they look it: config/system is
     * rebuilt from the image on every container deploy, so the key in
     * settings.php is a different one after each — and anything encrypted
     * with the old one is lost. The environment variable survives, which is
     * the only reason a stored password is still readable tomorrow.
     */
    private static function key(): string
    {
        $env = getenv('TYPO3_ENCRYPTION_KEY');
        if ($env !== false && $env !== '') {
            return $env;
        }

        return (string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '');
    }
}
