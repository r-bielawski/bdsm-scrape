<?php

chdir(__DIR__);
require_once 'lib/Autoloader.php';
\App\Bootstrap::init();

$browser = new \GM\Browser\SimpleCurlBrowser('./tmp');
$browser->sleep = 0;
$browser->setCookieFileSpecific(__DIR__ . '/tmp/' . time() . '-poster.cookie', true);

$service = new \App\Services\SentSyncService($browser);
$result = $service->syncForEnabledAccounts();

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

