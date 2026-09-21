<?php

declare(strict_types=1);

/**
 * Self-check for SecretCipher — the one place TypoVigil keeps a secret it
 * has to be able to read back, so the round trip and the failure modes
 * matter more than anywhere else in this extension.
 *
 * Run it with
 *   ddev exec php packages/typovigil/Tests/SecretCipherTest.php
 */

require_once __DIR__ . '/../Classes/Service/SecretCipher.php';

use Maidemde\Typovigil\Service\SecretCipher;

$checks = 0;

function check(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// Stand-in for TYPO3\CMS\Core\Crypto\Random, so this runs without a
// bootstrap — the cipher only needs random bytes from it.
if (!class_exists(\TYPO3\CMS\Core\Crypto\Random::class)) {
    eval('namespace TYPO3\CMS\Core\Crypto; class Random { public function generateRandomBytes(int $length): string { return random_bytes($length); } }');
}

$GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('a', 96);
$cipher = new SecretCipher(new \TYPO3\CMS\Core\Crypto\Random());

$secret = 'p4ssw0rd mit Ümläuten und "Anführungszeichen"';
$encrypted = $cipher->encrypt($secret);

check($encrypted !== $secret, 'the ciphertext is not the plaintext');
check(!str_contains($encrypted, $secret), 'the plaintext does not survive inside the ciphertext');
check(SecretCipher::isEncrypted($encrypted), 'an encrypted value is recognised as such');
check(!SecretCipher::isEncrypted($secret), 'a plain value is not mistaken for an encrypted one');
check($cipher->decrypt($encrypted) === $secret, 'the round trip returns the original');

// Same input twice must not produce the same ciphertext, otherwise equal
// passwords would be visible as equal in the database.
check($cipher->encrypt($secret) !== $encrypted, 'encrypting twice yields different ciphertexts');

// A value stored before encryption existed, or written straight into the
// database, is returned as-is rather than being lost.
check($cipher->decrypt('plain-old-password') === 'plain-old-password', 'an unencrypted stored value still works');

check($cipher->encrypt('') === '', 'an empty secret stays empty');
check($cipher->decrypt('') === '', 'an empty stored value stays empty');

// A tampered or truncated ciphertext must fail closed — GCM authenticates,
// so this is a decryption failure, not garbage output.
$tampered = substr($encrypted, 0, -4) . 'AAAA';
check($cipher->decrypt($tampered) === '', 'a tampered ciphertext decrypts to nothing, not to garbage');
check($cipher->decrypt('enc:v1:not-base64!!') === '', 'malformed input decrypts to nothing');

// A different key must not be able to read it — the guarantee the field
// description makes to whoever types a password in.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('b', 96);
check($cipher->decrypt($encrypted) === '', 'another key cannot read the secret');

// Without a key, refusing beats storing something readable.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = '';
$refused = false;
try {
    $cipher->encrypt('something');
} catch (\RuntimeException) {
    $refused = true;
}
check($refused, 'encrypting without a key is refused rather than silently skipped');

fwrite(STDOUT, "OK: {$checks} checks passed\n");
