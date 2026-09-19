<?php

declare(strict_types=1);

/**
 * Self-check for BackupBeforeUpdateService::executionIsFresh() — the only
 * non-trivial decision in the Coolify backup flow (everything else is
 * plumbing between ProjectRepository and CoolifyClient, both final and
 * readonly, so not worth mocking here).
 *
 * Run it with
 *   ddev exec php packages/typovigil/Tests/BackupBeforeUpdateServiceTest.php
 */

require_once __DIR__ . '/../Classes/Service/BackupBeforeUpdateService.php';

use Maidemde\Typovigil\Service\BackupBeforeUpdateService;

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

$now = strtotime('2026-01-01 12:00:00');

// --- fresh, successful backup -----------------------------------------------
check(
    BackupBeforeUpdateService::executionIsFresh(
        ['status' => 'success', 'created_at' => '2026-01-01T11:00:00+00:00', 'filename' => 'x.sql'],
        3600,
        $now
    ),
    'a successful backup within the max age is fresh'
);

// --- too old -----------------------------------------------------------------
check(
    !BackupBeforeUpdateService::executionIsFresh(
        ['status' => 'success', 'created_at' => '2026-01-01T09:00:00+00:00', 'filename' => 'x.sql'],
        3600,
        $now
    ),
    'a successful backup older than the max age is not fresh'
);

// --- failed status, even if recent -------------------------------------------
check(
    !BackupBeforeUpdateService::executionIsFresh(
        ['status' => 'failed', 'created_at' => '2026-01-01T11:59:00+00:00', 'filename' => ''],
        3600,
        $now
    ),
    'a failed execution is never fresh, regardless of age'
);

// --- unparsable timestamp -----------------------------------------------------
check(
    !BackupBeforeUpdateService::executionIsFresh(
        ['status' => 'success', 'created_at' => 'not-a-date', 'filename' => 'x.sql'],
        3600,
        $now
    ),
    'an unparsable created_at is never fresh'
);

// --- exactly at the boundary is still fresh -----------------------------------
check(
    BackupBeforeUpdateService::executionIsFresh(
        ['status' => 'ok', 'created_at' => '2026-01-01T11:00:00+00:00', 'filename' => 'x.sql'],
        3600,
        $now
    ),
    'exactly at max age is still fresh, and "ok" is accepted like "success"'
);

fwrite(STDOUT, "OK: {$checks} checks passed\n");
