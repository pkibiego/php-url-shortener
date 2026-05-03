<?php

declare(strict_types=1);

/**
 * Shortener — URL validation, normalization, and short code generation.
 *
 * Validates URLs, normalizes them (adds https:// if missing),
 * generates random hex short codes, and checks for collisions.
 */
class Shortener
{
    /**
     * Validate and normalize a URL.
     *
     * - Checks the URL is non-empty
     * - Prepends https:// if no scheme is present
     * - Validates with filter_var(FILTER_VALIDATE_URL)
     * - Rejects non-HTTP(S) schemes
     *
     * @param string $url Raw URL input.
     * @return string|null Normalized URL on success, null on failure.
     */
    public static function validateAndNormalize(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        // If no scheme, prepend https://
        if (!preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $url)) {
            $url = 'https://' . $url;
        }

        // Validate URL format
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        // Reject non-HTTP(S) schemes
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        // Basic SSRF protection: reject internal/reserved IPs
        $host = parse_url($url, PHP_URL_HOST);
        if ($host !== null) {
            $ip = gethostbyname($host);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return null;
            }
        }

        return $url;
    }

    /**
     * Generate a random hex short code.
     *
     * Uses bin2hex(random_bytes(4)) to produce an 8-character hex string.
     *
     * @param int $length Number of random bytes (default 4 → 8 hex chars).
     * @return string Hex short code.
     */
    public static function generateCode(int $length = 4): string
    {
        return bin2hex(random_bytes($length));
    }

    /**
     * Generate a unique short code that does not exist in the store.
     *
     * Retries on collision up to $maxAttempts times.
     *
     * @param Store $store The store to check against.
     * @param int $maxAttempts Maximum collision retries (default 10).
     * @return string Unique short code.
     * @throws RuntimeException If unable to generate a unique code.
     */
    public static function generateUniqueCode(Store $store, int $maxAttempts = 10): string
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            $code = self::generateCode();
            if ($store->get($code) === null) {
                return $code;
            }
        }
        throw new RuntimeException('Unable to generate a unique short code after ' . $maxAttempts . ' attempts');
    }

    /**
     * Validate a custom short code.
     *
     * Rules:
     * - Alphanumeric only (a-z, A-Z, 0-9)
     * - 3 to 32 characters
     * - Must not conflict with reserved API route words
     *
     * @param string $code The custom code to validate.
     * @return string|null Trimmed code if valid, null otherwise.
     */
    public static function validateCustomCode(string $code): ?string
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        // Reserved route segments that would shadow API endpoints
        $reserved = ['api', 'shorten', 'stats', 'index', 'favicon.ico', 'robots.txt'];
        if (in_array(strtolower($code), $reserved, true)) {
            return null;
        }

        // Length check
        $len = strlen($code);
        if ($len < 3 || $len > 32) {
            return null;
        }

        // Alphanumeric only
        if (!preg_match('/^[a-zA-Z0-9]+$/', $code)) {
            return null;
        }

        return $code;
    }
}
