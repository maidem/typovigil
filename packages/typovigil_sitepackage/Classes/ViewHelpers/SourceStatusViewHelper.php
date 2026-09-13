<?php

declare(strict_types=1);

namespace Maidemde\TypovigilSitepackage\ViewHelpers;

use Maidemde\Typovigil\Service\VersionCheckService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Reachability of the upstream data sources, for the page footer.
 *
 * Needed because the footer sits in the PAGEVIEW layout — outside the plugin —
 * so no controller assigns the value. It has to live there: only a direct child
 * of the flex body can be pushed to the bottom edge, and the plugin renders
 * inside TYPO3's frame wrapper.
 *
 * @return list<array{label: string, state: string, checkedAt: int, checkedAtLabel: string}>
 */
final class SourceStatusViewHelper extends AbstractViewHelper
{
    public function render(): array
    {
        return GeneralUtility::makeInstance(VersionCheckService::class)->sourceStatus();
    }
}
