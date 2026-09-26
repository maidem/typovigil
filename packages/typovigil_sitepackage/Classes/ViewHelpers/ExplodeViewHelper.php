<?php

declare(strict_types=1);

namespace Maidemde\TypovigilSitepackage\ViewHelpers;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Splits a comma-separated TCA select value into a list Fluid's f:for can
 * iterate — this Fluid version ships no f:format.explode of its own.
 *
 * @return list<string>
 */
final class ExplodeViewHelper extends AbstractViewHelper
{
    public function initializeArguments(): void
    {
        $this->registerArgument('value', 'mixed', 'Comma-separated value, or already a list', true);
    }

    public function render(): array
    {
        $value = $this->arguments['value'];
        // Content Blocks hands a multi-value select field to Fluid as an
        // array already; only a plain comma-separated string needs exploding.
        $raw = is_array($value) ? $value : explode(',', (string)$value);

        return array_values(array_filter(array_map(
            static fn(mixed $item): string => trim((string)$item),
            $raw
        )));
    }
}
