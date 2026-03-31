<?php

chdir(__DIR__);
require_once 'lib/Autoloader.php';

\App\Bootstrap::init();

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function newBrowser(): \GM\Browser\SimpleCurlBrowser
{
    $browser = new \GM\Browser\SimpleCurlBrowser('./tmp');
    $browser->sleep = 0;
    $cookiePath = __DIR__ . '/tmp/' . time() . '-' . random_int(1000, 9999) . '.cookie';
    $browser->setCookieFileSpecific($cookiePath, true);
    return $browser;
}

function buildQuery(array $overrides = []): string
{
    $merged = array_merge($_GET, $overrides);
    foreach ($merged as $k => $v) {
        if ($v === null || $v === '') {
            unset($merged[$k]);
        }
    }
    return http_build_query($merged);
}

$messages = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'send_manual') {
            $accountId = (int) ($_POST['account_id'] ?? 0);
            $recipientId = (int) ($_POST['recipient_id'] ?? 0);
            $message = trim((string) ($_POST['message'] ?? ''));

            if ($accountId <= 0 || $recipientId <= 0) {
                throw new RuntimeException('Brak poprawnego konta lub ID odbiorcy.');
            }

            $manual = new \App\Services\ManualMessageService(newBrowser());
            $result = $manual->send($accountId, $recipientId, $message);
            if ($result['status'] === 'success') {
                $messages[] = $result['reason'];
            } else {
                $errors[] = $result['reason'];
            }
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
$city = trim((string) ($_GET['city'] ?? ''));
$ageMin = (int) ($_GET['age_min'] ?? 0);
$ageMax = (int) ($_GET['age_max'] ?? 34);
$unsentFor = (int) ($_GET['unsent_for'] ?? 0);
$onlyActive = (int) ($_GET['only_active'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];

if ($q !== '') {
    if (preg_match('/^[0-9]+$/', $q)) {
        $where[] = 'p.profile_id = ?';
        $params[] = (int) $q;
    } else {
        $where[] = '(p.nick LIKE ? OR p.motto LIKE ? OR p.`desc` LIKE ? OR CAST(p.profile_id AS CHAR) LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
}
if ($city !== '') {
    $where[] = 'p.miasto LIKE ?';
    $params[] = '%' . $city . '%';
}
if ($ageMin > 0) {
    $where[] = 'p.wiek >= ?';
    $params[] = $ageMin;
}
if ($ageMax > 0 && $ageMax < 100) {
    $where[] = 'p.wiek <= ?';
    $params[] = $ageMax;
}
if ($unsentFor > 0) {
    $where[] = 'NOT EXISTS (SELECT 1 FROM sent sx WHERE sx.recipient_id = p.profile_id AND sx.account_id = ?)';
    $params[] = $unsentFor;
}
if ($onlyActive === 1) {
    $where[] = 'IFNULL(p.active, 1) = 1';
}

$whereSql = implode(' AND ', $where);

$total = (int) \R::getCell(
    "SELECT COUNT(1) FROM profile p WHERE {$whereSql}",
    $params
);
$totalAll = (int) \R::getCell('SELECT COUNT(1) FROM profile');
$dbInfo = [
    'db' => (string) \R::getCell('SELECT DATABASE()'),
    'host' => (string) \R::getCell('SELECT @@hostname'),
];
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$profileQueryParams = $params;
$profileQueryParams[] = $perPage;
$profileQueryParams[] = $offset;

$profiles = \R::getAll(
    "SELECT
        p.*,
        COUNT(DISTINCT s.account_id) AS sent_accounts_count,
        GROUP_CONCAT(
            DISTINCT CONCAT(
                a.login,
                IF(a.deleted_at IS NOT NULL, ' (deleted)', IF(a.enabled = 1, '', ' (disabled)'))
            )
            ORDER BY a.login SEPARATOR ', '
        ) AS sent_by_accounts
    FROM profile p
    LEFT JOIN sent s ON s.recipient_id = p.profile_id
    LEFT JOIN account a ON a.id = s.account_id
    WHERE {$whereSql}
    GROUP BY p.id
    ORDER BY p.profile_id DESC
    LIMIT ? OFFSET ?",
    $profileQueryParams
);

$accounts = \R::getAll(
    'SELECT id, login, IFNULL(enabled, IFNULL(active, 0)) AS enabled, IFNULL(message, "") AS message
     FROM account
     WHERE deleted_at IS NULL
     ORDER BY id ASC'
);

?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gemini BDSM Wrapper</title>
    <style>
        :root {
            --bg1: #f7f5f1;
            --bg2: #ece7de;
            --panel: #ffffff;
            --panel2: #f8fafc;
            --ink: #1f2428;
            --muted: #5f6871;
            --accent: #0ea5e9;
            --accent-rgb: 14, 165, 233;
            --danger: #b42318;
            --line: #d8dde3;
            --input-bg: #ffffff;
            --input-border: #c5cdd5;
            --btn-secondary-bg: #475467;
            --btn-secondary-ink: #ffffff;
            --link: var(--accent);
            --shadow: rgba(0,0,0,0.25);
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg1: #070b12;
                --bg2: #0b1220;
                --panel: #0f172a;
                --panel2: #0b1324;
                --ink: #e5e7eb;
                --muted: #98a2b3;
                --accent: #38bdf8;
                --accent-rgb: 56, 189, 248;
                --danger: #f97066;
                --line: #223049;
                --input-bg: #0b1324;
                --input-border: #223049;
                --btn-secondary-bg: #334155;
                --btn-secondary-ink: #e5e7eb;
                --shadow: rgba(0,0,0,0.55);
            }
        }
        :root[data-theme="light"] {
            --bg1: #f7f5f1;
            --bg2: #ece7de;
            --panel: #ffffff;
            --panel2: #f8fafc;
            --ink: #1f2428;
            --muted: #5f6871;
            --accent: #0ea5e9;
            --accent-rgb: 14, 165, 233;
            --danger: #b42318;
            --line: #d8dde3;
            --input-bg: #ffffff;
            --input-border: #c5cdd5;
            --btn-secondary-bg: #475467;
            --btn-secondary-ink: #ffffff;
            --shadow: rgba(0,0,0,0.25);
        }
        :root[data-theme="dark"] {
            --bg1: #070b12;
            --bg2: #0b1220;
            --panel: #0f172a;
            --panel2: #0b1324;
            --ink: #e5e7eb;
            --muted: #98a2b3;
            --accent: #38bdf8;
            --accent-rgb: 56, 189, 248;
            --danger: #f97066;
            --line: #223049;
            --input-bg: #0b1324;
            --input-border: #223049;
            --btn-secondary-bg: #334155;
            --btn-secondary-ink: #e5e7eb;
            --shadow: rgba(0,0,0,0.55);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, sans-serif;
            background: linear-gradient(180deg, var(--bg1) 0%, var(--bg2) 100%);
            color: var(--ink);
        }
        .wrap {
            max-width: 3200px;
            margin: 0 auto;
            padding: clamp(14px, 1.8vw, 28px);
        }
        h1 {
            margin: 0 0 16px;
            font-size: 28px;
        }
        .layout {
            display: grid;
            grid-template-columns: 1fr;
            gap: 14px;
        }
        .sidebar {
            display: grid;
            gap: 14px;
            align-content: start;
        }
        .content {
            min-width: 0;
        }
        .card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 14px;
        }
        .row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }
        .row > * {
            margin: 0;
        }
        input, select, textarea, button {
            border-radius: 8px;
            border: 1px solid var(--input-border);
            padding: 8px 10px;
            font: inherit;
        }
        input, select, textarea {
            background: var(--input-bg);
            color: var(--ink);
        }
        button {
            background: var(--accent);
            color: #fff;
            border: none;
            cursor: pointer;
        }
        button.secondary {
            background: var(--btn-secondary-bg);
            color: var(--btn-secondary-ink);
        }
        button:hover {
            filter: brightness(0.95);
        }
        .msg {
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 10px;
        }
        .ok {
            background: #ecfdf3;
            color: #067647;
            border: 1px solid #abefc6;
        }
        .bad {
            background: #fef3f2;
            color: var(--danger);
            border: 1px solid #fecdca;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        th, td {
            border-bottom: 1px solid var(--line);
            text-align: left;
            vertical-align: top;
            padding: 8px 6px;
        }
        th {
            background: var(--panel2);
            color: var(--ink);
            position: sticky;
            top: 0;
            z-index: 1;
        }
        .muted {
            color: var(--muted);
            font-size: 13px;
        }
        .badge {
            display: inline-block;
            background: #eef4ff;
            color: #3538cd;
            border-radius: 999px;
            padding: 2px 8px;
            font-size: 12px;
        }
        .badge.gray {
            background: #f2f4f7;
            color: #475467;
        }
        .kpi {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 8px;
        }
        .kpi .pill {
            border: 1px solid var(--line);
            background: var(--panel);
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 12px;
            color: var(--ink);
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
            gap: 12px;
        }
        .profile-card {
            overflow: hidden;
            padding: 0;
        }
        .profile-top {
            display: grid;
            grid-template-columns: 120px 1fr;
            gap: 12px;
            padding: 12px;
            border-bottom: 1px solid var(--line);
            background:
                radial-gradient(1200px 300px at 0% 0%, rgba(var(--accent-rgb), 0.12), transparent 60%),
                linear-gradient(180deg, var(--panel) 0%, rgba(255,255,255,0.02) 100%);
        }
        .profile-photo {
            width: 120px;
            height: 120px;
            border-radius: 12px;
            border: 1px solid #e4e7ec;
            overflow: hidden;
            background: #f2f4f7;
            display: grid;
            place-items: center;
        }
        .profile-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .profile-meta {
            min-width: 0;
        }
        .profile-meta .nick {
            font-weight: 700;
            font-size: 16px;
            line-height: 1.25;
        }
        .profile-meta .motto {
            color: var(--muted);
            font-size: 13px;
            margin-top: 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .profile-meta .facts {
            margin-top: 8px;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .profile-body {
            padding: 12px;
            display: grid;
            gap: 10px;
        }
        .desc-snippet {
            color: var(--ink);
            font-size: 13px;
            line-height: 1.35;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
            min-height: 3.9em; /* stable height even when empty-ish */
        }
        .desc-empty {
            color: var(--muted);
            font-size: 13px;
            min-height: 3.9em;
            display: grid;
            align-items: center;
        }
        .profile-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            justify-content: space-between;
        }
        .profile-actions .left {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }
        .profile-actions a {
            color: var(--link);
            text-decoration: none;
        }
        .profile-actions a:hover {
            text-decoration: underline;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            font-weight: 650;
            letter-spacing: 0.2px;
            border-radius: 10px;
            padding: 7px 10px;
            border: 1px solid transparent;
            line-height: 1.1;
            cursor: pointer;
            user-select: none;
            text-decoration: none;
            white-space: nowrap;
        }
        .btn:focus-visible {
            outline: 2px solid rgba(var(--accent-rgb), 0.55);
            outline-offset: 2px;
        }
        .btn-outline {
            background: rgba(var(--accent-rgb), 0.06);
            color: var(--ink);
            border-color: var(--input-border);
        }
        .btn-outline:hover {
            background: rgba(var(--accent-rgb), 0.12);
            border-color: rgba(var(--accent-rgb), 0.45);
        }
        .btn-primary {
            background: var(--accent);
            color: #fff;
            border-color: rgba(var(--accent-rgb), 0.50);
        }
        .btn-primary:hover {
            filter: brightness(1.03);
        }
        .btn-danger {
            background: var(--danger);
            color: #fff;
            border-color: rgba(0,0,0,0);
        }
        .btn-danger {
            background: var(--danger);
        }

        .modal {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.65);
            display: none;
            padding: 24px;
            z-index: 1000;
        }
        .modal.open {
            display: grid;
            place-items: center;
        }
        .modal-card {
            width: min(1100px, 96vw);
            max-height: 92vh;
            overflow: hidden;
            background: var(--panel);
            border-radius: 14px;
            border: 1px solid var(--line);
            box-shadow: 0 24px 60px var(--shadow);
            display: grid;
            grid-template-rows: auto 1fr auto;
        }
        .modal-head {
            padding: 12px 14px;
            border-bottom: 1px solid var(--line);
            display: flex;
            justify-content: space-between;
            gap: 10px;
            align-items: center;
        }
        .modal-title {
            min-width: 0;
        }
        .modal-title .t1 {
            font-weight: 700;
        }
        .modal-title .t2 {
            color: var(--muted);
            font-size: 13px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 700px;
        }
        .modal-body {
            padding: 14px;
            overflow: auto;
        }
        .modal-foot {
            padding: 12px 14px;
            border-top: 1px solid var(--line);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        .modal-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
        }
        .photos {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
        }
        .photos .main {
            width: 100%;
            aspect-ratio: 16/9;
            border-radius: 12px;
            border: 1px solid #e4e7ec;
            overflow: hidden;
            background: #f2f4f7;
        }
        .photos .main img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .thumbs {
            display: flex;
            gap: 8px;
            overflow: auto;
            padding-bottom: 4px;
        }
        .thumbs img {
            width: 96px;
            height: 64px;
            object-fit: cover;
            border-radius: 10px;
            border: 1px solid #e4e7ec;
            cursor: pointer;
            flex: 0 0 auto;
        }
        .thumbs img.active {
            outline: 2px solid rgba(15,118,110,0.45);
            outline-offset: 1px;
        }
        .desc-full {
            white-space: pre-wrap;
            line-height: 1.45;
            font-size: 14px;
            color: var(--ink);
        }

        @media (min-width: 1100px) {
            .layout {
                grid-template-columns: 360px 1fr;
                align-items: start;
            }
            .sidebar {
                position: sticky;
                top: 14px;
                height: calc(100vh - 28px);
                overflow: auto;
                padding-right: 6px;
            }
            .modal-grid {
                grid-template-columns: 1.1fr 0.9fr;
                align-items: start;
            }
        }
        @media (min-width: 1800px) {
            .cards {
                grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            }
            .layout {
                grid-template-columns: 380px 1fr;
            }
        }
        @media (min-width: 2300px) {
            .cards {
                grid-template-columns: repeat(auto-fill, minmax(420px, 1fr));
            }
            .layout {
                grid-template-columns: 420px 1fr;
            }
        }
        .pagination {
            display: flex;
            gap: 8px;
            margin-top: 10px;
            align-items: center;
        }
        .pagination a {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 6px;
            border: 1px solid #d0d5dd;
            text-decoration: none;
            color: var(--ink);
            background: #fff;
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="row" style="justify-content: space-between; margin-bottom: 6px;">
        <h1 style="margin: 0;">Gemini BDSM Wrapper</h1>
        <div class="row">
            <a href="index.php" class="muted" style="text-decoration:none;">Profile</a>
            <span class="muted">|</span>
            <a href="admin.php" class="muted" style="text-decoration:none;">Admin (konta/sync)</a>
            <button type="button" class="secondary" id="themeToggle" style="padding:6px 10px;">Tryb</button>
        </div>
    </div>
    <div class="muted" style="margin: -8px 0 16px;">
        DB: <?= h($dbInfo['db']) ?>@<?= h($dbInfo['host']) ?> | profile: <?= (int) $totalAll ?> | widoczne (po filtrach): <?= (int) $total ?>
    </div>

    <?php foreach ($messages as $message): ?>
        <div class="msg ok"><?= h($message) ?></div>
    <?php endforeach; ?>
	    <?php foreach ($errors as $error): ?>
	        <div class="msg bad"><?= h($error) ?></div>
	    <?php endforeach; ?>

        <div class="layout">
            <div class="sidebar">
                <div class="card">
                    <form method="get" style="display:grid; gap:10px;">
                        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Szukaj: nick/opis/id">
                        <div class="row">
                            <input type="text" name="city" value="<?= h($city) ?>" placeholder="Miasto" style="flex:1; min-width: 160px;">
                            <label class="muted">Wiek
                                <input type="number" name="age_min" value="<?= h((string) $ageMin) ?>" style="width:86px;" placeholder="od">
                            </label>
                            <label class="muted">
                                <input type="number" name="age_max" value="<?= h((string) $ageMax) ?>" style="width:86px;" placeholder="do">
                            </label>
                        </div>
                        <label class="muted">Niewysłane z konta:
                            <select name="unsent_for" style="width:100%;">
                                <option value="0">Wszystkie</option>
                                <?php foreach ($accounts as $acc): ?>
                                    <option value="<?= (int) $acc['id'] ?>" <?= $unsentFor === (int) $acc['id'] ? 'selected' : '' ?>>
                                        #<?= (int) $acc['id'] ?> <?= h($acc['login']) ?><?= (int) $acc['enabled'] === 1 ? '' : ' [disabled]' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="muted">
                            <input type="checkbox" name="only_active" value="1" <?= $onlyActive === 1 ? 'checked' : '' ?>>
                            Tylko profile aktywne
                        </label>
                        <div class="row">
                            <button type="submit">Filtruj</button>
                            <a class="muted" href="index.php" style="text-decoration:none;">Reset</a>
                        </div>
                    </form>
                    <div class="kpi">
                        <div class="pill">Wyniki: <strong><?= (int) $total ?></strong></div>
                        <div class="pill">Strona: <strong><?= (int) $page ?>/<?= (int) $totalPages ?></strong></div>
                        <div class="pill">W bazie: <strong><?= (int) $totalAll ?></strong></div>
                    </div>
                </div>
            </div>

            <div class="content">
                <div class="cards">
                    <?php foreach ($profiles as $profile): ?>
                        <?php
                        $pid = (int) $profile['profile_id'];
                        $images = [];
                        if (!empty($profile['images_json'])) {
                            $decoded = json_decode((string) $profile['images_json'], true);
                            if (is_array($decoded)) {
                                $images = array_values(array_filter(array_map('strval', $decoded)));
                            }
                        } elseif (!empty($profile['image'])) {
                            $images = [(string) $profile['image']];
                        }
                        $imagesJsonSafe = h(json_encode($images, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                        ?>
                        <div class="card profile-card"
                             data-profile-id="<?= $pid ?>"
                             data-nick="<?= h((string) ($profile['nick'] ?? '')) ?>"
                             data-motto="<?= h((string) ($profile['motto'] ?? '')) ?>"
                             data-images='<?= $imagesJsonSafe ?>'
                             data-desc="<?= h((string) ($profile['desc'] ?? '')) ?>"
                        >
                            <div class="profile-top">
                                <div class="profile-photo">
                                    <?php if (!empty($profile['image'])): ?>
                                        <img src="<?= h((string) $profile['image']) ?>" alt="photo">
                                    <?php else: ?>
                                        <span class="muted">brak zdjęcia</span>
                                    <?php endif; ?>
                                </div>
                                <div class="profile-meta">
                                    <div class="nick"><?= h((string) ($profile['nick'] ?? '')) ?></div>
                                    <div class="motto"><?= h((string) ($profile['motto'] ?? '')) ?></div>
                                    <div class="facts">
                                        <span class="badge"><?= (int) ($profile['wiek'] ?? 0) ?> lat</span>
                                        <span class="badge gray"><?= (int) ($profile['waga_kg'] ?? 0) ?> kg</span>
                                        <?php if (!empty($profile['miasto'])): ?>
                                            <span class="badge gray"><?= h((string) $profile['miasto']) ?></span>
                                        <?php endif; ?>
                                        <?php if ((int) ($profile['active'] ?? 1) === 1): ?>
                                            <span class="badge">aktywne</span>
                                        <?php else: ?>
                                            <span class="badge gray">nieaktywne</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="muted" style="margin-top:6px;">
                                        <?= h((string) ($profile['orientacja'] ?? '')) ?> | Poznam: <?= h((string) ($profile['poznam'] ?? '')) ?>
                                    </div>
                                    <div class="muted" style="margin-top:4px;">last_seen: <?= h((string) ($profile['last_seen'] ?? '')) ?></div>
                                </div>
                            </div>
                            <div class="profile-body">
                                <?php if (!empty($profile['desc'])): ?>
                                    <div class="desc-snippet"><?= h((string) $profile['desc']) ?></div>
                                <?php else: ?>
                                    <div class="desc-empty">Brak opisu</div>
                                <?php endif; ?>

                            <div class="profile-actions">
                                <div class="left">
                                        <a class="btn btn-outline" href="https://bdsm.pl/user.php?id=<?= $pid ?>" target="_blank" rel="noreferrer">Otwórz profil</a>
                                        <button type="button" class="btn btn-outline btn-desc">Opis i zdjęcia</button>
                                        <button type="button" class="btn btn-primary btn-send">Wyślij wiadomość</button>
                                    </div>
                                    <div class="muted" style="text-align:right;">
                                        <?php if (!empty($profile['sent_by_accounts'])): ?>
                                            wysłały: <?= h((string) $profile['sent_by_accounts']) ?>
                                        <?php else: ?>
                                            brak w historii sent
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?<?= h(buildQuery(['page' => $page - 1])) ?>">Poprzednia</a>
                    <?php endif; ?>
                    <span class="muted">Strona <?= (int) $page ?> / <?= (int) $totalPages ?></span>
                    <?php if ($page < $totalPages): ?>
                        <a href="?<?= h(buildQuery(['page' => $page + 1])) ?>">Następna</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
	</div>

    <div class="modal" id="profileModal" aria-hidden="true">
        <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="profileModalTitle">
            <div class="modal-head">
                <div class="modal-title">
                    <div class="t1" id="profileModalTitle">Profil</div>
                    <div class="t2" id="profileModalSubtitle"></div>
                </div>
                <button type="button" class="secondary" id="profileModalClose">Zamknij</button>
            </div>
            <div class="modal-body">
                <div class="modal-grid">
                    <div class="photos">
                        <div class="main"><img id="profileModalMainImg" alt="photo"></div>
                        <div class="thumbs" id="profileModalThumbs"></div>
                    </div>
                    <div>
                        <div class="desc-full" id="profileModalDesc"></div>
                    </div>
                </div>
            </div>
            <div class="modal-foot">
                <a id="profileModalLink" href="#" target="_blank" rel="noreferrer">Otwórz na bdsm.pl</a>
                <button type="button" class="secondary" id="profileModalClose2">Zamknij</button>
            </div>
        </div>
    </div>

    <div class="modal" id="sendModal" aria-hidden="true">
        <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="sendModalTitle">
            <div class="modal-head">
                <div class="modal-title">
                    <div class="t1" id="sendModalTitle">Wyślij wiadomość</div>
                    <div class="t2" id="sendModalSubtitle"></div>
                </div>
                <button type="button" class="secondary" id="sendModalClose">Zamknij</button>
            </div>
            <div class="modal-body">
                <form method="post" id="sendModalForm" style="display:grid; gap:10px;">
                    <input type="hidden" name="action" value="send_manual">
                    <input type="hidden" name="recipient_id" id="sendModalRecipientId" value="">
                    <label class="muted">Konto:
                        <select name="account_id" class="account-select" required style="width:100%;">
                            <option value="">Wybierz konto</option>
                            <?php foreach ($accounts as $acc): ?>
                                <option
                                    value="<?= (int) $acc['id'] ?>"
                                    data-default-message="<?= h((string) $acc['message']) ?>"
                                >
                                    #<?= (int) $acc['id'] ?> <?= h($acc['login']) ?><?= (int) $acc['enabled'] === 1 ? ' [enabled]' : ' [disabled]' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="muted">Treść:
                        <textarea name="message" rows="6" class="message-field" placeholder="Treść wiadomości" required style="width:100%;"></textarea>
                    </label>
                    <button type="submit">Wyślij</button>
                </form>
            </div>
            <div class="modal-foot">
                <a id="sendModalLink" href="#" target="_blank" rel="noreferrer">Otwórz profil na bdsm.pl</a>
                <button type="button" class="secondary" id="sendModalClose2">Zamknij</button>
            </div>
        </div>
    </div>

	<script>
    (function () {
        var root = document.documentElement;
        var key = 'gemini_bdsm_theme';
        function getSystemTheme() {
            return (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
        }
        function getEffectiveTheme() {
            return root.getAttribute('data-theme') || getSystemTheme();
        }
        function applySaved() {
            var saved = localStorage.getItem(key);
            if (saved === 'dark' || saved === 'light') {
                root.setAttribute('data-theme', saved);
            }
        }
        function updateButton(btn) {
            var t = getEffectiveTheme();
            btn.textContent = (t === 'dark') ? 'Ciemny' : 'Jasny';
            btn.setAttribute('aria-label', 'Przełącz tryb kolorów (obecnie: ' + btn.textContent + ')');
        }
        applySaved();
        var btn = document.getElementById('themeToggle');
        if (!btn) return;
        updateButton(btn);
        btn.addEventListener('click', function () {
            var current = getEffectiveTheme();
            var next = (current === 'dark') ? 'light' : 'dark';
            root.setAttribute('data-theme', next);
            localStorage.setItem(key, next);
            updateButton(btn);
        });
        if (window.matchMedia) {
            var mql = window.matchMedia('(prefers-color-scheme: dark)');
            if (mql && mql.addEventListener) {
                mql.addEventListener('change', function () {
                    if (!root.getAttribute('data-theme')) {
                        updateButton(btn);
                    }
                });
            }
        }
    })();

    function openModal(el) {
        el.classList.add('open');
        el.setAttribute('aria-hidden', 'false');
    }
    function closeModal(el) {
        el.classList.remove('open');
        el.setAttribute('aria-hidden', 'true');
    }

    function parseJsonAttr(value) {
        try { return JSON.parse(value || '[]'); } catch (e) { return []; }
    }

    var profileModal = document.getElementById('profileModal');
    var profileModalClose = document.getElementById('profileModalClose');
    var profileModalClose2 = document.getElementById('profileModalClose2');
    var profileModalSubtitle = document.getElementById('profileModalSubtitle');
    var profileModalDesc = document.getElementById('profileModalDesc');
    var profileModalLink = document.getElementById('profileModalLink');
    var profileModalMainImg = document.getElementById('profileModalMainImg');
    var profileModalThumbs = document.getElementById('profileModalThumbs');

    function setMainImg(url) {
        if (!url) {
            profileModalMainImg.removeAttribute('src');
            profileModalMainImg.style.display = 'none';
            return;
        }
        profileModalMainImg.style.display = 'block';
        profileModalMainImg.setAttribute('src', url);
    }

    document.querySelectorAll('.btn-desc').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var card = btn.closest('.profile-card');
            if (!card) return;
            var pid = card.getAttribute('data-profile-id');
            var nick = card.getAttribute('data-nick') || '';
            var motto = card.getAttribute('data-motto') || '';
            var desc = card.getAttribute('data-desc') || '';
            var images = parseJsonAttr(card.getAttribute('data-images'));

            profileModalSubtitle.textContent = '#' + pid + ' ' + nick + (motto ? (' | ' + motto) : '');
            profileModalDesc.textContent = desc || 'Brak opisu';
            profileModalLink.setAttribute('href', 'https://bdsm.pl/user.php?id=' + pid);

            profileModalThumbs.innerHTML = '';
            if (images.length > 0) {
                setMainImg(images[0]);
                images.forEach(function(url, idx) {
                    var img = document.createElement('img');
                    img.src = url;
                    if (idx === 0) img.classList.add('active');
                    img.addEventListener('click', function() {
                        setMainImg(url);
                        profileModalThumbs.querySelectorAll('img').forEach(function(i) { i.classList.remove('active'); });
                        img.classList.add('active');
                    });
                    profileModalThumbs.appendChild(img);
                });
            } else {
                setMainImg('');
            }

            openModal(profileModal);
        });
    });

    [profileModalClose, profileModalClose2].forEach(function(b){
        b.addEventListener('click', function(){ closeModal(profileModal); });
    });

    var sendModal = document.getElementById('sendModal');
    var sendModalClose = document.getElementById('sendModalClose');
    var sendModalClose2 = document.getElementById('sendModalClose2');
    var sendModalRecipientId = document.getElementById('sendModalRecipientId');
    var sendModalSubtitle = document.getElementById('sendModalSubtitle');
    var sendModalLink = document.getElementById('sendModalLink');
    var sendModalForm = document.getElementById('sendModalForm');

    document.querySelectorAll('.btn-send').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var card = btn.closest('.profile-card');
            if (!card) return;
            var pid = card.getAttribute('data-profile-id');
            var nick = card.getAttribute('data-nick') || '';
            sendModalRecipientId.value = pid;
            sendModalSubtitle.textContent = '#' + pid + ' ' + nick;
            sendModalLink.setAttribute('href', 'https://bdsm.pl/user.php?id=' + pid);

            // reset form fields (keep selection optional)
            var textarea = sendModalForm.querySelector('.message-field');
            if (textarea) textarea.value = '';
            var select = sendModalForm.querySelector('.account-select');
            if (select) select.value = '';

            openModal(sendModal);
        });
    });

    [sendModalClose, sendModalClose2].forEach(function(b){
        b.addEventListener('click', function(){ closeModal(sendModal); });
    });

    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape') {
            closeModal(profileModal);
            closeModal(sendModal);
        }
    });
    [profileModal, sendModal].forEach(function(m){
        m.addEventListener('click', function(e){
            if (e.target === m) closeModal(m);
        });
    });

	document.querySelectorAll('.account-select').forEach(function (select) {
	    select.addEventListener('change', function () {
	        var form = select.closest('form');
	        var textarea = form ? form.querySelector('.message-field') : null;
	        var selected = select.options[select.selectedIndex];
	        if (!textarea || !selected) {
	            return;
	        }
	        if (textarea.value.trim() === '') {
	            textarea.value = selected.getAttribute('data-default-message') || '';
	        }
	    });
	});
	</script>
	</body>
	</html>
