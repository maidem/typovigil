<?php

declare(strict_types=1);

defined('TYPO3') or die('Access denied.');

/**
 * The customer's side of the project assignment.
 *
 * Same MM table as tx_typovigil_project.fe_users, only read from the other
 * end: MM_opposite_field tells TYPO3 that this is the foreign side, so
 * uid_local/uid_foreign keep their meaning and an assignment made here shows
 * up on the project and the other way round. Without it both sides would
 * write the relation in the same direction and overwrite each other.
 */
$GLOBALS['TCA']['fe_users']['columns']['tx_typovigil_projects'] = [
    'label' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:fe_users.tx_typovigil_projects',
    'description' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:fe_users.tx_typovigil_projects.description',
    'config' => [
        'type' => 'select',
        'renderType' => 'selectMultipleSideBySide',
        'foreign_table' => 'tx_typovigil_project',
        'MM' => 'tx_typovigil_project_feuser_mm',
        'MM_opposite_field' => 'fe_users',
        'size' => 6,
        'autoSizeMax' => 12,
        'maxitems' => 999,
    ],
];

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
    'fe_users',
    '--div--;LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:fe_users.tab.typovigil,tx_typovigil_projects'
);
