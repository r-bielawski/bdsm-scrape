<?php

namespace App\Services;

use App\Proxy;
use GM\BdsmPl\PortalClient;
use GM\Browser\SimpleCurlBrowser;

class ManualMessageService
{
    private SimpleCurlBrowser $browser;
    private SentSyncService $sentSyncService;

    public function __construct(SimpleCurlBrowser $browser)
    {
        $this->browser = $browser;
        $this->sentSyncService = new SentSyncService($browser);
    }

    public function send(int $accountId, int $recipientId, string $message): array
    {
        $account = \R::load('account', $accountId);
        if (!$account || (int) $account->id === 0) {
            throw new \InvalidArgumentException('Konto nie istnieje.');
        }
        if (trim($message) === '') {
            throw new \InvalidArgumentException('Treść wiadomości nie może być pusta.');
        }

        Proxy::applyToBrowser($this->browser, (string) $account->proxy, true);
        $portal = new PortalClient($this->browser);

        $portal->login((string) $account->login, (string) $account->password);
        $this->sentSyncService->syncForAccount($account);

        $alreadySent = (int) \R::getCell(
            'SELECT COUNT(1) FROM sent WHERE account_id = ? AND recipient_id = ?',
            [$accountId, $recipientId]
        ) > 0;

        if ($alreadySent) {
            return [
                'status' => 'skipped',
                'reason' => 'To konto ma już zarejestrowaną wysyłkę do tego profilu.',
            ];
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
                    'manual',
                    mb_substr($message, 0, 240),
                    date('Y-m-d H:i:s'),
                ]
            );

            return [
                'status' => 'success',
                'reason' => 'Wiadomość wysłana poprawnie.',
            ];
        } catch (\Throwable $e) {
            \R::exec(
                'INSERT INTO message_attempt(account_id, recipient_id, status, error_message, source, message_preview, created_at)
                 VALUES(?, ?, ?, ?, ?, ?, ?)',
                [
                    $accountId,
                    $recipientId,
                    'error',
                    mb_substr($e->getMessage(), 0, 480),
                    'manual',
                    mb_substr($message, 0, 240),
                    date('Y-m-d H:i:s'),
                ]
            );
            throw $e;
        }
    }
}
