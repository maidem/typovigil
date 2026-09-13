<?php

declare(strict_types=1);

/**
 * Self-check for SeverityResolver — the only non-trivial logic in this extension.
 *
 * No framework on purpose: run it with
 *   ddev exec php packages/typovigil/Tests/SeverityResolverTest.php
 * It exits non-zero on the first failed assertion.
 */

require_once __DIR__ . '/../Classes/Domain/Severity.php';
require_once __DIR__ . '/../Classes/Service/SeverityResolver.php';

use Maidemde\Typovigil\Domain\Severity;
use Maidemde\Typovigil\Service\SeverityResolver;

$r = new SeverityResolver();
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

// --- plain version comparison -----------------------------------------------
check($r->resolve('1.0.0', '1.0.0') === Severity::Ok, 'same version is ok');
check($r->resolve('1.0.0', '1.2.0') === Severity::Outdated, 'newer version available is outdated');
check($r->resolve('2.0.0', '1.0.0') === Severity::Ok, 'ahead of latest is not outdated');
check($r->resolve('v14.3.1', '14.3.7') === Severity::Outdated, 'leading v is stripped');

// A stable release must not read as older than itself because of a suffix.
check($r->normalize('1.2.3-beta1') === '1.2.3', 'stability suffix is stripped');
check($r->normalize('dev-main') === '', 'branch versions yield empty, not garbage');
check($r->resolve('dev-main', '1.0.0') === Severity::Ok, 'unparseable version never reports outdated');

// --- advisories outrank mere outdatedness ------------------------------------
$advisory = [['affectedVersions' => '>=1.0.0,<1.2.0']];
check($r->resolve('1.1.0', '1.2.0', $advisory) === Severity::Critical, 'version inside advisory range is critical');
check($r->resolve('1.2.0', '1.2.0', $advisory) === Severity::Ok, 'version outside advisory range is ok');
check($r->resolve('0.9.0', '1.2.0', $advisory) === Severity::Outdated, 'below advisory range is only outdated');

// Multi-branch constraints, as Packagist emits them for TYPO3 extensions.
$multi = [['affectedVersions' => '>=13.0.0,<13.4.2|>=14.0.0,<14.3.5']];
check($r->resolve('13.4.1', '14.3.7', $multi) === Severity::Critical, 'first or-branch matches');
check($r->resolve('14.3.4', '14.3.7', $multi) === Severity::Critical, 'second or-branch matches');
check($r->resolve('13.4.2', '14.3.7', $multi) === Severity::Outdated, 'patched in first branch is not critical');
check($r->resolve('14.3.5', '14.3.7', $multi) === Severity::Outdated, 'patched in second branch is not critical');

// --- core: a newer security release is always critical -----------------------
$releases = [
    ['version' => '14.3.1', 'type' => 'regular'],
    ['version' => '14.3.7', 'type' => 'security'],
];
check($r->resolveCore('14.3.1', $releases) === Severity::Critical, 'newer security release makes core critical');
check($r->resolveCore('14.3.7', $releases) === Severity::Ok, 'on the security release itself is ok');

$regularOnly = [
    ['version' => '14.3.1', 'type' => 'regular'],
    ['version' => '14.3.7', 'type' => 'regular'],
];
check($r->resolveCore('14.3.1', $regularOnly) === Severity::Outdated, 'newer regular release is only outdated');
check($r->resolveCore('14.3.7', $regularOnly) === Severity::Ok, 'newest regular release is ok');

// An older security release must not drag a patched install back to critical.
$oldSecurity = [
    ['version' => '14.3.0', 'type' => 'security'],
    ['version' => '14.3.7', 'type' => 'regular'],
];
check($r->resolveCore('14.3.7', $oldSecurity) === Severity::Ok, 'past security release does not affect current version');

// --- severity ordering -------------------------------------------------------
check(Severity::Critical->isWorseThan(Severity::Outdated), 'critical outranks outdated');
check(Severity::Outdated->isWorseThan(Severity::Ok), 'outdated outranks ok');
check(!Severity::Ok->isWorseThan(Severity::Critical), 'ok never outranks critical');

echo "SeverityResolver: {$checks} checks passed\n";
