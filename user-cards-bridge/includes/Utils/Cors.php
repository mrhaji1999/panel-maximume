<?php

namespace UCB\Utils;

/**
 * Helper utilities for working with CORS configuration.
 */
class Cors {
    /**
     * Normalize an origin string so that comparisons are consistent.
     *
     * @param string|null $origin Raw origin value provided by the user or browser.
     */
    public static function normalize_origin(?string $origin): string {
        if (empty($origin)) {
            return '';
        }

        $origin = trim($origin);

        if ('*' === $origin) {
            return '*';
        }

        $parsed = \wp_parse_url($origin);

        if (false === $parsed || empty($parsed['scheme']) || empty($parsed['host'])) {
            return '';
        }

        $scheme = strtolower($parsed['scheme']);
        $host = strtolower($parsed['host']);
        $port = isset($parsed['port']) ? ':' . (int) $parsed['port'] : '';

        return \esc_url_raw(sprintf('%s://%s%s', $scheme, $host, $port));
    }

    /**
     * Sanitize a list of origins and remove empty/duplicate entries.
     *
     * @param array<int, string> $origins Raw origins provided by a settings form or option value.
     *
     * @return array<int, string>
     */
    public static function sanitize_origins(array $origins): array {
        $normalized = array_map([self::class, 'normalize_origin'], $origins);

        $filtered = array_filter($normalized, static function ($origin) {
            return '' !== $origin;
        });

        return array_values(array_unique($filtered));
    }
}
