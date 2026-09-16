<?php

declare(strict_types=1);

namespace Huuray\Cli;

/**
 * The command and flags parsed from argv.
 *
 * @internal
 */
final readonly class ParsedArgs
{
    /**
     * @param string|null               $command The first non-flag argument, e.g. `balance`. Null when only flags were given.
     * @param array<string, string|true> $flags
     */
    public function __construct(
        public ?string $command,
        public array $flags,
    ) {}
}
