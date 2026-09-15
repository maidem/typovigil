<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tx_typovigil_project',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'default_sortby' => 'title',
        'iconfile' => 'EXT:typovigil/Resources/Public/Icons/Extension.svg',
        'searchFields' => 'title,notes',
    ],
    'columns' => [
        'title' => [
            'label' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tx_typovigil_project.title',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
                'required' => true,
            ],
        ],
        'site_url' => [
            'label' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tx_typovigil_project.site_url',
            'description' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tx_typovigil_project.site_url.description',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
            ],
        ],
        'token_hash' => [
            'label' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tx_typovigil_project.token_hash',
            'description' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tx_typovigil_project.token_hash.description',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
                'size' => 40,
            ],
        ],
        'core_version' => [
            'label' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tx_typovigil_project.core_version',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
                'size' => 20,
            ],
        ],
        'last_report_at' => [
            'label' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tx_typovigil_project.last_report_at',
            'config' => [
                'type' => 'datetime',
                'format' => 'datetime',
                'readOnly' => true,
            ],
        ],
        'fe_users' => [
            'label' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tx_typovigil_project.fe_users',
            'description' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tx_typovigil_project.fe_users.description',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectMultipleSideBySide',
                'foreign_table' => 'fe_users',
                'MM' => 'tx_typovigil_project_feuser_mm',
                'size' => 6,
                'autoSizeMax' => 12,
                'maxitems' => 999,
            ],
        ],
        'notes' => [
            'label' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tx_typovigil_project.notes',
            'config' => [
                'type' => 'text',
                'rows' => 4,
            ],
        ],
        'hidden' => [
            'label' => 'LLL:LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.hidden',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
            ],
        ],
    ],
    'types' => [
        '1' => [
            'showitem' => '
                --div--;LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.general,
                    title, site_url, notes, hidden,
                --div--;LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tab.status,
                    core_version, last_report_at, token_hash,
                --div--;LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tab.access,
                    fe_users,
            ',
        ],
    ],
];
