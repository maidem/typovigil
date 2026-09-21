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
        'coolify_application_uuid' => [
            'label' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tx_typovigil_project.coolify_application_uuid',
            'description' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tx_typovigil_project.coolify_application_uuid.description',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
            ],
        ],
        'coolify_storage_uuid' => [
            'label' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tx_typovigil_project.coolify_storage_uuid',
            'description' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tx_typovigil_project.coolify_storage_uuid.description',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
            ],
        ],
        'coolify_database_uuid' => [
            'label' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tx_typovigil_project.coolify_database_uuid',
            'description' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tx_typovigil_project.coolify_database_uuid.description',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
            ],
        ],
        'db_host' => [
            'label' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tx_typovigil_project.db_host',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
            ],
        ],
        'db_port' => [
            'label' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tx_typovigil_project.db_port',
            'config' => [
                'type' => 'number',
                'size' => 10,
                'default' => 3306,
            ],
        ],
        'db_name' => [
            'label' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tx_typovigil_project.db_name',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
            ],
        ],
        'db_user' => [
            'label' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tx_typovigil_project.db_user',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
            ],
        ],
        'db_password' => [
            'label' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tx_typovigil_project.db_password',
            'description' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tx_typovigil_project.db_password.description',
            'config' => [
                'type' => 'password',
                'size' => 40,
                // Not hashed: TypoVigil has to send this password to the
                // project's database, so it must be readable again. It is
                // encrypted instead, by EncryptDatabasePassword — without
                // this flag the DataHandler would hash it and the backup
                // would fail with "access denied" forever.
                'hashed' => false,
            ],
        ],
        'last_backup_at' => [
            'label' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tx_typovigil_project.last_backup_at',
            'config' => [
                'type' => 'datetime',
                'format' => 'datetime',
                'readOnly' => true,
            ],
        ],
        'last_backup_status' => [
            'label' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tx_typovigil_project.last_backup_status',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'readOnly' => true,
            ],
        ],
        'github_repo' => [
            'label' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tx_typovigil_project.github_repo',
            'description' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tx_typovigil_project.github_repo.description',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
            ],
        ],
        'update_requested_at' => [
            'label' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tx_typovigil_project.update_requested_at',
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
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.hidden',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
            ],
        ],
    ],
    'types' => [
        '1' => [
            // No tab for core_version / last_report_at / token_hash: they are
            // written by the agent's reports and the token-issue hook, never
            // by hand, and would otherwise look like fields someone forgot to
            // fill in. Their values already show in the module (list and
            // detail view).
            'showitem' => '
                --div--;LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tab.general,
                    title, site_url, notes, hidden,
                --div--;LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tab.access,
                    fe_users,
                --div--;LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tab.coolify,
                    coolify_application_uuid, coolify_storage_uuid, coolify_database_uuid,
                --div--;LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tab.database,
                    db_host, db_port, db_name, db_user, db_password,
                --div--;LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:tab.github,
                    github_repo, update_requested_at,
            ',
        ],
    ],
];
