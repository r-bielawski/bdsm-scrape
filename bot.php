<?php

chdir(__DIR__);
require_once 'lib/Autoloader.php';
\App\Bootstrap::init();

$pages = isset($argv[1]) ? (int) $argv[1] : 3;
$pages = max(1, min(5, $pages));

$browser = new \GM\Browser\SimpleCurlBrowser('./tmp');
$browser->sleep = 0;
$browser->setCookieFileSpecific(__DIR__ . '/tmp/' . time() . '-bot.cookie', true);

$service = new \App\Services\ProfileSyncService(new \GM\BdsmPl\PortalClient($browser));
$stats = $service->sync([
    'sex' => 'kobieta',
    'orientacja' => 'sub',
    'city' => '',
    'minage' => 18,
    'maxage' => 34,
    'state' => '',
    'sponsoring' => '',
    'stancywilny' => '',
    'pozna' => 'man',
    'minwzrost' => '',
    'maxwzrost' => '',
    'minwaga' => 40,
    'maxwaga' => 60,
    'like' => '',
    'howhard' => '',
    'contact' => '',
], $pages);

echo json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
