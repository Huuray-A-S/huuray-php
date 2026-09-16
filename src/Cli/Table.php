<?php

declare(strict_types=1);

namespace Huuray\Cli;

/**
 * A minimal fixed-width table.
 *
 * Pure ASCII, including the header rule, so the output reads correctly in any
 * console code page — a box-drawing rule turns into garbage on a Windows console
 * that is not set to UTF-8.
 *
 * @internal
 */
final class Table
{
    private function __construct() {}

    /** @param list<array<mixed>> $rows Each row maps a column name to a cell value. */
    public static function render(array $rows): string
    {
        if ($rows === []) {
            return '(no results)';
        }

        $columns = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $column) {
                $column = (string) $column;
                if (!in_array($column, $columns, true)) {
                    $columns[] = $column;
                }
            }
        }

        $widths = [];
        foreach ($columns as $column) {
            $width = self::length($column);
            foreach ($rows as $row) {
                $width = max($width, self::length(self::cell($row[$column] ?? null)));
            }
            $widths[] = $width;
        }

        $lines = [self::line($columns, $widths)];
        $lines[] = self::line(array_map(static fn(int $width): string => str_repeat('-', $width), $widths), $widths);
        foreach ($rows as $row) {
            $cells = [];
            foreach ($columns as $column) {
                $cells[] = self::cell($row[$column] ?? null);
            }
            $lines[] = self::line($cells, $widths);
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $cells
     * @param list<int>    $widths
     */
    private static function line(array $cells, array $widths): string
    {
        $padded = [];
        foreach ($cells as $index => $cell) {
            $padded[] = $cell . str_repeat(' ', max(0, ($widths[$index] ?? 0) - self::length($cell)));
        }

        return rtrim(implode('  ', $padded));
    }

    private static function cell(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value === true => 'true',
            $value === false => 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        };
    }

    /** Length in characters, so non-ASCII brand names still line up. */
    private static function length(string $text): int
    {
        $count = preg_match_all('/./us', $text);

        return is_int($count) ? $count : strlen($text);
    }
}
