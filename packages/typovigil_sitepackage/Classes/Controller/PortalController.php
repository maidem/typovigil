<?php

declare(strict_types=1);

namespace Maidemde\TypovigilSitepackage\Controller;

use Maidemde\Typovigil\Domain\Repository\ProjectRepository;
use Maidemde\Typovigil\Service\StatusReportService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\PropagateResponseException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Frontend\Controller\ErrorController;

/**
 * Customer-facing status view.
 *
 * Shows only what the logged-in frontend user has been granted, and deliberately
 * shows less than the backend module: counts rather than the full package list.
 */
final class PortalController extends ActionController
{
    public function __construct(
        private readonly StatusReportService $statusReport,
        private readonly ProjectRepository $projects,
    ) {}

    public function listAction(): ResponseInterface
    {
        $userId = $this->frontendUserId();
        if ($userId === 0) {
            return $this->accessDenied('Please log in to see your projects.');
        }

        $this->view->assign('projects', $this->statusReport->summariesForFrontendUser($userId));

        return $this->htmlResponse();
    }

    public function showAction(int $project): ResponseInterface
    {
        $userId = $this->frontendUserId();
        if ($userId === 0) {
            return $this->accessDenied('Please log in to see your projects.');
        }

        // Checked here, not only through page access rights: the uid arrives as a
        // request argument, so without this a logged-in customer could read any
        // project by editing the URL.
        if (!$this->projects->isVisibleToFrontendUser($project, $userId)) {
            return $this->accessDenied('This project is not available to your account.');
        }

        $detail = $this->statusReport->detailForBackend($project);
        if ($detail === null) {
            return $this->accessDenied('This project is not available to your account.');
        }

        // The package list stays out of the customer view on purpose: a complete
        // inventory of vulnerable versions is an attack plan once an account is
        // compromised. Counts convey the urgency without handing that over.
        unset($detail['packages']);

        $this->view->assign('project', $detail);

        return $this->htmlResponse();
    }

    private function frontendUserId(): int
    {
        $user = $this->request->getAttribute('frontend.user');

        return (int)($user?->user['uid'] ?? 0);
    }

    private function accessDenied(string $message): never
    {
        $response = GeneralUtility::makeInstance(ErrorController::class)
            ->accessDeniedAction($this->request, $message);

        throw new PropagateResponseException($response, 1757770000);
    }
}
