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

$approvedPackages = [
    ['composer_name' => 'typo3/cms-core', 'severity' => 'critical', 'ai_report_status' => 'approved', 'installed_version' => '14.3.5'],
    ['composer_name' => 'acme/news', 'severity' => 'outdated', 'ai_report_status' => '', 'installed_version' => '1.0.0'],
    ['composer_name' => 'acme/stable', 'severity' => 'ok', 'ai_report_status' => '', 'installed_version' => '2.0.0'],
];

$linkedProject = [
    'github_repo' => 'maidem/example',
    'coolify_application_uuid' => 'app-uuid',
    'coolify_database_uuid' => 'db-uuid',
    // A backup was taken against exactly this package state.
    'last_backup_state' => RequestUpdateService::packageState($approvedPackages),
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
check($reason['key'] === 'blocked.pendingReports', 'the reason names the pending reports');
check($reason['argument'] === 1, 'the reason carries how many are pending');

check(RequestUpdateService::pendingReportCount($withPending) === 1, 'counts one pending report');
check(RequestUpdateService::pendingReportCount($approvedPackages) === 0, 'approved reports do not count as pending');

// The backup gate: a project not linked to the hosting platform cannot be
// backed up, so it cannot be updated either.
$unlinked = $linkedProject;
$unlinked['coolify_database_uuid'] = '';
check(
    RequestUpdateService::blockedBecause($unlinked, $approvedPackages)['key'] === 'blocked.noBackup',
    'a project without a backup link is blocked'
);

// A pending report outranks the missing backup link: it is the one the
// operator can resolve without touching another system.
check(
    RequestUpdateService::blockedBecause($unlinked, $withPending)['key'] === 'blocked.pendingReports',
    'the pending report is reported before the missing backup link'
);

// The backup gate proper: linked to the platform, reports approved, but no
// backup taken yet.
$neverBackedUp = $linkedProject;
$neverBackedUp['last_backup_state'] = '';
check(
    RequestUpdateService::blockedBecause($neverBackedUp, $approvedPackages)['key'] === 'blocked.backupMissing',
    'without a successful backup the update is blocked'
);

// And the point of fingerprinting it: a backup taken against a different
// package state does not cover what is about to be updated.
$movedOn = $approvedPackages;
$movedOn[0]['installed_version'] = '14.3.6';
check(
    RequestUpdateService::blockedBecause($linkedProject, $movedOn)['key'] === 'blocked.backupMissing',
    'a backup taken before the versions changed no longer counts'
);

check(
    RequestUpdateService::hasCurrentBackup($linkedProject, $approvedPackages),
    'a backup against the current state counts'
);

// The fingerprint must not depend on the order rows come back in.
check(
    RequestUpdateService::packageState($approvedPackages)
        === RequestUpdateService::packageState(array_reverse($approvedPackages)),
    'the package state fingerprint is order-independent'
);

// The findings behind a request: what has somewhere to go. This describes
// the update, it is not the argument list for composer — see the method's
// docblock for why naming a subset does not work with TYPO3.
$updatable = RequestUpdateService::updatablePackages($approvedPackages);
check(in_array('typo3/cms-core', $updatable, true), 'a critical package counts as a finding');
check(in_array('acme/news', $updatable, true), 'an outdated package counts as a finding');
check(!in_array('acme/stable', $updatable, true), 'an up-to-date package is not a finding');

// An empty result is what stops the request ("everything is already up to
// date"), so a package with no composer name must not silently fill it.
$noComposerName = [['composer_name' => '', 'extension_key' => 'legacy_ext', 'severity' => 'critical']];
check(
    RequestUpdateService::updatablePackages($noComposerName) === [],
    'a package without a composer name does not count as a finding'
);

fwrite(STDOUT, "OK: {$checks} checks passed\n");
