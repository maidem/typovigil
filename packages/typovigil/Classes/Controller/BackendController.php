<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Controller;

use Maidemde\Typovigil\Service\StatusReportService;
use Maidemde\Typovigil\Service\VersionCheckService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

final class BackendController extends ActionController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly StatusReportService $statusReport,
        private readonly VersionCheckService $versionCheck,
    ) {}

    public function indexAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->assign('projects', $this->statusReport->summariesForBackend());
        $moduleTemplate->assign('sources', $this->versionCheck->sourceStatus());

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
}
