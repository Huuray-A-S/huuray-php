<?php

declare(strict_types=1);

namespace Huuray\Internal;

/**
 * Helpers for building request bodies and reading decoded response bodies.
 *
 * Request side: {@see self::object()} drops nulls so an optional parameter that
 * was not supplied is never serialised — the spec-conformance gates assert the
 * SDK sends nothing it was not given.
 *
 * Response side: every reader tolerates a missing key, a null, or a value of the
 * wrong JSON type, and returns null rather than guessing. The mapping code then
 * applies the same defaults the reference clients apply.
 *
 * @internal
 */
final class Wire
{
    private function __construct() {}

    /**
     * A JSON object with every null entry removed.
     *
     * Returned as a `stdClass`, not an array, so an object with no entries still
     * encodes as `{}` rather than `[]`.
     *
     * @param array<string, mixed> $values
     */
    public static function object(
        #[\SensitiveParameter]
        array $values,
    ): \stdClass {
        return (object) array_filter($values, static fn(mixed $value): bool => $value !== null);
    }

    /**
     * Formats a value for the specification's `date-time` fields.
     *
     * A `DateTimeInterface` is converted to UTC and formatted like JavaScript's
     * `toISOString()` — `2026-09-01T09:00:00.000Z`. Strings pass through untouched,
     * so a caller with a preformatted timestamp is never second-guessed.
     */
    public static function dateTime(\DateTimeInterface|string|null $value): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }

        return \DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Rejects an amount that is not an integer number of minor units.
     *
     * A plain `int` parameter is not enough in PHP: when the *caller's* file does
     * not declare strict_types, PHP coerces the argument to the declared native
     * type before the SDK sees it — 50.00 becomes 50 (50.5 too, with only a
     * deprecation notice), and `true` becomes 1 — so a major-unit amount would be
     * ordered at 1/100th of its value without any error. A native union such as
     * `int|float|bool` would stop the coercion, but static analysers then widen the
     * PHPDoc `int` back to that union. Amount parameters are therefore declared
     * natively as `mixed` with a PHPDoc type of `int`, and anything but an int is
     * rejected here — every float, including 50.00, which PHP, unlike JavaScript,
     * can tell apart from the integer 50, and every bool.
     *
     * @throws \InvalidArgumentException for anything but an int
     */
    public static function requireMinorUnits(mixed $value, string $label = 'value'): int
    {
        if (is_float($value)) {
            throw new \InvalidArgumentException(sprintf(
                '%s must be an integer in minor units (50.00 is 5000), received the float %s. '
                . 'A float always means major units were passed by mistake — which would order '
                . '1/100th of the intended amount. PHP tells 50.00 (a float) apart from 50 (an int), '
                . 'so this guard catches that mixup too; the one it cannot catch is a whole-number '
                . 'int such as 50, which is a valid order for 0.50. Always write amounts as '
                . 'integers in minor units.',
                $label,
                var_export($value, true),
            ));
        }
        if (!is_int($value)) {
            throw new \InvalidArgumentException(sprintf(
                '%s must be an integer in minor units (50.00 is 5000), received %s. Only an int is accepted; '
                . 'nothing is coerced into an amount.',
                $label,
                self::describe($value),
            ));
        }

        return $value;
    }

    /**
     * Rejects a count that is not a positive integer.
     *
     * The same coercion as {@see self::requireMinorUnits()}: without strict_types in
     * the caller, an `int` parameter turns 1.5 into 1 and `true` into 1, so
     * `quantity: $budget / $denomination` would silently order fewer codes. Count
     * parameters are declared natively as `mixed` with a PHPDoc type of `int` for
     * that reason, and anything but an int is rejected here — including 2.0.
     *
     * @throws \InvalidArgumentException for anything but an int, or an int below 1
     */
    public static function requirePositiveInt(mixed $value, string $label): int
    {
        if (!is_int($value)) {
            throw new \InvalidArgumentException(sprintf(
                '%s must be a positive integer, received %s. It is never truncated or coerced: '
                . 'a fractional count would otherwise silently order fewer codes than intended.',
                $label,
                self::describe($value),
            ));
        }
        if ($value < 1) {
            throw new \InvalidArgumentException(sprintf('%s must be a positive integer, received %d.', $label, $value));
        }

        return $value;
    }

    /** The raw value at `$key`, or null when `$data` is not an object or lacks the key. */
    public static function field(
        #[\SensitiveParameter]
        mixed $data,
        string $key,
    ): mixed {
        return is_array($data) && array_key_exists($key, $data) ? $data[$key] : null;
    }

    public static function string(
        #[\SensitiveParameter]
        mixed $data,
        string $key,
    ): ?string {
        $value = self::field($data, $key);

        return is_string($value) ? $value : null;
    }

    /**
     * An integer field.
     *
     * A whole-number float such as `5000.0` is accepted as the integer it
     * represents: JSON does not distinguish the two, and neither does the
     * JavaScript reference client.
     */
    public static function int(
        #[\SensitiveParameter]
        mixed $data,
        string $key,
    ): ?int {
        $value = self::field($data, $key);
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) <= 9007199254740992.0) {
            return (int) $value;
        }

        return null;
    }

    /** A numeric field, passed through exactly as decoded. */
    public static function number(
        #[\SensitiveParameter]
        mixed $data,
        string $key,
    ): int|float|null {
        $value = self::field($data, $key);

        return is_int($value) || is_float($value) ? $value : null;
    }

    public static function bool(
        #[\SensitiveParameter]
        mixed $data,
        string $key,
    ): ?bool {
        $value = self::field($data, $key);

        return is_bool($value) ? $value : null;
    }

    /**
     * The rows of a list field. Null or absent means no rows.
     *
     * A row that is not a JSON object is kept as an empty row rather than
     * dropped, so the number of results always matches what the API sent.
     *
     * @return list<array<mixed>>
     */
    public static function rows(
        #[\SensitiveParameter]
        mixed $data,
        string $key,
    ): array {
        $value = self::field($data, $key);
        if (!is_array($value)) {
            return [];
        }

        $rows = [];
        foreach ($value as $row) {
            $rows[] = is_array($row) ? $row : [];
        }

        return $rows;
    }

    /** A rejected argument, for an error message: the value for a scalar, the type otherwise. */
    private static function describe(mixed $value): string
    {
        return is_scalar($value)
            ? sprintf('the %s %s', get_debug_type($value), var_export($value, true))
            : get_debug_type($value);
    }
}
