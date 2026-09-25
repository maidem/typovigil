<?php

declare(strict_types=1);

namespace Maidemde\TypovigilSitepackage\ViewHelpers;

use Maidemde\Typovigil\Service\VersionCheckService;
use TYPO3\CMS\Core\Domain\RecordInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Resolves the composer_name/label rows of a "Security Sources" Content Block's
 * `sources` Collection into the full, newest-first advisory list across all
 * configured packages — same shape VersionCheckService::securityAdvisories()
 * produces for the hardcoded core-only check, so the existing filter/pagination
 * markup and main.js need no changes.
 *
 * Runs at render time rather than through a DataProcessor: the Collection rows
 * are already in `data.sources` by the time the template renders, so no extra
 * TypoScript wiring is needed to reach them.
 *
 * @return list<array{id: string, title: string, link: string, date: int, severity: string, source: string}>
 */
final class SecuritySourcesViewHelper extends AbstractViewHelper
{
    /**
     * Same cutoff VersionCheckService::MAINTAINED_MAJORS uses for the
     * hardcoded core check — advisories for majors below this are noise here,
     * nobody runs an unmaintained TYPO3 anymore.
     */
    private const OLDEST_MAINTAINED_MAJOR = 12;

    public function initializeArguments(): void
    {
        $this->registerArgument('sources', 'mixed', 'Collection rows with composer_name/label', true);
    }

    public function render(): array
    {
        // Content Blocks hands Collection fields to Fluid as a
        // LazyRecordCollection of RecordInterface objects, not plain arrays —
        // normalize once here so the rest of this method can treat $sources
        // as a simple list of arrays.
        $sources = [];
        foreach ($this->arguments['sources'] as $row) {
            $sources[] = $row instanceof RecordInterface ? $row->toArray() : (array)$row;
        }

        $composerNames = array_values(array_filter(array_map(
            static fn(array $row): string => (string)($row['composer_name'] ?? ''),
            $sources
        )));
        // Label per package, for display alongside each advisory — a source
        // list can mix several packages, so the title alone would not say
        // which one an advisory belongs to.
        $labelByComposerName = [];
        foreach ($sources as $row) {
            $composerName = (string)($row['composer_name'] ?? '');
            if ($composerName !== '') {
                $labelByComposerName[$composerName] = (string)($row['label'] ?? $composerName);
            }
        }

        $service = GeneralUtility::getContainer()->get(VersionCheckService::class);
        $advisoriesByPackage = $service->advisories($composerNames);

        $items = [];
        foreach ($advisoriesByPackage as $composerName => $advisories) {
            foreach ($advisories as $advisory) {
                $title = (string)($advisory['title'] ?? '');
                if ($title === '') {
                    continue;
                }
                if (!self::affectsMaintainedMajor((string)($advisory['affectedVersions'] ?? ''))) {
                    continue;
                }
                $items[] = [
                    'id' => (string)($advisory['cve'] ?? $advisory['advisoryId'] ?? ''),
                    'title' => $title,
                    'link' => (string)($advisory['link'] ?? ''),
                    'date' => strtotime((string)($advisory['reportedAt'] ?? '')) ?: 0,
                    'severity' => (string)($advisory['severity'] ?? 'unknown'),
                    'source' => $labelByComposerName[$composerName] ?? $composerName,
                ];
            }
        }

        usort($items, static fn(array $a, array $b): int => $b['date'] <=> $a['date']);

        return $items;
    }

    /**
     * Whether a Packagist affectedVersions constraint (e.g.
     * "<10.4.57|>=11.0.0,<11.5.51|>=12.0.0,<12.4.46") touches TYPO3 12 or
     * newer. Matched on the version numbers present in the string rather than
     * parsed as a real constraint: this only has to decide "is this still
     * relevant", not resolve exact ranges.
     */
    private static function affectsMaintainedMajor(string $affectedVersions): bool
    {
        if ($affectedVersions === '') {
            return true;
        }

        // Matches the major component of each x.y.z version in the constraint
        // (e.g. the "12" in "12.4.46"), not minor/patch numbers, which could
        // otherwise be misread as a major (".46" is not TYPO3 46).
        preg_match_all('/(\d+)\.\d+\.\d+/', $affectedVersions, $matches);
        foreach ($matches[1] ?? [] as $major) {
            if ((int)$major >= self::OLDEST_MAINTAINED_MAJOR) {
                return true;
            }
        }

        return false;
    }
}
