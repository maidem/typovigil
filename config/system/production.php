<?php

declare(strict_types=1);

/**
 * Production configuration (Coolify / Docker).
 *
 * Loaded from settings.php. Deliberately NOT in additional.php: ddev owns that
 * file (#ddev-generated) and config/system/.gitignore excludes it, so anything
 * put there would never reach the repository.
 *
 * Everything here is skipped inside ddev, which configures itself.
 */

if (getenv('IS_DDEV_PROJECT') === 'true') {
    return;
}

$dbHost = getenv('TYPO3_DATABASE_HOST') ?: '127.0.0.1';
$dbPort = (int)(getenv('TYPO3_DATABASE_PORT') ?: 3306);
$dbUser = getenv('TYPO3_DATABASE_USERNAME') ?: '';
$dbPassword = getenv('TYPO3_DATABASE_PASSWORD') ?: '';
$dbName = getenv('TYPO3_DATABASE_NAME') ?: '';

$GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] = array_merge(
    $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] ?? [],
    [
        'driver' => 'mysqli',
        'host' => $dbHost,
        'port' => $dbPort,
        'user' => $dbUser,
        'password' => $dbPassword,
        'dbname' => $dbName,
        'driverOptions' => [
            // Fail fast instead of hanging for minutes when the host is unreachable
            MYSQLI_OPT_CONNECT_TIMEOUT => 5,
        ],
    ]
);

if (getenv('TYPO3_CONTEXT') === 'Production') {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['displayErrors'] = 0;
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['devIPmask'] = '';

    // Behind Traefik: without these the backend login fails with "Missing referrer"
    // and redirects loop between http and https.
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxy_ips'] = '*';
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxy_ssl'] = '*';
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] = '.*';

    if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
        $_SERVER['HTTPS'] = 'on';
    }
}

// SMTP is optional: without a server TYPO3 falls back to sendmail, which does not
// exist in the container. Only override when actually configured.
if ($smtpServer = getenv('TYPO3_SMTP_SERVER')) {
    $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = 'smtp';
    $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_server'] = $smtpServer;
    $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_encrypt'] = 'tls';
    $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_username'] = getenv('TYPO3_SMTP_USER') ?: '';
    $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_password'] = getenv('TYPO3_SMTP_PASSWORD') ?: '';
}
