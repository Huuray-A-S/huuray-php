<?php

declare(strict_types=1);

namespace Huuray\Tests\Cli;

use Huuray\Cli\Args;
use Huuray\Cli\Table;
use Huuray\Exception\HuurayException;
use PHPUnit\Framework\TestCase;

final class ArgsTest extends TestCase
{
    // ---------------------------------------------------------------- parse()

    public function testReadsABareCommand(): void
    {
        $parsed = Args::parse(['balance']);

        self::assertSame('balance', $parsed->command);
        self::assertSame([], $parsed->flags);
    }

    public function testReadsACommandWithABooleanFlag(): void
    {
        $parsed = Args::parse(['catalogue', '--all']);

        self::assertSame('catalogue', $parsed->command);
        self::assertSame(['all' => true], $parsed->flags);
    }

    public function testReadsACommandWithAValuedFlag(): void
    {
        $parsed = Args::parse(['stock', '--token', 'abc']);

        self::assertSame('stock', $parsed->command);
        self::assertSame(['token' => 'abc'], $parsed->flags);
    }

    public function testHandlesAFlagBeforeTheCommand(): void
    {
        // --help must never be swallowed as the command name, or `huuray --help`
        // would demand credentials before printing usage.
        $parsed = Args::parse(['--help']);

        self::assertNull($parsed->command);
        self::assertSame(['help' => true], $parsed->flags);
    }

    public function testFindsTheCommandEvenWhenFlagsComeFirst(): void
    {
        self::assertSame('balance', Args::parse(['--json', 'balance'])->command);
    }

    public function testSupportsTheShortHelpFlag(): void
    {
        self::assertTrue(Args::wantsHelp(Args::parse(['-h'])->flags));
        self::assertFalse(Args::wantsHelp(Args::parse(['balance'])->flags));
    }

    public function testRejectsAValuedFlagWithNoValueInsteadOfSilentlyDegrading(): void
    {
        // `huuray search --ref-id --json` must not quietly run a FILTERLESS search:
        // the user typed a filter, so dropping it changes which API query is sent.
        foreach ([['search', '--ref-id'], ['search', '--ref-id', '--json']] as $argv) {
            try {
                Args::parse($argv);
                self::fail('Expected a missing value to be rejected: ' . implode(' ', $argv));
            } catch (HuurayException $e) {
                self::assertStringContainsString('--ref-id requires a value', $e->getMessage());
            }
        }
    }

    public function testSupportsGnuFlagEqualsValueSyntax(): void
    {
        self::assertSame(['ref-id' => 'abc'], Args::parse(['search', '--ref-id=abc'])->flags);
        self::assertSame(['from' => 'EUR', 'to' => 'DKK'], Args::parse(['rates', '--from=EUR', '--to', 'DKK'])->flags);
    }

    public function testRejectsAValueOnABooleanFlag(): void
    {
        $this->expectException(HuurayException::class);
        $this->expectExceptionMessage('does not take a value');

        Args::parse(['catalogue', '--all=yes']);
    }

    public function testAcceptsNegativeNumbersAsFlagValues(): void
    {
        self::assertSame(['token' => 'x', 'value' => '-500'], Args::parse(['stock', '--token', 'x', '--value', '-500'])->flags);
    }

    public function testRejectsUnknownFlagsInsteadOfIgnoringThem(): void
    {
        $this->expectException(HuurayException::class);
        $this->expectExceptionMessage('Unknown option --verbose');

        Args::parse(['balance', '--verbose']);
    }

    public function testKeepsHyphenatedFlagNamesIntact(): void
    {
        self::assertSame(['ref-id' => 'payroll-2026-08'], Args::parse(['search', '--ref-id', 'payroll-2026-08'])->flags);
    }

    public function testReturnsNoCommandForEmptyArgv(): void
    {
        self::assertNull(Args::parse([])->command);
    }

    // ----------------------------------------------------------- flag readers

    public function testRequireFlagExplainsWhatIsMissing(): void
    {
        foreach ([[], ['token' => true]] as $flags) {
            try {
                Args::requireFlag($flags, 'token');
                self::fail('Expected a missing --token to be rejected.');
            } catch (HuurayException $e) {
                self::assertStringContainsString('--token', $e->getMessage());
            }
        }

        self::assertSame('abc', Args::requireFlag(['token' => 'abc'], 'token'));
    }

    public function testOptionalIntRejectsANonIntegerRatherThanSilentlyTruncating(): void
    {
        try {
            Args::optionalInt(['value' => '50.5'], 'value');
            self::fail('Expected 50.5 to be rejected.');
        } catch (HuurayException $e) {
            self::assertStringContainsString('must be an integer', $e->getMessage());
        }

        self::assertSame(5000, Args::optionalInt(['value' => '5000'], 'value'));
        self::assertSame(-500, Args::optionalInt(['value' => '-500'], 'value'));
        self::assertNull(Args::optionalInt([], 'value'));
        self::assertNull(Args::optionalInt(['value' => true], 'value'));
    }

    public function testOptionalStringIgnoresBooleanFlags(): void
    {
        self::assertNull(Args::optionalString(['ref-id' => true], 'ref-id'));
        self::assertSame('r', Args::optionalString(['ref-id' => 'r'], 'ref-id'));
    }

    // ------------------------------------------------------------------ table

    public function testTableSaysSoPlainlyWhenThereIsNothingToShow(): void
    {
        self::assertSame('(no results)', Table::render([]));
    }

    public function testTableAlignsColumnsAndIncludesAHeaderRule(): void
    {
        $lines = explode("\n", Table::render([
            ['currency' => 'DKK', 'balance' => 50_000],
            ['currency' => 'EUR', 'balance' => 1234],
        ]));

        self::assertCount(4, $lines);
        self::assertMatchesRegularExpression('/^currency\s+balance$/', $lines[0]);
        self::assertMatchesRegularExpression('/^-+\s+-+$/', $lines[1]);
        self::assertSame('DKK       50000', $lines[2]);
    }

    public function testTableOutputIsPureAsciiSoAnyConsoleCodePageRendersIt(): void
    {
        $out = Table::render([['currency' => 'DKK', 'balance' => 50_000]]);

        self::assertMatchesRegularExpression('/^[\x00-\x7F]*$/', $out);
    }

    public function testTableWidensAColumnToItsLongestValue(): void
    {
        self::assertStringContainsString('a-much-longer-value', Table::render([['name' => 'a'], ['name' => 'a-much-longer-value']]));
    }

    public function testTableToleratesRowsWithDifferentKeys(): void
    {
        $lines = explode("\n", Table::render([['a' => 1], ['b' => 2]]));

        self::assertMatchesRegularExpression('/^a\s+b$/', $lines[0]);
    }

    public function testTableAlignsNonAsciiValuesByCharacterNotByte(): void
    {
        $lines = explode("\n", Table::render([['brand' => 'Österreich', 'x' => 1], ['brand' => 'Denmark', 'x' => 2]]));

        // "Österreich" is 10 characters but 11 bytes; padding by bytes would misalign it.
        self::assertSame(['brand       x', '----------  -', 'Österreich  1', 'Denmark     2'], $lines);
    }
}
