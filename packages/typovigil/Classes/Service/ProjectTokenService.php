<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Service;

use Maidemde\Typovigil\Domain\Repository\ProjectRepository;
use TYPO3\CMS\Core\Http\NormalizedParams;

/**
 * Issues a fresh bearer token for a project and, when a site URL is on
 * record, the one-click link that lets the agent extension pick it up
 * without anyone copying it by hand.
 *
 * Shared between the "new project" hook and the "generate setup link" backend
 * action so both paths issue tokens the same way.
 */
final readonly class ProjectTokenService
{
    /**
     * Session key the just-issued token is stashed under so the TypoVigil
     * module can show it with a copy button once, right after the record
     * edit form (where it is first issued) redirects back there. A backend
     * user session, not the database: the plain token is never persisted.
     */
    private const SESSION_KEY = 'typovigil_justIssuedSetupLink';

    public function __construct(
        private ProjectRepository $projects,
        private TokenGenerator $tokenGenerator,
    ) {}

    /**
     * @return array{token: string, setupLink: string|null}
     */
    public function issue(int $projectUid, string $siteUrl): array
    {
        $token = $this->tokenGenerator->generate();

        $this->projects->updateProject($projectUid, [
            'token_hash' => hash('sha256', $token),
        ]);

        $siteUrl = trim($siteUrl);
        $setupLink = $siteUrl !== '' ? $this->setupLink($siteUrl, $token) : null;

        $this->stashForModule($token, $setupLink);

        return ['token' => $token, 'setupLink' => $setupLink];
    }

    /**
     * Fetches and clears the token stashed by the last issue() call in this
     * backend user's session, if any. One-time read, same as the flash
     * message: once shown in the module, it is gone.
     *
     * @return array{token: string, setupLink: string|null}|null
     */
    public function takeStashed(): ?array
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if ($backendUser === null) {
            return null;
        }

        $stashed = $backendUser->getSessionData(self::SESSION_KEY);
        if (!is_array($stashed)) {
            return null;
        }

        $backendUser->setAndSaveSessionData(self::SESSION_KEY, null);

        return $stashed;
    }

    private function stashForModule(string $token, ?string $setupLink): void
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        // setAndSaveSessionData, not setSessionData: this must survive the
        // redirect to the module in a fresh request, so it has to be written
        // to the session record immediately rather than at request shutdown.
        $backendUser?->setAndSaveSessionData(self::SESSION_KEY, [
            'token' => $token,
            'setupLink' => $setupLink,
        ]);
    }

    /**
     * Points at the agent's setup route on the monitored site, carrying this
     * hub's own address so the agent knows where to send its reports.
     */
    private function setupLink(string $siteUrl, string $token): string
    {
        /** @var NormalizedParams $normalizedParams */
        $normalizedParams = $GLOBALS['TYPO3_REQUEST']->getAttribute('normalizedParams');

        return rtrim($siteUrl, '/') . '/typo3/typovigil-agent/setup?' . http_build_query([
            'hub' => rtrim($normalizedParams->getSiteUrl(), '/'),
            'token' => $token,
        ]);
    }
}
