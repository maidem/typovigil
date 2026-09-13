<?php

declare(strict_types=1);

/**
 * Verifies every class of this extension is reachable through the autoloader.
 * Run: ddev exec php packages/typovigil/Tests/autoload-check.php
 */

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

$classes = [
    \Maidemde\Typovigil\Service\SeverityResolver::class,
    \Maidemde\Typovigil\Service\TokenGenerator::class,
    \Maidemde\Typovigil\Service\VersionCheckService::class,
    \Maidemde\Typovigil\Domain\Repository\ProjectRepository::class,
    \Maidemde\Typovigil\Middleware\ReportReceiver::class,
    \Maidemde\Typovigil\Hook\GenerateProjectToken::class,
    \Maidemde\Typovigil\Domain\Severity::class,
];

$failed = false;
foreach ($classes as $class) {
    $ok = class_exists($class) || enum_exists($class);
    printf("%-68s %s\n", $class, $ok ? 'OK' : 'MISSING');
    $failed = $failed || !$ok;
}

exit($failed ? 1 : 0);
