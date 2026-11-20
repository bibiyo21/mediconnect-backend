<?php

namespace App\Modules\Discovery\Services;

use Normalizer;

/**
 * Normalizes free text before search, caching and analytics (§11.5): Unicode
 * NFKC, no control characters, single spaces, at most 100 characters, and
 * lowercase for the cache key. An empty query means "browse".
 */
final class QueryNormalizer
{
    public static function text(?string $q): string
    {
        $q = (string) $q;
        if (class_exists(Normalizer::class)) {
            $q = (string) Normalizer::normalize($q, Normalizer::FORM_KC);
        }
        $q = (string) preg_replace('/[\p{C}]+/u', ' ', $q);
        $q = (string) preg_replace('/\s+/u', ' ', trim($q));

        return mb_substr($q, 0, 100);
    }

    /** Lowercased, for cache keys and analytics. */
    public static function key(?string $q): string
    {
        return mb_strtolower(self::text($q));
    }

    /** Escapes a value for an engine filter (backtick-quoted). */
    public static function filterValue(string $value): string
    {
        return '`'.str_replace('`', '', $value).'`';
    }
}
