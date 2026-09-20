<?php

declare(strict_types=1);

/**
 * Checks that stored reachability rows become the three states the footer
 * renders: answered, failed, never asked.
 *
 * Exercises VersionCheckService::mapSourceRows() directly with plain arrays.
 * The storage itself moved into a database table, and faking a Doctrine
 * connection here would mean three layers of scaffolding to re-prove a match
 * expression — the mapping and the per-host attribution are what actually
 * carry the risk, and both are pure.
 *
 * Standalone like the other checks here — run with `php SourceStatusTest.php`.
 */

require __DIR__ . '/../../../vendor/autoload.php';

use Maidemde\Typovigil\Service\VersionCheckService;

$failures = 0;

function check(string $label, bool $ok): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    printf("%-60s %s\n", $label, $ok ? 'OK' : 'FAILED');
}

/**
 * @param array<string, array{reachable: int, checked_at: int}> $rows
 * @return array<string, string> label => state
 */
function statesByLabel(array $rows): array
{
    $states = [];
    foreach (VersionCheckService::mapSourceRows($rows) as $source) {
        $states[$source['label']] = $source['state'];
    }

    return $states;
}

// Nothing queried yet: every source is unknown, not broken. A fresh install
// must not look like every upstream is dead.
$states = statesByLabel([]);
check('five sources are reported', count($states) === 5);
check('unqueried source is "unknown"', ($states['Packagist'] ?? '') === 'unknown');
check('no source is "down" before the first run', !in_array('down', $states, true));

// A successful answer marks that source, and only that source, reachable.
$now = time();
$states = statesByLabel(['repo.packagist.org' => ['reachable' => 1, 'checked_at' => $now]]);
check('answering source is "ok"', ($states['Packagist'] ?? '') === 'ok');
check('untouched source stays "unknown"', ($states['get.typo3.org'] ?? '') === 'unknown');

// A failing upstream shows as down without disturbing the others.
$states = statesByLabel([
    'repo.packagist.org' => ['reachable' => 1, 'checked_at' => $now],
    'get.typo3.org' => ['reachable' => 0, 'checked_at' => $now],
]);
check('failing source is "down"', ($states['get.typo3.org'] ?? '') === 'down');
check('previously ok source stays "ok"', ($states['Packagist'] ?? '') === 'ok');

// Recovery: a source that answers again must stop being shown as broken.
$states = statesByLabel(['get.typo3.org' => ['reachable' => 1, 'checked_at' => $now]]);
check('recovered source is "ok" again', ($states['get.typo3.org'] ?? '') === 'ok');

// The advisories endpoint is a different host than the package endpoint and
// must be attributed separately, or one Packagist outage would mislabel both.
check(
    'advisories are a separate source',
    array_key_exists('Sicherheitsmeldungen', $states)
        && $states['Sicherheitsmeldungen'] === 'unknown'
);

// The templates put checkedAtLabel straight into a title attribute, so it has
// to be a ready-made string: filled for a queried source, empty for one that
// was never asked (no "zuletzt geprüft 01.01.1970").
$labels = [];
foreach (VersionCheckService::mapSourceRows(['get.typo3.org' => ['reachable' => 1, 'checked_at' => $now]]) as $source) {
    $labels[$source['label']] = $source['checkedAtLabel'];
}
check(
    'queried source carries a formatted timestamp',
    (bool)preg_match('/^\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}$/', $labels['get.typo3.org'] ?? '')
);
check('unqueried source has an empty label', ($labels['Sicherheitsmeldungen'] ?? 'x') === '');

echo $failures === 0
    ? "\nSourceStatus: all checks passed\n"
    : "\nSourceStatus: {$failures} check(s) FAILED\n";

exit($failures === 0 ? 0 : 1);
