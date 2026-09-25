<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Controller;

use Maidemde\Typovigil\Domain\Repository\ProjectRepository;
use Maidemde\Typovigil\Queue\Message\BackupMessage;
use Maidemde\Typovigil\Service\BackupBeforeUpdateService;
use Maidemde\Typovigil\Service\CustomerStorageService;
use Maidemde\Typovigil\Service\ProjectTokenService;
use Maidemde\Typovigil\Service\RequestUpdateService;
use Maidemde\Typovigil\Service\StatusReportService;
use Maidemde\Typovigil\Service\UpdateChecker;
use Maidemde\Typovigil\Service\VersionCheckService;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

final class BackendController extends ActionController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly StatusReportService $statusReport,
        private readonly VersionCheckService $versionCheck,
        private readonly ProjectRepository $projects,
        private readonly ProjectTokenService $projectTokens,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly CustomerStorageService $customerStorage,
        private readonly UpdateChecker $updateChecker,
        private readonly RequestUpdateService $requestUpdate,
        private readonly BackupBeforeUpdateService $backup,
        private readonly MessageBusInterface $messageBus,
    ) {}

    public function indexAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->assign('projects', $this->statusReport->summariesForBackend());
        $moduleTemplate->assign('sources', $this->versionCheck->sourceStatus());
        $moduleTemplate->assign('projectStoragePid', $this->projectStoragePid());
        $moduleTemplate->assign('customerStoragePid', $this->customerStorage->storagePid());
        $moduleTemplate->assign('justIssued', $this->projectTokens->takeStashed());
        $moduleTemplate->assign('customers', $this->projects->findAllCustomersWithProjects());

        return $moduleTemplate->renderResponse('Backend/Index');
    }

    /**
     * Runs the same check the scheduler task runs, on demand — for one
     * project, or for all of them when $project is left at 0 (the "check
     * all" button on the overview). Synchronous: fine for the package
     * counts this runs against today, but a growing project list will
     * eventually want this pushed into a queue instead.
     *
     * $returnTo brings the user back to wherever they triggered this from
     * (the overview list or a project's detail page) instead of always
     * landing on the index.
     */
    public function checkNowAction(int $project = 0, string $returnTo = 'index'): ResponseInterface
    {
        $checked = $this->updateChecker->run($project === 0 ? null : $project);
        $this->addPersistentFlashMessage(
            sprintf('Checked %d packages.', $checked),
            'TypoVigil',
            ContextualFeedbackSeverity::OK
        );

        return $returnTo === 'show'
            ? $this->redirect('show', null, null, ['project' => $project])
            : $this->redirect('index');
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
     * Approves one AI risk report, so it stops blocking the update button.
     *
     * The same approval the customer portal offers the agency role — here so
     * that reading the report and acting on it happen in one place, instead
     * of forcing a switch to the frontend for a single click.
     */
    public function approveAiReportAction(int $project, string $composerName): ResponseInterface
    {
        $this->projects->updatePackage($project, $composerName, '', ['ai_report_status' => 'approved']);

        return $this->redirect('show', null, null, ['project' => $project]);
    }

    /**
     * Queues the project's backup instead of running it inline — a dump can
     * take minutes, and nobody should have to stare at a frozen tab for that.
     * The background worker (`messenger:consume backup`, started alongside
     * Apache — see docker-entrypoint.sh) picks it up and writes its progress
     * to `backup_progress`, which backupStatusAction polls for.
     */
    public function backupNowAction(int $project): ResponseInterface
    {
        $this->projects->updateProject($project, ['backup_progress' => 'queued']);
        $this->messageBus->dispatch(new BackupMessage($project));

        $this->addPersistentFlashMessage(
            'Backup queued — the status below updates once it starts.',
            'TypoVigil',
            ContextualFeedbackSeverity::OK
        );

        return $this->redirect('show', null, null, ['project' => $project]);
    }

    /**
     * Polled by Show.html while a backup is in flight. Plain JSON, no Extbase
     * view: this is read by JavaScript, not rendered for a person.
     */
    public function backupStatusAction(int $project): ResponseInterface
    {
        $record = $this->projects->findByUid($project);

        return new JsonResponse([
            'progress' => $record['backup_progress'] ?? '',
            'lastBackupAt' => (int)($record['last_backup_at'] ?? 0),
            'lastBackupStatus' => (string)($record['last_backup_status'] ?? ''),
        ]);
    }

    /**
     * Asks the project's repository to open an update pull request.
     *
     * Nothing happens to the live installation here — see
     * RequestUpdateService for why the update goes through git rather than
     * straight onto the server.
     */
    public function requestUpdateAction(int $project): ResponseInterface
    {
        $result = $this->requestUpdate->run($project);

        $this->addPersistentFlashMessage(
            LocalizationUtility::translate(
                'LLL:EXT:typovigil/Resources/Private/Language/locallang.xlf:update.' . $result['key'],
                null,
                $result['arguments']
            ) ?? $result['key'],
            'TypoVigil',
            $result['requested'] ? ContextualFeedbackSeverity::OK : ContextualFeedbackSeverity::WARNING
        );

        return $this->redirect('show', null, null, ['project' => $project]);
    }

    /**
     * Issues a fresh token and setup link.
     *
     * This is also the only way to revoke a token: issuing a new one
     * overwrites the stored hash, so the old token stops matching
     * immediately. There is no separate "revoke" action — a compromised or
     * lost token is invalidated by replacing it, same as here.
     *
     * The template asks for confirmation once the agent has already
     * reported, since replacing the token then means the agent's own copy
     * (in its backend module, or its TYPOVIGIL_AGENT_TOKEN environment
     * variable) also needs to be updated, or reporting breaks until it is.
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

    private function projectStoragePid(): int
    {
        try {
            return (int)$this->extensionConfiguration->get('typovigil', 'projectStoragePid');
        } catch (\Throwable) {
            return 0;
        }
    }
}
