<?php

declare(strict_types=1);

namespace Maidemde\TypovigilSitepackage\ViewHelpers;

use Maidemde\Typovigil\Service\VersionCheckService;
use TYPO3\CMS\Core\Domain\RecordInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Resolves a "Core Versions" Content Block's `majors` Collection (e.g. "12",
 * "13", "14") into the newest known release per major, queried live against
 * get.typo3.org — same shape VersionCheckService::maintainedCoreVersions()
 * produces for its hardcoded major list, but with the list itself editorial.
 *
 * @return list<array{major: string, version: string, severity: string, severityLabel: string}>
 */
final class CoreVersionsViewHelper extends AbstractViewHelper
{
    public function initializeArguments(): void
    {
        $this->registerArgument('majors', 'mixed', 'Collection rows with a major_version field', true);
    }

    public function render(): array
    {
        // Content Blocks hands Collection fields to Fluid as a
        // LazyRecordCollection of RecordInterface objects, not plain arrays.
        $rows = [];
        foreach ($this->arguments['majors'] as $row) {
            $rows[] = $row instanceof RecordInterface ? $row->toArray() : (array)$row;
        }

        $service = GeneralUtility::getContainer()->get(VersionCheckService::class);

        $cards = [];
        foreach ($rows as $row) {
            $major = (string)($row['major_version'] ?? '');
            if ($major === '' || !ctype_digit($major)) {
                continue;
            }

            // coreReleases() takes a version string and reads its major part
            // itself — passing "$major.0.0" reuses that lookup for a bare
            // major number without duplicating get.typo3.org's release shape.
            $releases = $service->coreReleases($major . '.0.0');
            if ($releases === []) {
                continue;
            }

            // get.typo3.org lists releases newest first.
            $newest = $releases[0];
            $isSecurity = ($newest['type'] ?? '') === 'security';

            $cards[] = [
                'major' => $major,
                'version' => (string)($newest['version'] ?? ''),
                'severity' => $isSecurity ? 'critical' : 'ok',
                'severityLabel' => $isSecurity ? 'Sicherheitsupdate' : 'Aktuell',
            ];
        }

        return $cards;
    }
}
