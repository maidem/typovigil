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

// Encrypts a project's database password before it is written.
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][]
    = \Maidemde\Typovigil\Hook\EncryptDatabasePassword::class;

// Routes a backup job to its own transport/queue table instead of core's
// shared default, so the backup button no longer waits on the dump inline —
// see Classes/Queue and the background worker loop in docker-entrypoint.sh.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['messenger']['routing'][\Maidemde\Typovigil\Queue\Message\BackupMessage::class] = 'backup';

// Scheduler task that matches reported packages against the upstream sources.
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][\Maidemde\Typovigil\Task\UpdateCheckTask::class] = [
    'extension' => 'typovigil',
    'title' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:task.updateCheck.title',
    'description' => 'LLL:EXT:typovigil/Resources/Private/Language/locallang_db.xlf:task.updateCheck.description',
];
