<?php

declare(strict_types=1);

defined('TYPO3') or die('Access denied.');

// configurePlugin() in ext_localconf.php wires up the controller actions, but it
// does not put the plugin into the CType list — without this the element cannot
// be placed on a page at all.
\TYPO3\CMS\Extbase\Utility\ExtensionUtility::registerPlugin(
    'TypovigilSitepackage',
    'Portal',
    'LLL:EXT:typovigil_sitepackage/Resources/Private/Language/locallang_db.xlf:plugin.portal.title',
    'content-widget-list',
    'plugins',
    'LLL:EXT:typovigil_sitepackage/Resources/Private/Language/locallang_db.xlf:plugin.portal.description',
);
