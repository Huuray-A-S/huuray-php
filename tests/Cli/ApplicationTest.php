<?php

declare(strict_types=1);

namespace Huuray\Tests\Cli;

use Huuray\Cli\Application;
use Huuray\Tests\Support\CapturedRequest;
use Huuray\Tests\Support\FakeTransport;
use Huuray\Tests\Support\MockResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The CLI: what it prints, what it refuses to do, and that nothing reaches the network. */
final class ApplicationTest extends TestCase
{
    /** Throwaway credentials; the fake transport means nothing reaches the network. */
    private const ENV = ['HUURAY_API_TOKEN' => 'test-token', 'HUURAY_API_SECRET' => 'test-secret'];

    /** Invented values, not taken from any account. */
    private const PDF_TEMPLATE = [
        'Uid' => '00000000-0000-4000-8000-00000000c11a',
        'Name' => 'Invented PDF template',
        'Type' => 'InventedType',
        'Language' => 'zz',
        'Country' => null,
        'BrandName' => null,
    ];

    // -------------------------------------------------------------- templates

    public function testPrintsPdfTemplatesInTableOutputEvenWhenThereAreNoDeliveryTemplates(): void
    {
        $run = $this->runCli(['templates'], new MockResponse(json: ['Templates' => [], 'PDFTemplates' => [self::PDF_TEMPLATE]]));

        self::assertSame(0, $run['code']);
        self::assertCount(1, $run['calls']);
        self::assertSame('POST', $run['calls'][0]->method);
        self::assertSame('/v4/Template', $run['calls'][0]->path);

        $lines = explode("\n", $run['stdout']);
        self::assertSame(['Delivery templates', '(no results)', '', 'PDF templates'], array_slice($lines, 0, 4));
        self::assertMatchesRegularExpression('/^uid\s+name\s+type\s+language\s+country\s+brand$/', $lines[4]);
        self::assertStringContainsString(self::PDF_TEMPLATE['Uid'], $run['stdout']);
        self::assertStringContainsString(self::PDF_TEMPLATE['Name'], $run['stdout']);
        // Null country and brand render as empty cells, not "null".
        self::assertStringNotContainsString('null', $run['stdout']);
    }

    public function testIncludesPdfTemplatesInJsonOutputAlongsideDeliveryTemplates(): void
    {
        $run = $this->runCli(['templates', '--json'], new MockResponse(json: ['Templates' => [], 'PDFTemplates' => [self::PDF_TEMPLATE]]));

        self::assertSame(0, $run['code']);
        self::assertSame(
            [
                'templates' => [],
                'pdfTemplates' => [[
                    'uid' => self::PDF_TEMPLATE['Uid'],
                    'name' => self::PDF_TEMPLATE['Name'],
                    'type' => self::PDF_TEMPLATE['Type'],
                    'language' => self::PDF_TEMPLATE['Language'],
                    'country' => null,
                    'brandName' => null,
                ]],
            ],
            json_decode($run['stdout'], true, 512, JSON_THROW_ON_ERROR),
        );
    }

    // ------------------------------------------------------------ other reads

    public function testBalancePrintsATable(): void
    {
        $run = $this->runCli(['balance'], new MockResponse(json: ['Balances' => [['Currency' => 'DKK', 'Balance' => 50_000, 'Master' => true]]]));

        self::assertSame(0, $run['code']);
        self::assertSame('GET', $run['calls'][0]->method);
        self::assertStringContainsString('balance (minor units)', $run['stdout']);
        self::assertStringContainsString('50000', $run['stdout']);
    }

    public function testCatalogueAllSendsAllTrue(): void
    {
        $run = $this->runCli(['catalogue', '--all'], new MockResponse(json: ['Products' => [['BrandName' => 'Example', 'Active' => true]]]));

        self::assertSame(0, $run['code']);
        self::assertSame(['All' => true], $run['calls'][0]->body);
        self::assertStringContainsString('(not returned with --all)', $run['stdout']);
    }

