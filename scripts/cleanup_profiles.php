#!/usr/bin/env php
<?php

chdir(dirname(__DIR__));
require_once 'lib/Autoloader.php';
\App\Bootstrap::init();

// Hard criteria:
// - wiek: 18..34
// - waga_kg: 40..60
// - plec contains "Kobieta" (if present)
// - poznam contains "mężczyzn" (or "mezczyzn")
// - orientacja contains "Uleg" (submissive only)
$sql = "
    DELETE FROM profile
    WHERE
        (wiek IS NULL OR wiek < 18 OR wiek > 34)
        OR (waga_kg IS NULL OR waga_kg < 40 OR waga_kg > 60)
        OR (plec IS NOT NULL AND plec <> '' AND LOWER(plec) NOT LIKE '%kobiet%')
        OR (poznam IS NULL OR (LOWER(poznam) NOT LIKE '%mężczyzn%' AND LOWER(poznam) NOT LIKE '%mezczyzn%'))
        OR (orientacja IS NULL OR orientacja = '' OR LOWER(orientacja) NOT LIKE '%uleg%')
";

$before = (int) \R::getCell('SELECT COUNT(1) FROM profile');
\R::exec($sql);
$after = (int) \R::getCell('SELECT COUNT(1) FROM profile');

echo json_encode([
    'before' => $before,
    'after' => $after,
    'deleted' => $before - $after,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
