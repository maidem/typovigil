<?php

declare(strict_types=1);

use Maidemde\Typovigil\Task\UpdateCheckTask;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

// Native task type (replaces the deprecated SC_OPTIONS registration). No own fields.
ExtensionManagementUtility::addRecordType(
    [
        'label' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:task.updateCheck.title',
        'description' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:task.updateCheck.description',
        'value' => UpdateCheckTask::class,
        'icon' => 'mimetypes-x-tx_scheduler_task_group',
        'group' => 'typovigil',
    ],
    '
        --div--;core.form.tabs:general,
            tasktype,
            task_group,
            description,
        --div--;core.form.tabs:timing,
            --palette--;;execution,
        --div--;core.form.tabs:access,
            disable,
        --div--;core.form.tabs:extended,',
    [],
    '',
    'tx_scheduler_task'
);