    public function testStockSendsTheTokenAndValue(): void
    {
        $run = $this->runCli(['stock', '--token', 'tok', '--value', '5000'], new MockResponse(json: ['Stock' => 12]));

        self::assertSame(0, $run['code']);
        self::assertSame(['ProductToken' => 'tok', 'Value' => 5000], $run['calls'][0]->body);
        self::assertStringContainsString('12', $run['stdout']);
    }

    public function testRatesSendsTheCurrenciesAsQueryParameters(): void
    {
        $run = $this->runCli(['rates', '--from', 'EUR', '--to', 'DKK', '--json'], new MockResponse(json: ['ExchangeRate' => 7.46, 'Spread' => 2]));

        self::assertSame(0, $run['code']);
        self::assertSame(['FromCurrency' => 'EUR', 'ToCurrency' => 'DKK'], $run['calls'][0]->query);
        self::assertSame(['exchangeRate' => 7.46, 'spread' => 2], json_decode($run['stdout'], true, 512, JSON_THROW_ON_ERROR));
    }

    public function testSearchNeverPrintsAVoucherCodeInTableOutput(): void
    {
        $run = $this->runCli(['search', '--ref-id', 'ref-9'], self::searchResponse());

        self::assertSame(0, $run['code']);
        self::assertSame(['RefID' => 'ref-9'], $run['calls'][0]->body);
        self::assertStringNotContainsString('INVENTED-CODE-123', $run['stdout']);
        self::assertStringNotContainsString('999', $run['stdout']);
        self::assertStringNotContainsString('https://redeem.example/abc', $run['stdout']);
        self::assertStringContainsString('order: uid-7  ref: ref-9', $run['stdout']);
        self::assertStringContainsString('(voucher codes are never printed by this CLI)', $run['stdout']);
    }

    public function testSearchNeverPrintsAVoucherCodeInJsonOutput(): void
    {
        $run = $this->runCli(['search', '--order-uid', 'uid-7', '--voucher-id', '3', '--json'], self::searchResponse());

        self::assertSame(0, $run['code']);
        self::assertSame(['OrderUID' => 'uid-7', 'VoucherID' => 3], $run['calls'][0]->body);
        self::assertStringNotContainsString('INVENTED-CODE-123', $run['stdout']);
        self::assertStringNotContainsString('jane@example.com', $run['stdout']);
        self::assertStringContainsString('[redacted: bearer value]', $run['stdout']);
    }

    // -------------------------------------------- entry point and refusals

    public function testHelpExitsZeroWithoutCredentials(): void
    {
        $run = $this->runCli(['--help'], new MockResponse(), env: []);

        self::assertSame(0, $run['code']);
        self::assertCount(0, $run['calls']);
        self::assertStringContainsString('read-only CLI for the Huuray API v4', $run['stdout']);
        self::assertStringContainsString('Voucher codes are never printed', $run['stdout']);
        self::assertStringContainsString('Ordering, resending and cancelling are not available here', $run['stdout']);
    }

    public function testPrintsUsageAndFailsWhenGivenNoCommand(): void
    {
        $run = $this->runCli([], new MockResponse(), env: []);

        self::assertSame(1, $run['code']);
        self::assertStringContainsString('Usage', $run['stdout']);
    }

