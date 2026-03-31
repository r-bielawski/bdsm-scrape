#!/usr/bin/env php
<?php

chdir(dirname(__DIR__));
require_once 'lib/Autoloader.php';
\App\Bootstrap::init();

if (($argc ?? 0) < 4) {
    fwrite(STDERR, "Użycie: php scripts/send_manual.php <account_id> <recipient_id> <message>\n");
    exit(1);
}

$accountId = (int) $argv[1];
$recipientId = (int) $argv[2];
$message = (string) $argv[3];

$browser = new \GM\Browser\SimpleCurlBrowser('./tmp');
$browser->sleep = 0;
$browser->setCookieFileSpecific(__DIR__ . '/../tmp/' . time() . '-send.cookie', true);

$service = new \App\Services\ManualMessageService($browser);
$result = $service->send($accountId, $recipientId, $message);

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

