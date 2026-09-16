#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Re-downloads the live v4 specification over the vendored copy.
 *
 *     composer spec-fetch
 *
 * Run by .github/workflows/spec-drift.yml on a schedule. If the download differs
 * from the committed copy, the workflow runs the conformance gates against it
 * and opens a pull request — that PR is the early warning that the API changed
 * under us.
 *
 * The file is written with exactly the bytes the Node client's fetch script
 * writes — JavaScript's JSON.stringify(spec, null, 2) plus a trailing newline:
 * two-space indentation, unescaped slashes and unicode, and JavaScript's
 * property order and number formatting. So every Huuray client vendors a
 * byte-identical copy, and a drift diff is never just formatting.
 *
 * Standalone on purpose: needs ext-curl and ext-json, not the Composer autoloader.
 * Exits 0 whether or not anything changed; the workflow diffs the working tree.
 */

// Shortest round-trip float representations, whatever php.ini says.
ini_set('serialize_precision', '-1');

const HUURAY_DEFAULT_SPEC_URL = 'https://api.huuray.com/swagger/v4/swagger.json';

/**
 * @param non-empty-string $url
 *
 * @return array{int, string} The HTTP status and the response body.
 */
function huuray_fetch(string $url): array
{
    $handle = curl_init();
    if ($handle === false) {
        throw new RuntimeException('curl_init() failed.');
    }

    curl_setopt_array($handle, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_CONNECTTIMEOUT_MS => 30_000,
        CURLOPT_TIMEOUT_MS => 60_000,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'huuray-php-spec-fetch',
    ]);

    $body = curl_exec($handle);
    if (!is_string($body)) {
        throw new RuntimeException(sprintf('cURL error %d: %s', curl_errno($handle), curl_error($handle)));
    }

    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

    return [is_int($status) ? $status : 0, $body];
}

/**
 * Serialises a value decoded with json_decode($json, false) exactly as
 * JavaScript's JSON.stringify(value, null, 2) would.
 */
function huuray_encode_like_javascript(mixed $value, string $indent = ''): string
{
    if ($value === null) {
        return 'null';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_int($value)) {
        // JavaScript numbers are doubles: beyond 2^53 an integer prints as the double it became.
        return $value >= -9007199254740991 && $value <= 9007199254740991
            ? (string) $value
            : huuray_javascript_number((float) $value);
    }
    if (is_float($value)) {
        return huuray_javascript_number($value);
    }
    if (is_string($value)) {
        return huuray_javascript_string($value);
    }

    $inner = $indent . '  ';

    if (is_array($value)) {
        if ($value === []) {
            return '[]';
        }
        $items = [];
        foreach ($value as $item) {
            $items[] = $inner . huuray_encode_like_javascript($item, $inner);
        }

        return "[\n" . implode(",\n", $items) . "\n" . $indent . ']';
    }

    if ($value instanceof stdClass) {
        $members = [];
        foreach (huuray_javascript_property_order($value) as [$key, $item]) {
            $members[] = $inner . huuray_javascript_string($key) . ': ' . huuray_encode_like_javascript($item, $inner);
        }
        if ($members === []) {
            return '{}';
        }

        return "{\n" . implode(",\n", $members) . "\n" . $indent . '}';
    }

    throw new RuntimeException('Unexpected value in the decoded spec: ' . get_debug_type($value));
}

/**
 * A string as JSON.stringify writes it: only quotes, backslashes and control
 * characters escaped (lower-case \u00xx); slashes, unicode and U+2028/U+2029 left alone.
 */
function huuray_javascript_string(string $value): string
{
    return json_encode(
        $value,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS,
    );
}

/**
 * An object's properties in JavaScript's own-property order: array-index keys
 * ("0" to "4294967294", no leading zeros) first in ascending numeric order, then
 * every other key in insertion order. JSON.parse followed by JSON.stringify
 * reorders a spec's `"404"`-style response keys this way, so PHP must too.
 *
 * @return list<array{string, mixed}>
 */
