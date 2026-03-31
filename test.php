<?php

chdir(__DIR__);
require_once 'lib/Autoloader.php';
\App\Bootstrap::init();

$browser = new \GM\Browser\SimpleCurlBrowser('./tmp');
$browser->sleep = 0;
$browser->setCookieFileSpecific(__DIR__ . '/tmp/' . time() . '-test.cookie', true);

$portal = new \GM\BdsmPl\PortalClient($browser);
$portal->login('master38@esemeski.com', 'gemini01');
$profile = $portal->fetchProfile(95324);

echo json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

