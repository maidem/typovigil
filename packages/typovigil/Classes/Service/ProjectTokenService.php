<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Service;

use Maidemde\Typovigil\Domain\Repository\ProjectRepository;

/**
 * Issues a fresh bearer token for a project.
 *
 * Shared between the "new project" hook and the "regenerate token" backend
 * action so both paths issue tokens the same way. The token is shown once —
 * in a flash message and, briefly, in the backend module — and copied by
 * hand into wherever the monitored installation sets
 * TYPOVIGIL_AGENT_TOKEN/_HUB_URL. There used to be a one-click setup link
 * instead, pointing at a route the agent extension offered for exactly
 * this; both are gone now that every monitored installation turned out to
 * run in Docker, where that route's own config/system entry would not have
 * survived the next deploy anyway.
 */
final readonly class ProjectTokenService
{
    /**
     * Session key the just-issued token is stashed under so the TypoVigil
     * module can show it with a copy button once, right after the record
     * edit form (where it is first issued) redirects back there. A backend
     * user session, not the database: the plain token is never persisted.
     */
    private const SESSION_KEY = 'typovigil_justIssuedToken';

    public function __construct(
        private ProjectRepository $projects,
        private TokenGenerator $tokenGenerator,
    ) {}

    public function issue(int $projectUid): string
    {
        $token = $this->tokenGenerator->generate();

        $this->projects->updateProject($projectUid, [
            'token_hash' => hash('sha256', $token),
        ]);

        $this->stashForModule($token);

        return $token;
    }

    /**
     * Fetches and clears the token stashed by the last issue() call in this
     * backend user's session, if any. One-time read, same as the flash
     * message: once shown in the module, it is gone.
     */
    public function takeStashed(): ?string
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if ($backendUser === null) {
            return null;
        }

        $stashed = $backendUser->getSessionData(self::SESSION_KEY);
        if (!is_string($stashed) || $stashed === '') {
            return null;
        }

        $backendUser->setAndSaveSessionData(self::SESSION_KEY, null);

        return $stashed;
    }

    private function stashForModule(string $token): void
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        // setAndSaveSessionData, not setSessionData: this must survive the
        // redirect to the module in a fresh request, so it has to be written
        // to the session record immediately rather than at request shutdown.
        $backendUser?->setAndSaveSessionData(self::SESSION_KEY, $token);
    }
}
