#!/usr/bin/env php
<?php

chdir(dirname(__DIR__));
require_once 'lib/Autoloader.php';
\App\Bootstrap::init();

function usage(): void
{
    fwrite(STDERR, "Użycie: php scripts/send_auto.php [--max-per-account=N] [--max-total=N] [--dry-run=0|1]\n");
    fwrite(STDERR, "  Opcje:\n");
    fwrite(STDERR, "    --max-per-account   Domyślnie 5 (max 5)\n");
    fwrite(STDERR, "    --max-total         Domyślnie 10\n");
    fwrite(STDERR, "    --dry-run           Domyślnie 1 (bez wysyłania na portal)\n");
    fwrite(STDERR, "\n");
    fwrite(STDERR, "Aby włączyć realną wysyłkę ustaw zmienną środowiskową ALLOW_AUTO_SEND=1.\n");
}

$maxPerAccount = 5;
$maxTotal = 10;
$dryRun = true;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        usage();
        exit(0);
    }
    if (preg_match('/^--max-per-account=(\\d+)$/', $arg, $m)) {
        $maxPerAccount = (int) $m[1];
        continue;
    }
    if (preg_match('/^--max-total=(\\d+)$/', $arg, $m)) {
        $maxTotal = (int) $m[1];
        continue;
    }
    if (preg_match('/^--dry-run=([01])$/', $arg, $m)) {
        $dryRun = ((int) $m[1]) === 1;
        continue;
    }

    fwrite(STDERR, "Nieznany argument: {$arg}\n\n");
    usage();
    exit(2);
}

if (!$dryRun && (string) getenv('ALLOW_AUTO_SEND') !== '1') {
    fwrite(STDERR, "Odmowa: --dry-run=0 wymaga ALLOW_AUTO_SEND=1.\n\n");
    usage();
    exit(3);
}

$browser = new \GM\Browser\SimpleCurlBrowser('./tmp');
$browser->sleep = 0;
$browser->setCookieFileSpecific(__DIR__ . '/../tmp/' . time() . '-auto-send.cookie', true);

$service = new \App\Services\AutoMessageService($browser);
$result = $service->run(
    maxPerAccount: $maxPerAccount,
    maxTotal: $maxTotal,
    dryRun: $dryRun
);

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
