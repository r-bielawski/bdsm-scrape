<?php

namespace App;

class Bootstrap
{
    private static bool $bootstrapped = false;

    public static function init(): void
    {
        if (self::$bootstrapped) {
            return;
        }

        self::$bootstrapped = true;

        if (PHP_SAPI !== 'cli') {
            header('Content-Type: text/html; charset=UTF-8');
            header('Referrer-Policy: no-referrer');
        }

        mb_internal_encoding('UTF-8');
        date_default_timezone_set('Europe/Warsaw');
        error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

        require_once dirname(__DIR__) . '/R.php';
        SchemaMigrator::migrate();
    }
}
