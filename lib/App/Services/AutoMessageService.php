<?php

namespace App\Services;

use App\Proxy;
use GM\BdsmPl\PortalClient;
use GM\Browser\SimpleCurlBrowser;

class AutoMessageService
{
    private SimpleCurlBrowser $browser;

    public function __construct(SimpleCurlBrowser $browser)
    {
        $this->browser = $browser;
    }

    /**
     * Auto-sends messages using enabled accounts.
     *
     * Safety defaults:
     * - dryRun=true: no messages are sent to the portal
     * - maxPerAccount=5: at most 5 recipients per account per run
     * - maxTotal=10: global cap per run
     */
    public function run(
        int $maxPerAccount = 5,
        int $maxTotal = 10,
        bool $dryRun = true,
        int $dailyLimitPerAccount = 150,
        int $cooldownSecondsAfterError = 3600,
        int $cooldownSecondsPerRecipientAcrossAccounts = 1800,
        int $sleepMinSeconds = 3,
        int $sleepMaxSeconds = 7
    ): array {
        $maxPerAccount = max(0, min(5, $maxPerAccount));
        $maxTotal = max(0, min(200, $maxTotal));
        $dailyLimitPerAccount = max(1, min(500, $dailyLimitPerAccount));
        $cooldownSecondsAfterError = max(0, min(24 * 3600, $cooldownSecondsAfterError));
        $cooldownSecondsPerRecipientAcrossAccounts = max(0, min(12 * 3600, $cooldownSecondsPerRecipientAcrossAccounts));
        $sleepMinSeconds = max(0, min(60, $sleepMinSeconds));
        $sleepMaxSeconds = max($sleepMinSeconds, min(120, $sleepMaxSeconds));

        $accounts = \R::findAll('account', ' enabled = 1 AND deleted_at IS NULL ');
        $accounts = array_values($accounts);
        shuffle($accounts);

        $cooldownCutoff = date('Y-m-d H:i:s', time() - $cooldownSecondsPerRecipientAcrossAccounts);
        $recentSuccess = [];
        if ($cooldownSecondsPerRecipientAcrossAccounts > 0) {
            $rows = \R::getAll(
                'SELECT recipient_id, MAX(created_at) AS last_at
                 FROM message_attempt
                 WHERE status = ? AND created_at >= ?
                 GROUP BY recipient_id',
                ['success', $cooldownCutoff]
            );
            foreach ($rows as $r) {
                $recentSuccess[(int) $r['recipient_id']] = (string) ($r['last_at'] ?? '');
            }
        }

        $results = [];
        $totalAttempted = 0;
        $totalSent = 0;
        $totalSkipped = 0;
        $totalErrors = 0;

        foreach ($accounts as $account) {
            if ($maxTotal > 0 && $totalAttempted >= $maxTotal) {
                break;
            }

            $accountId = (int) $account->id;
            $login = (string) $account->login;
            $message = trim((string) $account->message);

            if ($message === '') {
                $results[] = [
                    'account_id' => $accountId,
                    'account_login' => $login,
                    'status' => 'skipped',
                    'reason' => 'Brak treści wiadomości na koncie (account.message).',
                ];
                $totalSkipped++;
                continue;
            }

            if ($cooldownSecondsAfterError > 0 && !empty($account->last_post_error)) {
                $diff = time() - strtotime((string) $account->last_post_error);
                if ($diff >= 0 && $diff < $cooldownSecondsAfterError) {
                    $results[] = [
                        'account_id' => $accountId,
                        'account_login' => $login,
                        'status' => 'skipped',
                        'reason' => sprintf('Cooldown po błędzie (%ds).', $cooldownSecondsAfterError),
                    ];
                    $totalSkipped++;
                    continue;
                }
            }

            $sentToday = (int) \R::getCell(
                'SELECT COUNT(1) FROM sent WHERE account_id = ? AND date = CURDATE()',
                [$accountId]
            );
            if ($sentToday >= $dailyLimitPerAccount) {
                $results[] = [
                    'account_id' => $accountId,
                    'account_login' => $login,
                    'status' => 'skipped',
                    'reason' => sprintf('Limit dzienny wysyłek osiągnięty (%d/%d).', $sentToday, $dailyLimitPerAccount),
                ];
                $totalSkipped++;
                continue;
            }

            // Use a fresh browser per account. Proxy settings (CURLOPT_INTERFACE/PROXY) are sticky on curl handles
            // and cannot be reliably "unset" across iterations.
            $browser = $this->newPerAccountBrowser((string) $accountId);
            $this->setProxyIfNeeded($browser, (string) $account->proxy);
            $portal = new PortalClient($browser);

            try {
                $portal->login((string) $account->login, (string) $account->password);
            } catch (\Throwable $e) {
                $this->markAccountError($account);
                $results[] = [
                    'account_id' => $accountId,
                    'account_login' => $login,
                    'status' => 'error',
                    'reason' => 'Login failed: ' . $e->getMessage(),
                ];
                $totalErrors++;
                continue;
            }

            // Refresh "sent" history (messages may have been sent outside this tool).
            try {
                $recipients = $portal->getUsersSentTo();
                foreach ($recipients as $rid) {
                    \R::exec(
                        'INSERT INTO sent(recipient_id, account_id, date) VALUES(?, ?, ?) ON DUPLICATE KEY UPDATE date = VALUES(date)',
                        [(int) $rid, $accountId, date('Y-m-d')]
                    );
                }
            } catch (\Throwable $e) {
                // Non-fatal: still attempt to send based on local DB; duplicates are prevented by UNIQUE(recipient_id, account_id).
            }

            $limit = $maxPerAccount;
            if ($maxTotal > 0) {
                $limit = min($limit, $maxTotal - $totalAttempted);
            }
            if ($limit <= 0) {
                $results[] = [
                    'account_id' => $accountId,
                    'account_login' => $login,
                    'status' => 'skipped',
                    'reason' => 'Limit run-a osiągnięty.',
                ];
                $totalSkipped++;
                continue;
            }

            // Pull more candidates than needed; cooldown checks may skip some.
            $candidateLimit = min(200, max($limit, $limit * 20));
            $recipientIds = \R::getCol(
                "SELECT p.profile_id
                 FROM profile p
                 WHERE
                    IFNULL(p.active, 1) = 1
                    AND p.wiek BETWEEN 18 AND 34
                    AND p.waga_kg BETWEEN 40 AND 60
                    AND (p.plec IS NULL OR p.plec = '' OR LOWER(p.plec) LIKE '%kobiet%')
                    AND (LOWER(p.poznam) LIKE '%mężczyzn%' OR LOWER(p.poznam) LIKE '%mezczyzn%')
                    AND (p.orientacja IS NOT NULL AND p.orientacja <> '' AND LOWER(p.orientacja) LIKE '%uleg%')
                    AND NOT EXISTS (
                        SELECT 1 FROM sent s
                        WHERE s.account_id = ? AND s.recipient_id = p.profile_id
                    )
                 ORDER BY p.profile_id DESC
                 LIMIT {$candidateLimit}",
                [$accountId]
            );

            if (count($recipientIds) === 0) {
                $results[] = [
                    'account_id' => $accountId,
                    'account_login' => $login,
                    'status' => 'ok',
                    'attempted' => 0,
                    'sent' => 0,
                    'reason' => 'Brak nowych odbiorców dla tego konta.',
                ];
                continue;
            }

            $attempted = 0;
            $sent = 0;
            $errors = 0;

            foreach ($recipientIds as $recipientId) {
                if ($maxTotal > 0 && $totalAttempted >= $maxTotal) {
                    break;
                }
                if ($attempted >= $limit) {
                    break;
                }

                $recipientId = (int) $recipientId;

                if ($cooldownSecondsPerRecipientAcrossAccounts > 0 && isset($recentSuccess[$recipientId])) {
                    $lastAt = $recentSuccess[$recipientId];
                    $diff = time() - strtotime($lastAt);
                    if ($diff >= 0 && $diff < $cooldownSecondsPerRecipientAcrossAccounts) {
                        $totalSkipped++;
                        \R::exec(
                            'INSERT INTO message_attempt(account_id, recipient_id, status, error_message, source, message_preview, created_at)
                             VALUES(?, ?, ?, ?, ?, ?, ?)',
                            [
                                $accountId,
                                $recipientId,
                                'skipped_cooldown',
                                'Recipient cooldown across accounts',
                                'auto',
                                mb_substr($message, 0, 240),
                                date('Y-m-d H:i:s'),
                            ]
                        );
                        continue;
                    }
                }

                $attempted++;
                $totalAttempted++;

                if ($dryRun) {
                    \R::exec(
                        'INSERT INTO message_attempt(account_id, recipient_id, status, error_message, source, message_preview, created_at)
                         VALUES(?, ?, ?, NULL, ?, ?, ?)',
                        [
                            $accountId,
                            $recipientId,
                            'dry_run',
                            'auto',
                            mb_substr($message, 0, 240),
                            date('Y-m-d H:i:s'),
                        ]
                    );
                    continue;
                }

                try {
                    $portal->sendMessage($recipientId, $message);

                    \R::exec(
                        'INSERT INTO sent(recipient_id, account_id, date) VALUES(?, ?, ?) ON DUPLICATE KEY UPDATE date = VALUES(date)',
                        [$recipientId, $accountId, date('Y-m-d')]
                    );
                    \R::exec(
                        'INSERT INTO message_attempt(account_id, recipient_id, status, error_message, source, message_preview, created_at)
                         VALUES(?, ?, ?, NULL, ?, ?, ?)',
                        [
                            $accountId,
                            $recipientId,
                            'success',
                            'auto',
                            mb_substr($message, 0, 240),
                            date('Y-m-d H:i:s'),
                        ]
                    );
                    $sent++;
                    $totalSent++;
                    $recentSuccess[$recipientId] = date('Y-m-d H:i:s');

                    $account->error_count = 0;
                    \R::store($account);
                } catch (\Throwable $e) {
                    $errors++;
                    $totalErrors++;
                    $this->markAccountError($account);

                    \R::exec(
                        'INSERT INTO message_attempt(account_id, recipient_id, status, error_message, source, message_preview, created_at)
                         VALUES(?, ?, ?, ?, ?, ?, ?)',
                        [
                            $accountId,
                            $recipientId,
                            'error',
                            mb_substr($e->getMessage(), 0, 480),
                            'auto',
                            mb_substr($message, 0, 240),
                            date('Y-m-d H:i:s'),
                        ]
                    );

                    // Stop sending from this account on first error (keeps blast radius small).
                    break;
                }

                if ($sleepMaxSeconds > 0) {
                    sleep(random_int($sleepMinSeconds, $sleepMaxSeconds));
                }
            }

            $results[] = [
                'account_id' => $accountId,
                'account_login' => $login,
                'status' => 'ok',
                'attempted' => $attempted,
                'sent' => $sent,
                'errors' => $errors,
                'dry_run' => $dryRun,
            ];
        }

        return [
            'dry_run' => $dryRun,
            'max_per_account' => $maxPerAccount,
            'max_total' => $maxTotal,
            'attempted_total' => $totalAttempted,
            'sent_total' => $totalSent,
            'skipped_total' => $totalSkipped,
            'errors_total' => $totalErrors,
            'accounts' => $results,
        ];
    }

    private function newPerAccountBrowser(string $suffix): SimpleCurlBrowser
    {
        $b = new SimpleCurlBrowser('./tmp');
        $b->sleep = 0;
        $b->setCookieFileSpecific('./tmp/' . time() . '-' . $suffix . '-auto.cookie', true);
        return $b;
    }

    private function setProxyIfNeeded(SimpleCurlBrowser $browser, string $proxy): void
    {
        Proxy::applyToBrowser($browser, $proxy, false);
    }

    private function markAccountError($account): void
    {
        $account->last_post_error = date('Y-m-d H:i:s');
        $account->error_count = ((int) ($account->error_count ?? 0)) + 1;
        \R::store($account);
    }
}
