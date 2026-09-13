<?php

declare(strict_types=1);

defined('TYPO3') or die('Access denied.');

// Cache for upstream API answers, so the scheduler task does not re-query
// Packagist and get.typo3.org on every run.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['typovigil'] ??= [
    'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
    'backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
    'options' => [
        'defaultLifetime' => 3600,
    ],
];

// Issues the bearer token when a project record is created.
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][]
    = \Maidemde\Typovigil\Hook\GenerateProjectToken::class;
