<?php

declare(strict_types=1);

/**
 * Verifies the configuration files parse and declare what the rest of the
 * extension expects. Catches typos that would otherwise only surface as a
 * broken backend module.
 *
 * Run: ddev exec php packages/typovigil/Tests/config-check.php
 */

$extensionDir = dirname(__DIR__);
$failed = false;

function report(bool $ok, string $message): void
{
    global $failed;
    printf("%-62s %s\n", $message, $ok ? 'OK' : 'FAIL');
    $failed = $failed || !$ok;
}

// --- backend module ----------------------------------------------------------
$modules = require $extensionDir . '/Configuration/Backend/Modules.php';
report(isset($modules['typovigil']), 'Modules.php declares the typovigil module');
report(($modules['typovigil']['parent'] ?? '') === 'site', 'module is placed under the site parent');

$actions = $modules['typovigil']['controllerActions'] ?? [];
$controller = array_key_first($actions);
report($controller !== null && class_exists($controller), 'module controller class exists');
report(in_array('index', $actions[$controller] ?? [], true), 'index action is declared');
report(in_array('show', $actions[$controller] ?? [], true), 'show action is declared');

// Every declared action needs a template, or the module dies on first click.
foreach ($actions[$controller] ?? [] as $action) {
    $template = $extensionDir . '/Resources/Private/Templates/Backend/' . ucfirst($action) . '.html';
    report(is_file($template), "template exists for action '{$action}'");
}

// --- icons -------------------------------------------------------------------
$icons = require $extensionDir . '/Configuration/Icons.php';
$iconIdentifier = $modules['typovigil']['iconIdentifier'] ?? '';
report(isset($icons[$iconIdentifier]), "icon '{$iconIdentifier}' is registered");

$iconSource = $icons[$iconIdentifier]['source'] ?? '';
$iconPath = str_replace('EXT:typovigil/', $extensionDir . '/', $iconSource);
report(is_file($iconPath), 'icon file exists on disk');

// --- middleware --------------------------------------------------------------
$middlewares = require $extensionDir . '/Configuration/RequestMiddlewares.php';
$target = $middlewares['frontend']['maidemde/typovigil/report-receiver']['target'] ?? '';
report(class_exists($target), 'report middleware class exists');

// --- language ----------------------------------------------------------------
foreach (['locallang_db.xlf', 'locallang_mod.xlf'] as $file) {
    $path = $extensionDir . '/Resources/Private/Language/' . $file;
    $xml = @simplexml_load_file($path);
    report($xml !== false, "{$file} is valid XML");
}

// Labels referenced from ext_localconf.php and Modules.php must actually exist.
$db = simplexml_load_file($extensionDir . '/Resources/Private/Language/locallang_db.xlf');
$ids = [];
foreach ($db->file->body->{'trans-unit'} as $unit) {
    $ids[] = (string)$unit['id'];
}
foreach (['task.updateCheck.title', 'task.updateCheck.description', 'tx_typovigil_project.title'] as $needed) {
    report(in_array($needed, $ids, true), "label '{$needed}' is defined");
}

exit($failed ? 1 : 0);
