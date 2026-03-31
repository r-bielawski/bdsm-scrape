#!/usr/bin/env php
<?php

chdir(dirname(__DIR__));
require_once 'lib/Autoloader.php';
\App\Bootstrap::init();

$accountId = isset($argv[1]) ? (int) $argv[1] : 0;
$browser = new \GM\Browser\SimpleCurlBrowser('./tmp');
$browser->sleep = 0;
$browser->setCookieFileSpecific(__DIR__ . '/../tmp/' . time() . '-sent.cookie', true);

$service = new \App\Services\SentSyncService($browser);
if ($accountId > 0) {
    $account = \R::load('account', $accountId);
    if (!$account || (int) $account->id === 0) {
        fwrite(STDERR, "Konto {$accountId} nie istnieje.\n");
        exit(1);
    }
    $result = $service->syncForAccount($account);
    echo json_encode([
        'account_id' => $accountId,
        'stats' => $result,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

$result = $service->syncForEnabledAccounts();
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

