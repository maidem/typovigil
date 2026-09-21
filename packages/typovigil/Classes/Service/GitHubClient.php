<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Service;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Starts the update workflow in a monitored project's GitHub repository.
 *
 * Only the one call TypoVigil needs — a workflow_dispatch — not a general
 * GitHub wrapper. Everything about the update itself (running composer,
 * committing the lock file, opening the pull request) happens in that
 * workflow, inside the project's own repository: the hub has no checkout of
 * the projects and no business becoming a build machine for them.
 *
 * The token comes from TYPOVIGIL_GITHUB_TOKEN, not the extension
 * configuration. config/system has no persistent volume in the container
 * deploy, so a token entered in the backend module is lost on the next
 * deploy — the same trap the Eden AI credentials fell into, see
 * EdenAiClient.
 *
 * One token for every project: it works for repositories the operator owns.
 * A customer repository under someone else's account would need its own
 * token, and that belongs on the project record (encrypted), not here.
 */
final readonly class GitHubClient
{
    private const TIMEOUT = 15;
    private const API = 'https://api.github.com';

    /**
     * The workflow file each monitored repository is expected to carry. Fixed
     * rather than configurable per project: every repository gets the same
     * copied-in workflow, and a per-project name would be one more field to
     * fill in wrongly.
     */
    public const WORKFLOW = 'typovigil-update.yml';

    public function __construct(
        private RequestFactory $requestFactory,
        private LoggerInterface $logger,
    ) {}

    public function isConfigured(): bool
    {
        return $this->apiToken() !== '';
    }

    /**
     * Asks a repository to run its update workflow.
     *
     * Returns true when GitHub accepted the request (204), which means the
     * workflow was queued — not that the update succeeded or that a pull
     * request exists yet. Whatever happens afterwards is visible in the
     * repository's Actions tab, not here.
     *
     * @param string $repo "owner/repo"
     * @param list<string> $packages the findings behind this request, passed
     *        along so the pull request can name them — not an argument list
     *        for composer, see RequestUpdateService::updatablePackages()
     */
    public function dispatchUpdateWorkflow(string $repo, array $packages): bool
    {
        if (!preg_match('#^[\w.-]+/[\w.-]+$#', $repo)) {
            $this->logger->warning('TypoVigil: refusing to dispatch to a malformed repository name', [
                'repo' => $repo,
            ]);

            return false;
        }

        $token = $this->apiToken();
        if ($token === '') {
            $this->logger->warning('TypoVigil: GitHub token not configured, skipping dispatch');

            return false;
        }

        $url = sprintf('%s/repos/%s/actions/workflows/%s/dispatches', self::API, $repo, self::WORKFLOW);

        try {
            $response = $this->requestFactory->request($url, 'POST', [
                'timeout' => self::TIMEOUT,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                    // GitHub rejects requests without one.
                    'User-Agent' => 'TypoVigil',
                ],
                'json' => [
                    'ref' => 'main',
                    'inputs' => [
                        // A workflow_dispatch input has to be a string.
                        // Truncated because GitHub caps an input at 1024
                        // characters and a large project can exceed that —
                        // this only feeds the pull request description.
                        'findings' => mb_substr(implode(' ', $packages), 0, 900),
                    ],
                ],
            ]);

            $status = $response->getStatusCode();
            if ($status === 204) {
                return true;
            }

            $this->logger->warning('TypoVigil: GitHub refused the workflow dispatch', [
                'repo' => $repo,
                'status' => $status,
                // 404 here usually means the workflow file is missing or the
                // token cannot see the repository — GitHub does not
                // distinguish the two, on purpose.
                'body' => substr((string)$response->getBody(), 0, 500),
            ]);

            return false;
        } catch (\Throwable $e) {
            $this->logger->warning('TypoVigil: GitHub dispatch failed', [
                'repo' => $repo,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function apiToken(): string
    {
        $token = getenv('TYPOVIGIL_GITHUB_TOKEN');

        return $token === false ? '' : trim($token);
    }
}
