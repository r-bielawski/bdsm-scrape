<?php

namespace App;

class SchemaMigrator
{
    public static function migrate(): void
    {
        self::ensureUtf8mb4();
        self::ensureAccountColumns();
        self::ensureProfileColumns();
        self::ensureIndexes();
        self::ensureMessageAttemptTable();
        self::ensureSyncRunTable();
    }

    private static function ensureUtf8mb4(): void
    {
        $tables = ['account', 'profile', 'sent', 'received'];
        foreach ($tables as $table) {
            if (!self::tableExists($table)) {
                continue;
            }
            try {
                \R::exec(sprintf('ALTER TABLE %s CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $table));
            } catch (\Throwable $e) {
            }
        }
    }

    private static function ensureAccountColumns(): void
    {
        if (!self::columnExists('account', 'enabled')) {
            \R::exec('ALTER TABLE account ADD COLUMN enabled TINYINT(1) NOT NULL DEFAULT 0');
            if (self::columnExists('account', 'active')) {
                \R::exec('UPDATE account SET enabled = IFNULL(active, 0)');
            }
        }

        self::addColumnIfMissing('account', 'deleted_at', 'DATETIME NULL');
        self::addColumnIfMissing('account', 'updated_at', 'DATETIME NULL');
    }

    private static function ensureProfileColumns(): void
    {
        self::addColumnIfMissing('profile', 'plec', 'VARCHAR(64) NULL');
        self::addColumnIfMissing('profile', 'wojewodztwo', 'VARCHAR(128) NULL');
        self::addColumnIfMissing('profile', 'stan_cywilny', 'VARCHAR(128) NULL');
        self::addColumnIfMissing('profile', 'najbardziej_lubie', 'VARCHAR(255) NULL');
        self::addColumnIfMissing('profile', 'online_status', 'VARCHAR(255) NULL');
        self::addColumnIfMissing('profile', 'updated_at', 'DATETIME NULL');
        self::addColumnIfMissing('profile', 'waga_kg', 'SMALLINT UNSIGNED NULL');
        self::addColumnIfMissing('profile', 'wzrost_cm', 'SMALLINT UNSIGNED NULL');
        self::addColumnIfMissing('profile', 'images_json', 'MEDIUMTEXT NULL');
    }

    private static function ensureIndexes(): void
    {
        self::addIndexIfMissing('profile', 'idx_profile_profile_id', 'CREATE INDEX idx_profile_profile_id ON profile(profile_id)');
        self::addIndexIfMissing('profile', 'idx_profile_last_seen', 'CREATE INDEX idx_profile_last_seen ON profile(last_seen)');
        self::addIndexIfMissing('account', 'idx_account_enabled', 'CREATE INDEX idx_account_enabled ON account(enabled)');
        self::addIndexIfMissing('sent', 'idx_sent_account_id', 'CREATE INDEX idx_sent_account_id ON sent(account_id)');
        self::addIndexIfMissing('sent', 'idx_sent_recipient_id', 'CREATE INDEX idx_sent_recipient_id ON sent(recipient_id)');
    }

    private static function ensureMessageAttemptTable(): void
    {
        \R::exec(
            'CREATE TABLE IF NOT EXISTS message_attempt (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                account_id INT NOT NULL,
                recipient_id INT NOT NULL,
                status VARCHAR(32) NOT NULL,
                error_message VARCHAR(500) NULL,
                source VARCHAR(32) NOT NULL DEFAULT "manual",
                message_preview VARCHAR(255) NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_message_attempt_account (account_id),
                KEY idx_message_attempt_recipient (recipient_id),
                KEY idx_message_attempt_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    private static function ensureSyncRunTable(): void
    {
        \R::exec(
            'CREATE TABLE IF NOT EXISTS sync_run (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                kind VARCHAR(64) NOT NULL,
                details TEXT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_sync_run_kind (kind),
                KEY idx_sync_run_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    private static function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        if (!self::columnExists($table, $column)) {
            \R::exec(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition));
        }
    }

    private static function addIndexIfMissing(string $table, string $indexName, string $sql): void
    {
        $exists = \R::getCell(
            'SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $indexName]
        );

        if ((int) $exists === 0) {
            \R::exec($sql);
        }
    }

    private static function columnExists(string $table, string $column): bool
    {
        $exists = \R::getCell(
            'SELECT COUNT(1) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        );

        return (int) $exists > 0;
    }

    private static function tableExists(string $table): bool
    {
        $exists = \R::getCell(
            'SELECT COUNT(1) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );

        return (int) $exists > 0;
    }
}
