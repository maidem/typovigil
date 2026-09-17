<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Controller;

use Maidemde\Typovigil\Domain\Repository\ProjectRepository;
use Maidemde\Typovigil\Service\ProjectTokenService;
use Maidemde\Typovigil\Service\StatusReportService;
use Maidemde\Typovigil\Service\VersionCheckService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

final class BackendController extends ActionController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly StatusReportService $statusReport,
        private readonly VersionCheckService $versionCheck,
        private readonly ProjectRepository $projects,
        private readonly ProjectTokenService $projectTokens,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function indexAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->assign('projects', $this->statusReport->summariesForBackend());
        $moduleTemplate->assign('sources', $this->versionCheck->sourceStatus());
        $moduleTemplate->assign('projectStoragePid', $this->projectStoragePid());
        $moduleTemplate->assign('customerStoragePid', $this->customerStoragePid());
        $moduleTemplate->assign('justIssued', $this->projectTokens->takeStashed());
        $moduleTemplate->assign('customers', $this->projects->findAllCustomersWithProjects());

        return $moduleTemplate->renderResponse('Backend/Index');
    }

    public function showAction(int $project): ResponseInterface
    {
        $detail = $this->statusReport->detailForBackend($project);
        if ($detail === null) {
            return $this->redirect('index');
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->assign('project', $detail);

        return $moduleTemplate->renderResponse('Backend/Show');
    }

    /**
     * Issues a fresh token and setup link, for a project whose site URL was
     * filled in after the initial save (the token is only issued once, on
     * creation) or whose link was lost before it was ever used.
     *
     * Refuses once the agent has reported: the site is presumably already
     * configured with the current token, and replacing it would silently
     * break a working integration.
     */
    public function regenerateSetupLinkAction(int $project): ResponseInterface
    {
        $record = $this->projects->findByUid($project);

        if ($record === null) {
            $this->addPersistentFlashMessage('Project not found.', 'TypoVigil', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('index');
        }

        $siteUrl = trim((string)($record['site_url'] ?? ''));
        if ($siteUrl === '') {
            $this->addPersistentFlashMessage(
                'Fill in the site URL on this project first, then generate the setup link again.',
                'TypoVigil',
                ContextualFeedbackSeverity::WARNING
            );

            return $this->redirect('index');
        }

        if ((int)($record['last_report_at'] ?? 0) > 0) {
            $this->addPersistentFlashMessage(
                'This project already receives reports. Generating a new token would invalidate the one currently configured on the agent, breaking a working integration.',
                'TypoVigil',
                ContextualFeedbackSeverity::WARNING
            );

            return $this->redirect('index');
        }

        // issue() stashes the result in the backend user's session; indexAction
        // picks it up and shows it with a copy button after this redirect.
        $this->projectTokens->issue($project, $siteUrl);

        return $this->redirect('index');
    }

    /**
     * Not ModuleTemplate::addFlashMessage(): that queue belongs to the current
     * response and is discarded by the redirect. The default queue survives
     * across the following request, so the message still shows on the index
     * action it redirects to.
     */
    private function addPersistentFlashMessage(
        string $message,
        string $title,
        ContextualFeedbackSeverity $severity,
    ): void {
        GeneralUtility::makeInstance(FlashMessageService::class)
            ->getMessageQueueByIdentifier()
            ->addMessage(new FlashMessage($message, $title, $severity, true));
    }

    private function customerStoragePid(): int
    {
        try {
            return (int)$this->extensionConfiguration->get('typovigil', 'customerStoragePid');
        } catch (\Throwable) {
            return 0;
        }
    }

    private function projectStoragePid(): int
    {
        try {
            return (int)$this->extensionConfiguration->get('typovigil', 'projectStoragePid');
        } catch (\Throwable) {
            return 0;
        }
    }
}
