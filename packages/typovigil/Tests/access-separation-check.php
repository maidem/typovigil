<?php

declare(strict_types=1);

/**
 * Second security path: a logged-in customer must not reach another customer's
 * project by editing the uid in a URL. Page-level access rights do not cover
 * this, because the uid arrives as a request argument.
 *
 * Needs the fixture users and projects; it creates them itself and cleans up.
 *
 * Run: ddev exec php packages/typovigil/Tests/access-separation-check.php
 */

$root = dirname(__DIR__, 3);
putenv('TYPO3_CONTEXT=Development');
$classLoader = require $root . '/vendor/autoload.php';
\TYPO3\CMS\Core\Core\SystemEnvironmentBuilder::run(0, \TYPO3\CMS\Core\Core\SystemEnvironmentBuilder::REQUESTTYPE_CLI);
$container = \TYPO3\CMS\Core\Core\Bootstrap::init($classLoader);

$connectionPool = $container->get(\TYPO3\CMS\Core\Database\ConnectionPool::class);
$repo = $container->get(\Maidemde\Typovigil\Domain\Repository\ProjectRepository::class);
$status = $container->get(\Maidemde\Typovigil\Service\StatusReportService::class);

$projectTable = 'tx_typovigil_project';
$mmTable = 'tx_typovigil_project_feuser_mm';
$marker = 'access-separation-fixture';

$projectConn = $connectionPool->getConnectionForTable($projectTable);
$mmConn = $connectionPool->getConnectionForTable($mmTable);

// --- fixture -----------------------------------------------------------------
$projectConn->delete($projectTable, ['notes' => $marker]);
$now = time();
$ids = [];
foreach (['Fixture A', 'Fixture B'] as $title) {
    $projectConn->insert($projectTable, [
        'pid' => 0,
        'tstamp' => $now,
        'crdate' => $now,
        'title' => $title,
        'token_hash' => hash('sha256', bin2hex(random_bytes(16))),
        'notes' => $marker,
    ]);
    $ids[] = (int)$projectConn->lastInsertId();
}
[$projectA, $projectB] = $ids;
// Frontend user ids that do not collide with real records
$userA = 900001;
$userB = 900002;

foreach ([[$projectA, $userA], [$projectB, $userB]] as [$project, $user]) {
    $mmConn->delete($mmTable, ['uid_local' => $project]);
    $mmConn->insert($mmTable, [
        'uid_local' => $project,
        'uid_foreign' => $user,
        'sorting' => 1,
        'sorting_foreign' => 1,
    ]);
}

$failed = false;
function check(bool $ok, string $what): void
{
    global $failed;
    printf("%-58s %s\n", $what, $ok ? 'OK' : 'FAIL');
    $failed = $failed || !$ok;
}

// --- own projects are visible -------------------------------------------------
check($repo->isVisibleToFrontendUser($projectA, $userA), 'user A sees own project');
check($repo->isVisibleToFrontendUser($projectB, $userB), 'user B sees own project');

// --- the actual attack --------------------------------------------------------
check(!$repo->isVisibleToFrontendUser($projectB, $userA), 'user A does NOT see project B');
check(!$repo->isVisibleToFrontendUser($projectA, $userB), 'user B does NOT see project A');

// --- no session, no assignment -------------------------------------------------
check(!$repo->isVisibleToFrontendUser($projectA, 0), 'anonymous sees nothing');
check(!$repo->isVisibleToFrontendUser($projectA, 999999), 'unassigned user sees nothing');
check(!$repo->isVisibleToFrontendUser(0, $userA), 'project uid 0 is refused');

// --- listings are filtered the same way -----------------------------------------
$listA = $status->summariesForFrontendUser($userA);
$listB = $status->summariesForFrontendUser($userB);
check(count($listA) === 1 && (int)$listA[0]['uid'] === $projectA, 'list of A holds only project A');
check(count($listB) === 1 && (int)$listB[0]['uid'] === $projectB, 'list of B holds only project B');
check($status->summariesForFrontendUser(0) === [], 'anonymous list is empty');
check($status->summariesForFrontendUser(999999) === [], 'unassigned list is empty');

// --- cleanup --------------------------------------------------------------------
foreach ($ids as $id) {
    $mmConn->delete($mmTable, ['uid_local' => $id]);
}
$projectConn->delete($projectTable, ['notes' => $marker]);

echo $failed ? "\nACCESS SEPARATION BROKEN\n" : "\nAccess separation holds\n";
exit($failed ? 1 : 0);
