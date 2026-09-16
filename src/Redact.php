<?php

declare(strict_types=1);

namespace Huuray;

/**
 * Keeping bearer instruments out of logs.
 *
 * Voucher codes are bearer instruments: whoever holds the code holds the value.
 * They must never reach a log file, an error report, a CI fixture, or a bug
 * report pasted into a public issue.
 *
 * Redaction is this library's job, not the caller's. Anything this SDK prints or
 * attaches to an exception goes through here first.
 */
final class Redact
{
    /**
     * Fields that carry redeemable value and are never logged.
     *
     * Both the wire spelling (`Code`) and the mapped spelling (`code`,
     * `redeemLink`) are listed, so a redacted result object is as safe as a
     * redacted response body.
     */
    public const SECRET_FIELDS = ['Code', 'CVV', 'RedeemLink', 'code', 'cvv', 'redeemLink'];

    /** Fields carrying credentials or personal data, masked in any diagnostic output. */
    public const SENSITIVE_FIELDS = [
        'X-API-TOKEN',
        'X-API-HASH',
        'apiToken',
        'apiSecret',
        'Email',
        'email',
        'Phone',
        'phone',
    ];

    /** Replacement for a value that could be redeemed for money. */
    public const BEARER_MARKER = '[redacted: bearer value]';

    private const MAX_DEPTH = 12;

    private function __construct() {}

    /**
     * Returns a deep copy with secret and sensitive values replaced by markers.
     *
     * Understands arrays, `JsonSerializable` objects and plain objects — including
     * the result objects this SDK returns, whose public properties are read — so
     * both raw response bodies and mapped results are covered. Objects come back
     * as associative arrays.
     *
     * Use it for anything human-facing. It is deliberately lossy: a redacted
     * voucher code cannot be recovered from the output.
     *
     *     $logger->info('order complete', Redact::redact($result));
     */
    public static function redact(
        #[\SensitiveParameter]
        mixed $value,
    ): mixed {
        return self::walk($value, 0);
    }

    /**
     * `json_encode()` with redaction applied. Safe to log.
     *
     * @param int $flags Extra `json_encode` flags, e.g. `JSON_PRETTY_PRINT`.
     */
    public static function safeJson(
        #[\SensitiveParameter]
        mixed $value,
        int $flags = 0,
    ): string {
        return json_encode(
            self::redact($value),
            $flags | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }

    /**
     * Keeps just enough of a value to recognise it, never enough to use it.
     *
     * @internal
     */
    public static function maskPartial(
        #[\SensitiveParameter]
        mixed $value,
    ): string {
        if (!is_scalar($value)) {
            return '***';
        }

        $text = (string) $value;
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            // Not valid UTF-8: fall back to bytes rather than fail.
            $chars = str_split($text);
        }

        if (count($chars) <= 4) {
            return '***';
        }

        return $chars[0] . $chars[1] . '***' . $chars[count($chars) - 2] . $chars[count($chars) - 1];
    }

    private static function walk(
        #[\SensitiveParameter]
        mixed $value,
        int $depth,
    ): mixed {
        if ($depth > self::MAX_DEPTH) {
            return '[redacted: too deep]';
        }

        if ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
            if (is_object($value)) {
                $value = get_object_vars($value);
            }
        } elseif (is_object($value)) {
            // Called from this class's scope, so only public properties are read.
            $value = get_object_vars($value);
        }

        if (!is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, self::SECRET_FIELDS, true)) {
                $out[$key] = $item === null || $item === '' ? $item : self::BEARER_MARKER;
            } elseif (is_string($key) && in_array($key, self::SENSITIVE_FIELDS, true)) {
                $out[$key] = $item === null || $item === '' ? $item : self::maskPartial($item);
            } else {
                $out[$key] = self::walk($item, $depth + 1);
            }
        }

        return $out;
    }
}
