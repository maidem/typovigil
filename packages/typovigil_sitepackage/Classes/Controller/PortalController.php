<?php

declare(strict_types=1);

namespace Maidemde\TypovigilSitepackage\Controller;

use Maidemde\Typovigil\Domain\Repository\ProjectRepository;
use Maidemde\Typovigil\Service\StatusReportService;
use Maidemde\Typovigil\Service\VersionCheckService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
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
        private readonly Context $context,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly VersionCheckService $versionCheck,
    ) {}

    public function listAction(): ResponseInterface
    {
        $userId = $this->frontendUserId();
        if ($userId === 0) {
            return $this->accessDenied('Please log in to see your projects.');
        }

        $seesAll = $this->seesAllProjects();

        $this->view->assign('projects', $this->statusReport->summariesForFrontendUser($userId, $seesAll));
        $this->view->assign('seesAllProjects', $seesAll);
        $this->view->assign('sources', $this->versionCheck->sourceStatus());

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
        if (!$this->projects->isVisibleToFrontendUser($project, $userId, $this->seesAllProjects())) {
            return $this->accessDenied('This project is not available to your account.');
        }

        $detail = $this->statusReport->detailForBackend($project);
        if ($detail === null) {
            return $this->accessDenied('This project is not available to your account.');
        }

        // The package list used to be stripped here, on the grounds that a full
        // inventory of vulnerable versions helps an attacker who has taken over a
        // customer account. Showing it is a deliberate decision by the operator,
        // not an oversight — the trade-off is that this page now names the exact
        // version of every component, including the vulnerable ones.
        $this->view->assign('project', $detail);
        $this->view->assign('sources', $this->versionCheck->sourceStatus());

        return $this->htmlResponse();
    }

    private function frontendUserId(): int
    {
        $user = $this->request->getAttribute('frontend.user');

        return (int)($user?->user['uid'] ?? 0);
    }

    /**
     * Whether the logged-in user holds the agency role, which sees every project
     * instead of only the assigned ones.
     *
     * The role is configured as a group uid rather than a title: a title can be
     * renamed in the backend, and a renamed title would silently hand every
     * project to whoever holds the group. The groups come from the Context,
     * which has already resolved subgroups — reading fe_users.usergroup directly
     * would miss inherited membership.
     */
    private function seesAllProjects(): bool
    {
        $groupId = (int)($this->extensionConfiguration->get('typovigil', 'agencyFeGroupId') ?: 0);
        if ($groupId <= 0) {
            return false;
        }

        $groups = $this->context->getPropertyFromAspect('frontend.user', 'groupIds', []);

        return in_array($groupId, array_map('intval', $groups), true);
    }

    private function accessDenied(string $message): never
    {
        $response = GeneralUtility::makeInstance(ErrorController::class)
            ->accessDeniedAction($this->request, $message);

        throw new PropagateResponseException($response, 1757770000);
    }
}
