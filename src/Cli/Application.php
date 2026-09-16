<?php

declare(strict_types=1);

namespace Huuray\Cli;

use Huuray\Exception\ApiException;
use Huuray\Exception\HuurayException;
use Huuray\Http\Transport;
use Huuray\HuurayClient;
use Huuray\Redact;

/**
 * Read-only command line interface.
 *
 * Deliberately limited to operations that cannot move value: there is no
 * ordering, resending, or cancelling here. Sending real gift cards from a shell
 * one-liner is too easy to do by accident, and a mistyped quantity is money.
 *
 * Voucher codes are never printed, whatever the account settings allow.
 *
 * The process wiring (argv, environment, exit code) lives in `bin/huuray`; this
 * class only defines the commands, so tests can run them against a fake transport.
 */
final class Application
{
    public const USAGE = <<<'TXT'
        huuray - read-only CLI for the Huuray API v4

          Usage
            huuray <command> [options]

          Commands
            balance                       Available balances, per currency
            catalogue [--all]             Products you can order (--all for the full catalogue)
            templates                     Delivery and PDF templates on your account
            stock --token <t> [--value N] Stock for a product (value in minor units)
            rates --from EUR --to DKK     Exchange rate and spread
            search [--ref-id R] [--order-uid U] [--voucher-id N]
                                          Look up vouchers from previous orders

          Options
            --json                        Machine-readable output
            -h, --help                    This text

          Credentials, from the environment
            HUURAY_API_TOKEN
            HUURAY_API_SECRET
            HUURAY_BASE_URL               Optional; defaults to https://api.huuray.com

          Ordering, resending and cancelling are not available here. They move real
          value, so they belong in code you have reviewed. See the README.

          Voucher codes are never printed by this CLI.
        TXT;

    /**
     * @param \Closure(string): void $out       Receives text for stdout, one call per line or block.
     * @param \Closure(string): void $err       Receives text for stderr.
     * @param Transport|null         $transport Passed to the client; the test suite injects a fake.
     */
    public function __construct(
        private readonly \Closure $out,
        private readonly \Closure $err,
        private readonly ?Transport $transport = null,
    ) {}

    /**
     * Runs one command and returns the process exit code.
     *
     * @param list<string>          $argv The arguments after the program name.
     * @param array<string, string> $env  The environment, e.g. from getenv().
     */
    public function run(
        array $argv,
        #[\SensitiveParameter]
        array $env,
    ): int {
        try {
            return $this->dispatch($argv, $env);
        } catch (ApiException $e) {
            $this->stderr('Error: ' . $e->getMessage());
            if ($e->httpStatus === 401 || $e->httpStatus === 403) {
                $this->stderr('');
                $this->stderr('If the credentials are correct, the X-API-HASH encoding may differ from this');
                $this->stderr('client\'s default. See the README section "Authentication".');
            }

            return 1;
        } catch (HuurayException|\InvalidArgumentException $e) {
            $this->stderr('Error: ' . $e->getMessage());

            return 1;
        }
    }

