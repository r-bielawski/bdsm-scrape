#!/usr/bin/env php
<?php

chdir(dirname(__DIR__));
require_once 'lib/Autoloader.php';
\App\Bootstrap::init();

$pages = isset($argv[1]) ? (int) $argv[1] : 5;
$pages = max(1, min(10, $pages));

$browser = new \GM\Browser\SimpleCurlBrowser('./tmp');
$browser->sleep = 0;
$browser->setCookieFileSpecific(__DIR__ . '/../tmp/' . time() . '-debug-search.cookie', true);

$search = [
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
];

$browser->navigatePost('https://bdsm.pl/search.php', $search);
$html = \App\Text::normalizeMultiline((string) $browser->getContents());
file_put_contents(__DIR__ . '/../tmp/last_search.html', $html);

$m = [];
preg_match_all('/user\\.php\\?id=([0-9]+)/iu', $html, $m);
$ids1 = array_values(array_unique(array_map('intval', $m[1] ?? [])));

preg_match_all('/<a[^>]+href=([^\\s>]+)[^>]*>/iu', $html, $m2);
$links = [];
foreach (($m2[1] ?? []) as $href) {
    $href = trim($href, '\'"');
    if ($href === '') continue;
    if (stripos($href, 'search.php') === false) continue;
    $links[] = $href;
}
$links = array_values(array_unique($links));

echo json_encode([
    'page1_ids' => count($ids1),
    'sample_ids' => array_slice($ids1, 0, 20),
    'search_links' => array_slice($links, 0, 40),
    'saved_html' => 'tmp/last_search.html',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

$query = http_build_query(array_merge(['s' => 1], $search));
$browser->navigate('https://bdsm.pl/search.php?' . $query);
$htmlGet = \App\Text::normalizeMultiline((string) $browser->getContents());
file_put_contents(__DIR__ . '/../tmp/last_search_get.html', $htmlGet);
$mg = [];
preg_match_all('/user\\.php\\?id=([0-9]+)/iu', $htmlGet, $mg);
$idsGet = array_values(array_unique(array_map('intval', $mg[1] ?? [])));
$hasSelectedSub = (bool) preg_match('/<option\\s+value=sub\\s+selected/iu', $htmlGet);
echo json_encode([
    'get_page1_ids' => count($idsGet),
    'get_has_selected_sub' => $hasSelectedSub,
    'saved_html_get' => 'tmp/last_search_get.html',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

$portal = new \GM\BdsmPl\PortalClient($browser);
$all = $portal->searchProfileIds($search, $pages);
echo json_encode([
    'requested_pages' => $pages,
    'total_ids' => count($all),
    'first_ids' => array_slice($all, 0, 40),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
