<?php

namespace App\Services;

use App\Proxy;
use GM\BdsmPl\PortalClient;
use GM\Browser\SimpleCurlBrowser;

class SentSyncService
{
    private SimpleCurlBrowser $browser;

    public function __construct(SimpleCurlBrowser $browser)
    {
        $this->browser = $browser;
    }

    public function syncForAccount($account): array
    {
        Proxy::applyToBrowser($this->browser, (string) $account->proxy, true);

        $portal = new PortalClient($this->browser);
        $portal->login((string) $account->login, (string) $account->password);
        $recipients = $portal->getUsersSentTo();

        $inserted = 0;
        foreach ($recipients as $recipientId) {
            try {
                \R::exec(
                    'INSERT INTO sent(recipient_id, account_id, date) VALUES(?, ?, ?) ON DUPLICATE KEY UPDATE date = VALUES(date)',
                    [(int) $recipientId, (int) $account->id, date('Y-m-d')]
                );
                $inserted++;
            } catch (\Throwable $e) {
            }
        }

        \R::exec('INSERT INTO sync_run(kind, details, created_at) VALUES(?, ?, ?)', [
            'sent_history',
            json_encode([
                'account_id' => (int) $account->id,
                'account_login' => (string) $account->login,
                'recipient_count' => count($recipients),
            ], JSON_UNESCAPED_UNICODE),
            date('Y-m-d H:i:s'),
        ]);

        return [
            'recipient_count' => count($recipients),
            'inserted_or_updated' => $inserted,
        ];
    }

    public function syncForEnabledAccounts(): array
    {
        $accounts = \R::findAll('account', ' enabled = 1 AND deleted_at IS NULL ');
        $results = [];
        foreach ($accounts as $account) {
            $results[] = [
                'account_id' => (int) $account->id,
                'account_login' => (string) $account->login,
                'stats' => $this->syncForAccount($account),
            ];
        }

        return $results;
    }
}
