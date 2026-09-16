<?php

declare(strict_types=1);

namespace Huuray\Cli;

use Huuray\Exception\HuurayException;

/**
 * Argument parsing for the read-only CLI. Kept local so the package ships no CLI dependencies.
 *
 * @internal
 */
final class Args
{
    /** Flags that never take a value. */
    private const BOOLEAN_FLAGS = ['json', 'all', 'help', 'h'];

    /**
     * Flags that always take a value.
     *
     * Declared explicitly so a missing value is an error, never a silent
     * downgrade: `huuray search --ref-id --json` must not quietly run a
     * filterless search — the user typed a filter, so dropping it changes which
     * API query is sent.
     */
    private const VALUED_FLAGS = ['token', 'value', 'from', 'to', 'ref-id', 'order-uid', 'voucher-id'];

    private function __construct() {}

    /**
     * Parses argv into a command and flags.
     *
     * Flags may appear anywhere, including before the command. Both `--flag value`
     * and `--flag=value` are accepted. A valued flag with no value, or a flag the
     * CLI does not know, is an error rather than a guess.
     *
     * @param list<string> $argv The arguments after the program name.
     *
     * @throws HuurayException
     */
    public static function parse(array $argv): ParsedArgs
    {
        $flags = [];
        $positionals = [];
        $count = count($argv);

        for ($i = 0; $i < $count; $i++) {
            $arg = $argv[$i];
            if (!str_starts_with($arg, '-')) {
                $positionals[] = $arg;
                continue;
            }

            $key = self::stripDashes($arg);
            $inlineValue = null;
            $eq = strpos($key, '=');
            if ($eq !== false) {
                $inlineValue = substr($key, $eq + 1);
                $key = substr($key, 0, $eq);
            }

            if (in_array($key, self::BOOLEAN_FLAGS, true)) {
                if ($inlineValue !== null) {
                    throw new HuurayException(sprintf('Option --%s does not take a value.', $key));
                }
                $flags[$key] = true;
                continue;
            }

            if (in_array($key, self::VALUED_FLAGS, true)) {
                if ($inlineValue !== null) {
                    $flags[$key] = $inlineValue;
                    continue;
                }
                $next = $argv[$i + 1] ?? null;
                // The next token is the value even when it starts with '-', so
                // negative numbers work; only a missing token or another known
                // flag is an error.
                if ($next === null || self::flagName($next) === $key || self::isKnownFlag($next)) {
                    throw new HuurayException(sprintf('Option --%s requires a value. Run "huuray --help".', $key));
                }
                $flags[$key] = $next;
                $i++;
                continue;
            }

            throw new HuurayException(sprintf('Unknown option --%s. Run "huuray --help".', $key));
        }

        return new ParsedArgs($positionals[0] ?? null, $flags);
    }

    /** @param array<string, string|true> $flags */
    public static function wantsHelp(array $flags): bool
    {
        return ($flags['help'] ?? null) === true || ($flags['h'] ?? null) === true;
    }

    /**
     * @param array<string, string|true> $flags
     *
     * @throws HuurayException
     */
    public static function requireFlag(array $flags, string $name): string
    {
        $value = $flags[$name] ?? null;
        if (!is_string($value) || $value === '') {
            throw new HuurayException(sprintf('Missing required option --%s. Run "huuray --help".', $name));
        }

        return $value;
    }

    /**
     * An integer flag. A non-integer is rejected rather than silently truncated.
     *
     * @param array<string, string|true> $flags
     *
     * @throws HuurayException
     */
    public static function optionalInt(array $flags, string $name): ?int
    {
        $value = $flags[$name] ?? null;
        if (!is_string($value)) {
            return null;
        }

        $int = filter_var($value, FILTER_VALIDATE_INT);
        if ($int === false) {
            throw new HuurayException(sprintf('--%s must be an integer, got "%s".', $name, $value));
        }

        return $int;
    }

    /** @param array<string, string|true> $flags */
    public static function optionalString(array $flags, string $name): ?string
    {
        $value = $flags[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    private static function stripDashes(string $token): string
    {
        if (str_starts_with($token, '--')) {
            return substr($token, 2);
        }

        return str_starts_with($token, '-') ? substr($token, 1) : $token;
    }

    private static function flagName(string $token): string
    {
        return explode('=', self::stripDashes($token), 2)[0];
    }

    private static function isKnownFlag(string $token): bool
    {
        if (!str_starts_with($token, '-')) {
            return false;
        }
        $key = self::flagName($token);

        return in_array($key, self::BOOLEAN_FLAGS, true) || in_array($key, self::VALUED_FLAGS, true);
    }
}
