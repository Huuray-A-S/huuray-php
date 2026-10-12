<?php

declare(strict_types=1);

namespace Huuray\Internal;

/**
 * Reads a `Retry-After` response header: the one a 202 from `/v4/Pdf` carries, and
 * the one a 429 from any operation carries. One parser, so both read alike.
 *
 * @internal
 */
final class RetryAfter
{
    private function __construct() {}

    /**
     * Whole seconds from a `Retry-After` value; null when absent, or not a whole number
     * of seconds, an HTTP date included. A value with as many digits as PHP_INT_MAX,
     * leading zeros aside, or more, reads as PHP_INT_MAX.
     */
    public static function seconds(?string $value): ?int
    {
        $value = $value === null ? '' : trim($value, " \t");
        if (preg_match('/^[0-9]+$/D', $value) !== 1) {
            return null;
        }

        // Counted rather than cast: PHP 8.5 warns when an (int) cast cannot represent
        // the value. Any number with fewer digits than PHP_INT_MAX fits.
        $digits = ltrim($value, '0');

        return strlen($digits) >= strlen((string) PHP_INT_MAX) ? PHP_INT_MAX : (int) $digits;
    }
}
