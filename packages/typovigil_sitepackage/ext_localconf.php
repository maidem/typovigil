<?php

declare(strict_types=1);

defined('TYPO3') or die('Access denied.');

// Add default RTE configuration
$GLOBALS['TYPO3_CONF_VARS']['RTE']['Presets']['typovigil_sitepackage'] = 'EXT:typovigil_sitepackage/Configuration/RTE/Default.yaml';

// Customer-facing status view. Uncached: it renders data tied to the logged-in
// frontend user, which must never be served from a shared page cache.
\TYPO3\CMS\Extbase\Utility\ExtensionUtility::configurePlugin(
    'TypovigilSitepackage',
    'Portal',
    [\Maidemde\TypovigilSitepackage\Controller\PortalController::class => 'list,show'],
    [\Maidemde\TypovigilSitepackage\Controller\PortalController::class => 'list,show'],
    \TYPO3\CMS\Extbase\Utility\ExtensionUtility::PLUGIN_TYPE_CONTENT_ELEMENT
);
