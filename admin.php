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

$searchSyncDefaults = [
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'sync_profiles') {
            $pages = (int) ($_POST['pages'] ?? 3);
            $pages = max(1, min(5, $pages));

            $portal = new \GM\BdsmPl\PortalClient(newBrowser());
            $syncService = new \App\Services\ProfileSyncService($portal);
            $stats = $syncService->sync($searchSyncDefaults, $pages);
            $messages[] = sprintf(
                'Synchronizacja profili zakończona. IDs: %d, nowe: %d, zaktualizowane: %d.',
                $stats['fetched_ids'],
                $stats['created'],
                $stats['updated']
            );
        } elseif ($action === 'sync_sent_enabled') {
            $sentSync = new \App\Services\SentSyncService(newBrowser());
            $results = $sentSync->syncForEnabledAccounts();
            $messages[] = sprintf('Zsynchronizowano historię wysłanych wiadomości dla %d kont enabled.', count($results));
        } elseif ($action === 'account_save') {
            $id = (int) ($_POST['id'] ?? 0);
            $login = trim((string) ($_POST['login'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $proxy = trim((string) ($_POST['proxy'] ?? ''));
            $enabled = (int) ($_POST['enabled'] ?? 0) === 1 ? 1 : 0;
            $message = (string) ($_POST['message'] ?? '');
            $queryCondition = trim((string) ($_POST['query_condition'] ?? ''));

            if ($login === '') {
                throw new RuntimeException('Login konta nie może być pusty.');
            }
            if ($id <= 0 && trim($password) === '') {
                throw new RuntimeException('Hasło jest wymagane dla nowego konta.');
            }

            $acc = $id > 0 ? \R::load('account', $id) : \R::dispense('account');
            if ($id > 0 && (int) $acc->id === 0) {
                throw new RuntimeException('Konto nie istnieje.');
            }
            if (!empty($acc->deleted_at)) {
                throw new RuntimeException('Nie można edytować usuniętego konta.');
            }

            $acc->login = $login;
            if (trim($password) !== '') {
                $acc->password = $password;
            }
            $acc->proxy = $proxy;
            $acc->enabled = $enabled;
            // legacy field used in older code paths / dumps
            $acc->active = $enabled;
            $acc->message = $message;
            $acc->query_condition = $queryCondition !== '' ? $queryCondition : null;
            $acc->updated_at = date('Y-m-d H:i:s');

            \R::store($acc);
            $messages[] = $id > 0 ? 'Zapisano zmiany konta.' : 'Dodano nowe konto.';
        } elseif ($action === 'account_delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Brak ID konta.');
            }
            $acc = \R::load('account', $id);
            if ((int) $acc->id === 0) {
                throw new RuntimeException('Konto nie istnieje.');
            }
            $acc->enabled = 0;
            $acc->active = 0;
            $acc->deleted_at = date('Y-m-d H:i:s');
            $acc->updated_at = date('Y-m-d H:i:s');
            \R::store($acc);
            $messages[] = 'Konto oznaczone jako usunięte (soft-delete).';
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$editAccountId = (int) ($_GET['edit_account_id'] ?? 0);
$showDeletedAccounts = (int) ($_GET['show_deleted_accounts'] ?? 0);

$accountsAdminWhere = $showDeletedAccounts === 1 ? '1=1' : 'deleted_at IS NULL';
$accountsAdmin = \R::getAll(
    "SELECT
        id,
        login,
        proxy,
        IFNULL(enabled, IFNULL(active, 0)) AS enabled,
        IFNULL(message, '') AS message,
        query_condition,
        deleted_at,
        updated_at
     FROM account
     WHERE {$accountsAdminWhere}
     ORDER BY id ASC"
);

$editAccount = null;
if ($editAccountId > 0) {
    $bean = \R::load('account', $editAccountId);
    if ((int) $bean->id > 0 && empty($bean->deleted_at)) {
        $editAccount = [
            'id' => (int) $bean->id,
            'login' => (string) ($bean->login ?? ''),
            'proxy' => (string) ($bean->proxy ?? ''),
            'enabled' => (int) ($bean->enabled ?? ($bean->active ?? 0)),
            'message' => (string) ($bean->message ?? ''),
            'query_condition' => (string) ($bean->query_condition ?? ''),
        ];
    }
}

$dbInfo = [
    'db' => (string) \R::getCell('SELECT DATABASE()'),
    'host' => (string) \R::getCell('SELECT @@hostname'),
];
$totalProfiles = (int) \R::getCell('SELECT COUNT(1) FROM profile');
$totalAccounts = (int) \R::getCell('SELECT COUNT(1) FROM account WHERE deleted_at IS NULL');

?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Gemini BDSM Wrapper - Admin</title>
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
            padding: 20px;
        }
        h1 {
            margin: 0 0 16px;
            font-size: 28px;
        }
        .grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 14px;
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
        @media (max-width: 900px) {
            table, thead, tbody, th, td, tr {
                display: block;
            }
            thead {
                display: none;
            }
            tr {
                border: 1px solid var(--line);
                border-radius: 10px;
                margin-bottom: 10px;
                padding: 6px;
                background: #fff;
            }
            td {
                border-bottom: none;
                padding: 5px;
            }
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
        DB: <?= h($dbInfo['db']) ?>@<?= h($dbInfo['host']) ?> | profile: <?= (int) $totalProfiles ?> | konta: <?= (int) $totalAccounts ?>
    </div>

    <?php foreach ($messages as $message): ?>
        <div class="msg ok"><?= h($message) ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $error): ?>
        <div class="msg bad"><?= h($error) ?></div>
    <?php endforeach; ?>

    <div class="grid">
        <div class="card">
            <div class="row">
                <form method="post" class="row">
                    <input type="hidden" name="action" value="sync_profiles">
                    <label>Synchronizacja profili (GET search + strony):
                        <input type="number" min="1" max="5" name="pages" value="3" style="width:90px;">
                    </label>
                    <button type="submit">Synchronizuj profile</button>
                </form>
                <form method="post" class="row">
                    <input type="hidden" name="action" value="sync_sent_enabled">
                    <button type="submit" class="secondary">Synchronizuj „wysłane” dla kont enabled</button>
                </form>
            </div>
            <p class="muted">Auto-send uruchamiaj z CLI/cronem. Ręczna wysyłka jest dostępna na stronie profili.</p>
        </div>

        <div class="card">
            <div class="row" style="justify-content: space-between;">
                <div>
                    <strong>Konta</strong><br>
                    <span class="muted">Dodawanie/edycja/usuwanie (soft-delete) + domyślna treść wiadomości.</span>
                </div>
                <div class="row">
                    <?php if ($editAccount !== null): ?>
                        <a class="muted" href="?<?= h(buildQuery(['edit_account_id' => null])) ?>">Anuluj edycję</a>
                    <?php endif; ?>
                    <a class="muted" href="?<?= h(buildQuery(['show_deleted_accounts' => $showDeletedAccounts === 1 ? 0 : 1])) ?>">
                        <?= $showDeletedAccounts === 1 ? 'Ukryj usunięte' : 'Pokaż usunięte' ?>
                    </a>
                </div>
            </div>

            <form method="post" style="margin-top:10px;">
                <input type="hidden" name="action" value="account_save">
                <input type="hidden" name="id" value="<?= (int) ($editAccount['id'] ?? 0) ?>">
                <div class="row">
                    <label style="flex: 1; min-width: 240px;">Login
                        <input type="text" name="login" value="<?= h((string) ($editAccount['login'] ?? '')) ?>" placeholder="email" required style="width:100%;">
                    </label>
                    <label style="flex: 1; min-width: 240px;">Hasło <?= $editAccount !== null ? '<span class="muted">(puste = bez zmian)</span>' : '' ?>
                        <input type="password" name="password" value="" placeholder="<?= $editAccount !== null ? 'pozostaw puste aby nie zmieniać' : '' ?>" style="width:100%;">
                    </label>
                    <label style="flex: 1; min-width: 240px;">Proxy (opcjonalnie)
                        <input type="text" name="proxy" value="<?= h((string) ($editAccount['proxy'] ?? '')) ?>" placeholder="np. host:port lub login:haslo@host:port (albo puste)" style="width:100%;">
                    </label>
                    <label style="min-width: 180px;">Enabled
                        <select name="enabled" style="width:100%;">
                            <option value="1" <?= (int) ($editAccount['enabled'] ?? 0) === 1 ? 'selected' : '' ?>>Tak</option>
                            <option value="0" <?= (int) ($editAccount['enabled'] ?? 0) === 1 ? '' : 'selected' ?>>Nie</option>
                        </select>
                    </label>
                </div>
                <div class="row" style="margin-top:10px;">
                    <label style="flex: 1; min-width: 320px;">Domyślna wiadomość
                        <textarea name="message" rows="4" placeholder="Treść wiadomości" style="width:100%;"><?= h((string) ($editAccount['message'] ?? '')) ?></textarea>
                    </label>
                    <label style="flex: 1; min-width: 320px;">Query condition (opcjonalnie)
                        <textarea name="query_condition" rows="4" placeholder="Warunek/metadata (np. do przyszłego targetowania)" style="width:100%;"><?= h((string) ($editAccount['query_condition'] ?? '')) ?></textarea>
                    </label>
                </div>
                <div class="row" style="margin-top:10px;">
                    <button type="submit"><?= $editAccount !== null ? 'Zapisz zmiany' : 'Dodaj konto' ?></button>
                    <?php if ($editAccount !== null): ?>
                        <a class="muted" href="?<?= h(buildQuery(['edit_account_id' => null])) ?>">Anuluj</a>
                    <?php endif; ?>
                </div>
            </form>

            <div style="margin-top:14px; overflow:auto;">
                <table>
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Login</th>
                        <th>Status</th>
                        <th>Proxy</th>
                        <th>Domyślna wiadomość</th>
                        <th>Query condition</th>
                        <th>Updated</th>
                        <th>Akcje</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($accountsAdmin as $acc): ?>
                        <tr>
                            <td>#<?= (int) $acc['id'] ?></td>
                            <td><?= h((string) $acc['login']) ?></td>
                            <td>
                                <?php if (!empty($acc['deleted_at'])): ?>
                                    <span class="badge gray">deleted</span>
                                <?php elseif ((int) $acc['enabled'] === 1): ?>
                                    <span class="badge">enabled</span>
                                <?php else: ?>
                                    <span class="badge gray">disabled</span>
                                <?php endif; ?>
                            </td>
                            <td class="muted"><?= h((string) ($acc['proxy'] ?? '')) ?></td>
                            <td class="muted">
                                <?php
                                $msg = (string) ($acc['message'] ?? '');
                                $preview = mb_strlen($msg) > 140 ? (mb_substr($msg, 0, 140) . '...') : $msg;
                                ?>
                                <?= h($preview) ?>
                            </td>
                            <td class="muted">
                                <?php
                                $qc = (string) ($acc['query_condition'] ?? '');
                                $qcPrev = mb_strlen($qc) > 140 ? (mb_substr($qc, 0, 140) . '...') : $qc;
                                ?>
                                <?= h($qcPrev) ?>
                            </td>
                            <td class="muted"><?= h((string) ($acc['updated_at'] ?? '')) ?></td>
                            <td>
                                <?php if (empty($acc['deleted_at'])): ?>
                                    <a href="?<?= h(buildQuery(['edit_account_id' => (int) $acc['id']])) ?>">Edytuj</a>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Usunąć konto #<?= (int) $acc['id'] ?> (soft-delete)?');">
                                        <input type="hidden" name="action" value="account_delete">
                                        <input type="hidden" name="id" value="<?= (int) $acc['id'] ?>">
                                        <button type="submit" class="secondary" style="margin-left:8px;background: var(--danger);">Usuń</button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
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
</script>
</body>
</html>
