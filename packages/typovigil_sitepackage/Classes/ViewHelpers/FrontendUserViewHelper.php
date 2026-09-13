<?php

declare(strict_types=1);

namespace Maidemde\TypovigilSitepackage\ViewHelpers;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * The display name of the logged-in frontend user, or '' when nobody is.
 *
 * Exists because the user area sits in the PAGEVIEW layout, where no Extbase
 * controller runs to assign the value — and TYPO3 ships no frontend user
 * ViewHelper of its own (only IfAuthenticated / IfHasRole). The Context aspect
 * would be the shorter route but only exposes id and username, not the name.
 *
 * Falls back to the username: fe_users.name is optional, and an empty name
 * would leave the menu showing an icon with no label at all.
 */
final class FrontendUserViewHelper extends AbstractViewHelper
{
    public function render(): string
    {
        if (!$this->renderingContext->hasAttribute(ServerRequestInterface::class)) {
            return '';
        }

        $request = $this->renderingContext->getAttribute(ServerRequestInterface::class);
        $user = $request->getAttribute('frontend.user')?->user ?? null;
        if (!is_array($user) || (int)($user['uid'] ?? 0) === 0) {
            return '';
        }

        $name = trim((string)($user['name'] ?? ''));

        return $name !== '' ? $name : trim((string)($user['username'] ?? ''));
    }
}
