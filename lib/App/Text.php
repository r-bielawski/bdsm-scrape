<?php

namespace App;

class Text
{
    private const ENCODINGS = ['UTF-8', 'ISO-8859-2', 'ASCII'];

    public static function toUtf8(string $value): string
    {
        $converted = self::convertToUtf8($value);

        $decoded = html_entity_decode($converted, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = preg_replace('/\s+/u', ' ', $decoded ?? '');

        return trim((string) $decoded);
    }

    public static function normalizeMultiline(string $value): string
    {
        $converted = self::convertToUtf8($value);

        $decoded = html_entity_decode($converted, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = str_replace(["\r\n", "\r"], "\n", $decoded);

        return trim($decoded);
    }

    private static function convertToUtf8(string $value): string
    {
        $detected = mb_detect_encoding($value, self::ENCODINGS, true);
        if ($detected === false) {
            $detected = 'ISO-8859-2';
        }

        $converted = @mb_convert_encoding($value, 'UTF-8', $detected);
        if (!is_string($converted) || $converted === '') {
            return $value;
        }

        return $converted;
    }
}