    /**
     * @param list<string>          $argv
     * @param array<string, string> $env
     *
     * @throws HuurayException
     * @throws \InvalidArgumentException from the client's input guards, e.g. a request body that is not valid UTF-8
     */
    private function dispatch(
        array $argv,
        #[\SensitiveParameter]
        array $env,
    ): int {
        $parsed = Args::parse($argv);
        $flags = $parsed->flags;

        // Help must work before anything else, including the credential check.
        if (Args::wantsHelp($flags) || $parsed->command === null) {
            $this->stdout(self::USAGE);

            return Args::wantsHelp($flags) ? 0 : 1;
        }

        $apiToken = $env['HUURAY_API_TOKEN'] ?? '';
        $apiSecret = $env['HUURAY_API_SECRET'] ?? '';
        if ($apiToken === '' || $apiSecret === '') {
            $this->stderr('Set HUURAY_API_TOKEN and HUURAY_API_SECRET in the environment.');
            $this->stderr('Run "huuray --help" for usage.');

            return 1;
        }

        $baseUrl = $env['HUURAY_BASE_URL'] ?? '';
        $client = new HuurayClient(
            apiToken: $apiToken,
            apiSecret: $apiSecret,
            baseUrl: $baseUrl !== '' ? $baseUrl : HuurayClient::DEFAULT_BASE_URL,
            transport: $this->transport,
            userAgent: 'huuray-cli',
        );

        $asJson = ($flags['json'] ?? null) === true;

        switch ($parsed->command) {
            case 'balance':
                $balances = $client->balances->list()->balances;
                $this->emit($asJson, $balances, static function () use ($balances): array {
                    $rows = [];
                    foreach ($balances as $balance) {
                        $rows[] = [
                            'currency' => $balance->currency ?? '',
                            'balance (minor units)' => $balance->balance,
                            'master' => $balance->master ? 'yes' : '',
                        ];
                    }

                    return $rows;
                });

                return 0;

            case 'catalogue':
                $products = $client->catalogue->list(all: ($flags['all'] ?? null) === true)->products;
                $this->emit($asJson, $products, static function () use ($products): array {
                    $rows = [];
                    foreach ($products as $product) {
                        $rows[] = [
                            'token' => $product->productToken ?? '(not returned with --all)',
                            'brand' => $product->brandName ?? '',
                            'country' => $product->countryCode ?? '',
                            'currency' => $product->currency ?? '',
                            'discount' => $product->discount ?? '',
                            'active' => $product->active ? 'yes' : 'no',
                        ];
                    }

                    return $rows;
                });

                return 0;

            case 'templates':
                // Both lists, always: an account whose templates are all PDF
                // templates must not print an empty result.
                $result = $client->templates->list();
                if ($asJson) {
                    $this->printJson(['templates' => $result->templates, 'pdfTemplates' => $result->pdfTemplates]);

                    return 0;
                }

                $templateRows = [];
                foreach ($result->templates as $template) {
                    $templateRows[] = [
                        'id' => $template->id,
                        'name' => $template->name ?? '',
                        'type' => $template->type ?? '',
                        'language' => $template->language ?? '',
                        'sender' => $template->sender ?? '',
                    ];
                }
                $pdfRows = [];
                foreach ($result->pdfTemplates as $pdfTemplate) {
                    $pdfRows[] = [
                        'uid' => $pdfTemplate->uid ?? '',
                        'name' => $pdfTemplate->name ?? '',
                        'type' => $pdfTemplate->type ?? '',
                        'language' => $pdfTemplate->language ?? '',
                        'country' => $pdfTemplate->country ?? '',
                        'brand' => $pdfTemplate->brandName ?? '',
                    ];
                }

                $this->stdout('Delivery templates');
                $this->stdout(self::render($templateRows));
                $this->stdout('');
                $this->stdout('PDF templates');
                $this->stdout(self::render($pdfRows));

                return 0;

            case 'stock':
                $value = Args::optionalInt($flags, 'value');
                $stock = $client->stock->check(productToken: Args::requireFlag($flags, 'token'), value: $value);
                $this->emit($asJson, $stock, static fn(): array => [['stock' => $stock->stock ?? 'unknown']]);

                return 0;

            case 'rates':
                $rate = $client->exchangeRates->get(
                    from: Args::requireFlag($flags, 'from'),
                    to: Args::requireFlag($flags, 'to'),
                );
                $this->emit($asJson, $rate, static fn(): array => [[
                    'rate' => $rate->exchangeRate ?? '',
                    'spread (%)' => $rate->spread ?? '',
                ]]);

                return 0;

            case 'search':
                $found = $client->orders->search(
                    orderUid: Args::optionalString($flags, 'order-uid'),
                    voucherId: Args::optionalInt($flags, 'voucher-id'),
                    refId: Args::optionalString($flags, 'ref-id'),
                );
                // No code column at all: codes are never printed by this CLI, and
                // a column of redaction markers would wrongly imply codes were present.
                $this->emit($asJson, $found, static function () use ($found): array {
                    $rows = [];
                    foreach ($found->vouchers as $voucher) {
                        $rows[] = [
                            'voucher id' => $voucher->id ?? '',
                            'expires' => $voucher->expires ?? '',
                            'recipient' => $voucher->recipient->name ?? $voucher->recipient->refId ?? '',
                        ];
                    }

                    return $rows;
                });
                if (!$asJson) {
                    $this->stdout('');
                    $this->stdout(sprintf('order: %s  ref: %s', $found->orderUid ?? '(none)', $found->refId ?? ''));
                    $this->stdout('(voucher codes are never printed by this CLI)');
                }

                return 0;

            default:
                $this->stderr(sprintf('Unknown command "%s". Run "huuray --help".', $parsed->command));

                return 1;
        }
    }

    /**
     * Prints a result as a table, or as JSON. Redaction runs on both paths —
     * voucher codes never reach stdout.
     *
     * @param \Closure(): list<array<string, mixed>> $rows
     */
    private function emit(
        bool $asJson,
        #[\SensitiveParameter]
        mixed $data,
        #[\SensitiveParameter]
        \Closure $rows,
    ): void {
        if ($asJson) {
            $this->printJson($data);

            return;
        }

        $this->stdout(self::render($rows()));
    }

    private function printJson(
        #[\SensitiveParameter]
        mixed $data,
    ): void {
        $this->stdout(Redact::safeJson($data, JSON_PRETTY_PRINT));
    }

    /** @param list<array<string, mixed>> $rows */
    private static function render(
        #[\SensitiveParameter]
        array $rows,
    ): string {
        $redacted = [];
        foreach ($rows as $row) {
            $clean = Redact::redact($row);
            $redacted[] = is_array($clean) ? $clean : [];
        }

        return Table::render($redacted);
    }

    private function stdout(string $text): void
    {
        ($this->out)($text);
    }

    private function stderr(string $text): void
    {
        ($this->err)($text);
    }
}
