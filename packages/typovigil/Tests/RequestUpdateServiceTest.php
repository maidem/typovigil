<?php

declare(strict_types=1);

/**
 * Self-check for the gates in RequestUpdateService — the part that decides
 * whether an update may be requested at all. The dispatch itself is plumbing
 * over GitHubClient and not covered here.
 *
 * Run it with
 *   ddev exec php packages/typovigil/Tests/RequestUpdateServiceTest.php
 */

require_once __DIR__ . '/../Classes/Service/RequestUpdateService.php';

use Maidemde\Typovigil\Service\RequestUpdateService;

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

$linkedProject = [
    'github_repo' => 'maidem/example',
    'coolify_application_uuid' => 'app-uuid',
    'coolify_database_uuid' => 'db-uuid',
];

$approvedPackages = [
    ['composer_name' => 'typo3/cms-core', 'severity' => 'critical', 'ai_report_status' => 'approved'],
    ['composer_name' => 'acme/news', 'severity' => 'outdated', 'ai_report_status' => ''],
    ['composer_name' => 'acme/stable', 'severity' => 'ok', 'ai_report_status' => ''],
];

check(RequestUpdateService::appliesTo($linkedProject), 'a project with a repository can use the button');
check(
    !RequestUpdateService::appliesTo(['github_repo' => '  ']),
    'whitespace is not a repository'
);

check(
    RequestUpdateService::blockedBecause($linkedProject, $approvedPackages) === null,
    'a linked project with approved reports is not blocked'
);

// The approval gate: one pending report is enough to stop the update, and
// the reason names how many, so the operator knows what to go and approve.
$withPending = $approvedPackages;
$withPending[0]['ai_report_status'] = 'pending';
$reason = RequestUpdateService::blockedBecause($linkedProject, $withPending);
check($reason !== null, 'a pending report blocks the update');
check(str_contains((string)$reason, '1'), 'the reason names how many reports are pending');

check(RequestUpdateService::pendingReportCount($withPending) === 1, 'counts one pending report');
check(RequestUpdateService::pendingReportCount($approvedPackages) === 0, 'approved reports do not count as pending');

// The backup gate: a project not linked to the hosting platform cannot be
// backed up, so it cannot be updated either.
$unlinked = $linkedProject;
$unlinked['coolify_database_uuid'] = '';
check(
    RequestUpdateService::blockedBecause($unlinked, $approvedPackages) !== null,
    'a project without a backup link is blocked'
);

// A pending report outranks the missing backup link in the message: it is
// the one the operator can resolve without touching another system.
$unlinkedAndPending = $unlinked;
check(
    str_contains((string)RequestUpdateService::blockedBecause($unlinked, $withPending), 'approval'),
    'the pending report is reported before the missing backup link'
);

// Only packages with somewhere to go are handed to composer.
$updatable = RequestUpdateService::updatablePackages($approvedPackages);
check(in_array('typo3/cms-core', $updatable, true), 'a critical package is updatable');
check(in_array('acme/news', $updatable, true), 'an outdated package is updatable');
check(!in_array('acme/stable', $updatable, true), 'an up-to-date package is left alone');

// A TER-only extension has no composer name, so there is nothing to pass to
// composer — it must not end up as an empty argument.
$noComposerName = [['composer_name' => '', 'extension_key' => 'legacy_ext', 'severity' => 'critical']];
check(
    RequestUpdateService::updatablePackages($noComposerName) === [],
    'a package without a composer name is not passed to composer'
);

fwrite(STDOUT, "OK: {$checks} checks passed\n");
