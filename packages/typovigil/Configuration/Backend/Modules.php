<?php

declare(strict_types=1);

use Maidemde\Typovigil\Controller\BackendController;

return [
    'typovigil' => [
        'parent' => 'site',
        'position' => ['after' => 'site_configuration'],
        // admin only: the actions here issue tokens, run backups and open update PRs for every project
        'access' => 'admin',
        'workspaces' => 'live',
        'iconIdentifier' => 'typovigil-module',
        'path' => '/module/site/typovigil',
        'labels' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_mod.xlf',
        'extensionName' => 'Typovigil',
        'controllerActions' => [
            BackendController::class => [
                'index',
                'show',
                'regenerateToken',
                'checkNow',
                'requestUpdate',
                'approveAiReport',
                'backupNow',
                'backupStatus',
            ],
        ],
    ],
];