    public function testRefusesToRunWithoutCredentials(): void
    {
        $run = $this->runCli(['balance'], new MockResponse(), env: []);

        self::assertSame(1, $run['code']);
        self::assertCount(0, $run['calls']);
        self::assertStringContainsString('HUURAY_API_TOKEN', $run['stderr']);
        self::assertStringContainsString('Run "huuray --help"', $run['stderr']);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function halfConfiguredEnvironments(): iterable
    {
        yield 'secret only' => [['HUURAY_API_SECRET' => 's']];
        yield 'token only' => [['HUURAY_API_TOKEN' => 't']];
        yield 'token empty' => [['HUURAY_API_TOKEN' => '', 'HUURAY_API_SECRET' => 's']];
    }

    /** @param array<string, string> $env */
    #[DataProvider('halfConfiguredEnvironments')]
    public function testChecksBothCredentialsBeforeTouchingTheNetwork(array $env): void
    {
        $run = $this->runCli(['search', '--ref-id', 'x'], new MockResponse(), env: $env);

        self::assertSame(1, $run['code']);
        self::assertCount(0, $run['calls']);
        self::assertStringContainsString('Set HUURAY_API_TOKEN and HUURAY_API_SECRET in the environment.', $run['stderr']);
    }

    /** @return iterable<string, array{string}> */
    public static function valueMovingCommands(): iterable
    {
        foreach (['order', 'send', 'send-reward', 'resend', 'cancel'] as $command) {
            yield $command => [$command];
        }
    }

    #[DataProvider('valueMovingCommands')]
    public function testOffersNoCommandThatMovesValue(string $command): void
    {
        $run = $this->runCli([$command], new MockResponse());

        self::assertSame(1, $run['code']);
        self::assertCount(0, $run['calls']);
        self::assertStringContainsString('Unknown command', $run['stderr']);
    }

    public function testReportsABadFlagAsAnErrorWithoutSendingAnything(): void
    {
        $run = $this->runCli(['search', '--ref-id', '--json'], new MockResponse());

        self::assertSame(1, $run['code']);
        self::assertCount(0, $run['calls']);
        self::assertStringContainsString('--ref-id requires a value', $run['stderr']);
    }

    public function testExplainsA401AndPointsAtTheHashEncoding(): void
    {
        $run = $this->runCli(['balance'], new MockResponse(status: 401, json: ['Status' => 401, 'StatusMessage' => 'Unauthorized']));

        self::assertSame(1, $run['code']);
        self::assertStringContainsString('Error: GET /v4/Balance failed with HTTP 401', $run['stderr']);
        self::assertStringContainsString('X-API-HASH encoding', $run['stderr']);
    }

    public function testUsesAConfiguredBaseUrl(): void
    {
        $run = $this->runCli(['balance'], new MockResponse(json: ['Balances' => []]), env: self::ENV + ['HUURAY_BASE_URL' => 'https://sandbox.example.test']);

        self::assertSame(0, $run['code']);
        self::assertSame('https://sandbox.example.test', $run['calls'][0]->origin);
        self::assertStringStartsWith('huuray-php/', $run['calls'][0]->headers['User-Agent']);
        self::assertStringEndsWith(' huuray-cli', $run['calls'][0]->headers['User-Agent']);
    }

    // ---------------------------------------------------------------- helpers

    private static function searchResponse(): MockResponse
    {
        return new MockResponse(json: [
            'OrderUID' => 'uid-7',
            'RefID' => 'ref-9',
            'Vouchers' => [[
                'ID' => 3,
                'Code' => 'INVENTED-CODE-123',
                'CVV' => '999',
                'RedeemLink' => 'https://redeem.example/abc',
                'Expires' => '2027-01-01T00:00:00',
                'Recipient' => ['Name' => 'Jane', 'Email' => 'jane@example.com'],
            ]],
        ]);
    }

    /**
     * Runs the CLI against a fake transport and captures its output. Nothing here can reach the network.
     *
     * @param list<string>          $argv
     * @param array<string, string> $env
     *
     * @return array{code: int, stdout: string, stderr: string, calls: list<CapturedRequest>}
     */
    private function runCli(array $argv, MockResponse $response, array $env = self::ENV): array
    {
        $transport = new FakeTransport($response);
        $stdout = [];
        $stderr = [];

        $application = new Application(
            out: static function (string $text) use (&$stdout): void {
                $stdout[] = $text;
            },
            err: static function (string $text) use (&$stderr): void {
                $stderr[] = $text;
            },
            transport: $transport,
        );

        $code = $application->run($argv, $env);

        return [
            'code' => $code,
            'stdout' => implode("\n", $stdout),
            'stderr' => implode("\n", $stderr),
            'calls' => $transport->calls,
        ];
    }
}
