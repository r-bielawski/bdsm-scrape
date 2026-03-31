<?php

namespace App;

use GM\Browser\CurlProxy;
use GM\Browser\SimpleCurlBrowser;

final class Proxy
{
    public static function applyToBrowser(SimpleCurlBrowser $browser, string $proxy, bool $clearStickyOptions = true): void
    {
        $proxy = trim($proxy);

        if ($clearStickyOptions) {
            // Curl options are sticky on a shared browser handle; clear before setting.
            $browser->setCurlOption(CURLOPT_INTERFACE, null);
            $browser->setCurlOption(CURLOPT_PROXY, null);
            $browser->setCurlOption(CURLOPT_PROXYPORT, null);
            $browser->setCurlOption(CURLOPT_PROXYUSERPWD, null);
        }

        if ($proxy === '') {
            return;
        }

        // In Docker, ip-only "proxy" is treated as CURLOPT_INTERFACE and often fails with errno 99 (no such iface).
        if (self::isDocker() && self::isRawIp($proxy)) {
            return;
        }

        $browser->setCurlProxy(new CurlProxy($proxy));
    }

    private static function isRawIp(string $value): bool
    {
        return (bool) preg_match('/^\\d{1,3}(?:\\.\\d{1,3}){3}$/', $value);
    }

    private static function isDocker(): bool
    {
        if (getenv('RUNNING_IN_DOCKER') === '1') {
            return true;
        }

        return is_file('/.dockerenv');
    }
}