function huuray_javascript_property_order(stdClass $object): array
{
    $indexed = [];
    $named = [];
    foreach (get_object_vars($object) as $key => $item) {
        $key = (string) $key;
        if (preg_match('/^(?:0|[1-9][0-9]*)$/', $key) === 1 && strlen($key) <= 10 && (int) $key <= 4294967294) {
            // Canonical integer strings are unique as integers, so they can key the sort.
            $indexed[(int) $key] = [$key, $item];
        } else {
            $named[] = [$key, $item];
        }
    }

    ksort($indexed, SORT_NUMERIC);

    return [...array_values($indexed), ...$named];
}

/** A finite double as JavaScript's Number.prototype.toString writes it (ECMA-262 Number::toString). */
function huuray_javascript_number(float $number): string
{
    if (is_nan($number) || is_infinite($number)) {
        return 'null';
    }
    if ($number == 0.0) {
        return '0';
    }

    // With serialize_precision = -1 this is the shortest round-trip representation,
    // e.g. "7.46", "1.0e+25", "1.5e-7" — only the layout differs from JavaScript's.
    $shortest = json_encode($number, JSON_THROW_ON_ERROR);
    if (preg_match('/^(-?)(\d+)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/', $shortest, $match) !== 1) {
        throw new RuntimeException('Unexpected number format: ' . $shortest);
    }

    $sign = $match[1];
    $integerPart = $match[2];
    $fractionPart = $match[3] ?? '';
    // Group 4 requires a digit, so it is either absent or non-empty.
    $exponent = isset($match[4]) ? (int) $match[4] : 0;

    // Normalise to value = 0.DIGITS x 10^n, with no leading or trailing zeros in DIGITS.
    $digits = $integerPart . $fractionPart;
    $n = strlen($integerPart) + $exponent;
    $trimmed = ltrim($digits, '0');
    $n -= strlen($digits) - strlen($trimmed);
    $digits = rtrim($trimmed, '0');
    $k = strlen($digits);

    if ($k <= $n && $n <= 21) {
        $text = $digits . str_repeat('0', $n - $k);
    } elseif (0 < $n && $n <= 21) {
        $text = substr($digits, 0, $n) . '.' . substr($digits, $n);
    } elseif (-6 < $n && $n <= 0) {
        $text = '0.' . str_repeat('0', -$n) . $digits;
    } else {
        $e = $n - 1;
        $mantissa = $k === 1 ? $digits : $digits[0] . '.' . substr($digits, 1);
        $text = $mantissa . 'e' . ($e >= 0 ? '+' : '-') . abs($e);
    }

    return $sign . $text;
}

$specPath = dirname(__DIR__) . '/openapi/huuray-v4.json';
$configuredUrl = getenv('HUURAY_SPEC_URL');
$url = is_string($configuredUrl) && $configuredUrl !== '' ? $configuredUrl : HUURAY_DEFAULT_SPEC_URL;

try {
    [$status, $body] = huuray_fetch($url);
} catch (Throwable $e) {
    fwrite(STDERR, sprintf('Failed to fetch spec from %s: %s', $url, $e->getMessage()) . PHP_EOL);
    exit(1);
}

if ($status < 200 || $status >= 300) {
    fwrite(STDERR, sprintf('Failed to fetch spec: HTTP %d from %s', $status, $url) . PHP_EOL);
    exit(1);
}

if (str_starts_with($body, "\xEF\xBB\xBF")) {
    $body = substr($body, 3);
}

try {
    $incoming = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    fwrite(STDERR, 'The downloaded spec is not valid JSON: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$info = $incoming instanceof stdClass ? ($incoming->info ?? null) : null;
$version = $info instanceof stdClass ? ($info->version ?? null) : null;

if ($version !== 'v4') {
    fwrite(STDERR, sprintf(
        'Refusing to write: expected info.version "v4", got "%s".',
        is_scalar($version) ? (string) $version : 'undefined',
    ) . PHP_EOL);
    fwrite(STDERR, 'This SDK targets v4 only. A version change is a deliberate decision, not a sync.' . PHP_EOL);
    exit(1);
}

$next = huuray_encode_like_javascript($incoming) . "\n";
$previous = is_file($specPath) ? file_get_contents($specPath) : '';

if ($previous === $next) {
    fwrite(STDOUT, 'Spec unchanged.' . PHP_EOL);
    exit(0);
}

if (file_put_contents($specPath, $next) === false) {
    fwrite(STDERR, 'Could not write ' . $specPath . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'Spec CHANGED — the diff must be reviewed and the gates re-run.' . PHP_EOL);
exit(0);
