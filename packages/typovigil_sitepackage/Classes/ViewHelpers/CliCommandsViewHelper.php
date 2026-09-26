<?php

declare(strict_types=1);

namespace Maidemde\TypovigilSitepackage\ViewHelpers;

use Maidemde\Typovigil\Service\CliCommandService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * All console commands across the configured TYPO3 majors, each tagged with
 * its version so the template's filter pills can show/hide by major without
 * a server round trip — same client-side filter pattern as the security
 * advisory list.
 *
 * @return list<array{command: string, description: string, extension: string, major: string}>
 */
final class CliCommandsViewHelper extends AbstractViewHelper
{
    public function initializeArguments(): void
    {
        $this->registerArgument('majors', 'mixed', 'Comma-separated majors (e.g. "12,13,14") or a list of them', true);
    }

    public function render(): array
    {
        $service = GeneralUtility::getContainer()->get(CliCommandService::class);

        $items = [];
        foreach (self::majorsAsList($this->arguments['majors']) as $major) {
            foreach ($service->commandsForMajor($major) as $command) {
                $command['major'] = $major;
                $items[] = $command;
            }
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    public static function majorsAsList(mixed $majors): array
    {
        // The TCA select field stores its value as a comma-separated string,
        // not an array — Content Blocks does not convert it without an MM
        // relation, which this field has no reason to use.
        $raw = is_array($majors) ? $majors : explode(',', (string)$majors);

        return array_values(array_filter(array_map(
            static fn(mixed $major): string => trim((string)$major),
            $raw
        )));
    }
}
