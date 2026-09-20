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

    /**
     * Logged-in visitors see their own projects; everyone else sees the public
     * security overview instead of a bare "please log in" — general TYPO3
     * update/security information is useful on its own, with or without an
     * account.
     */
    public function listAction(): ResponseInterface
    {
        $userId = $this->frontendUserId();
        if ($userId === 0) {
            // Renders the Security template explicitly rather than calling
            // securityAction() as a plain method: $this->view is already bound
            // to List.html at this point, and render() is the supported way to
            // pick a different template within the same request.
            $this->assignSecurityOverview();

            return $this->htmlResponse($this->view->render('Security'));
        }

        $seesAll = $this->seesAllProjects();

        $this->view->assign('projects', $this->statusReport->summariesForFrontendUser($userId, $seesAll));
        $this->view->assign('seesAllProjects', $seesAll);

        return $this->htmlResponse();
    }

    /**
     * The public security overview, reachable from the navigation regardless
     * of login state — a logged-in customer may want it too, not only visitors
     * without an account.
     */
    public function securityAction(): ResponseInterface
    {
        $this->assignSecurityOverview();

        return $this->htmlResponse();
    }

    private function assignSecurityOverview(): void
    {
        $this->view->assign('coreVersions', $this->versionCheck->maintainedCoreVersions());
        $this->view->assign('advisories', $this->versionCheck->securityAdvisories());
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
        $seesAll = $this->seesAllProjects();
        $detail['packages'] = array_map(
            fn(array $package): array => $this->withAiReportVisibility($package, $seesAll),
            $detail['packages'] ?? []
        );

        $this->view->assign('project', $detail);
        $this->view->assign('seesAllProjects', $seesAll);

        return $this->htmlResponse();
    }

    /**
     * Strips the AI report fields for anyone but the agency role.
     *
     * Not even a "pending" placeholder is shown to a customer — that alone
     * would reveal a critical finding exists beyond what the severity
     * badge already shows. The unpacked ai_report_text field exists so the
     * template never has to json_decode ai_report_json itself.
     *
     * @param array<string, mixed> $package
     * @return array<string, mixed>
     */
    private function withAiReportVisibility(array $package, bool $seesAll): array
    {
        if (!$seesAll) {
            unset($package['ai_report_json'], $package['ai_report_status'], $package['ai_report_created_at']);

            return $package;
        }

        $decoded = json_decode((string)($package['ai_report_json'] ?? ''), true);
        $package['ai_report_text'] = is_array($decoded) ? (string)($decoded['report'] ?? '') : '';

        return $package;
    }

    /**
     * Sets a critical package's AI report to "approved" — a status flag
     * only. Does not trigger any actual update; the technical execution
     * path (hosting platform redeploy vs. a git push) is not decided yet.
     */
    public function approveAiReportAction(int $project, string $composerName): ResponseInterface
    {
        $userId = $this->frontendUserId();
        // Both checks matter: isVisibleToFrontendUser() alone would let a
        // customer approve a report on their own project — seesAllProjects()
        // is the independent, second condition that restricts this to the
        // agency, same as the report's visibility in showAction() above.
        if ($userId === 0 || !$this->seesAllProjects()) {
            return $this->accessDenied('Not allowed.');
        }

        if (!$this->projects->isVisibleToFrontendUser($project, $userId, true)) {
            return $this->accessDenied('This project is not available to your account.');
        }

        $this->projects->updatePackage($project, $composerName, '', ['ai_report_status' => 'approved']);

        return $this->redirect('show', null, null, ['project' => $project]);
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
