<?php

declare(strict_types=1);

namespace Huuray\Tests;

use Huuray\Exception\NotFoundException;
use Huuray\HuurayClient;
use Huuray\Result\Balance;
use Huuray\Result\ListTemplatesResult;
use Huuray\Result\PdfTemplate;
use Huuray\Result\Template;
use Huuray\RetryOptions;
use Huuray\Tests\Support\MockResponse;
use Huuray\Tests\Support\TestClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResourcesTest extends TestCase
{
    // --------------------------------------------------------------- balances

    public function testBalancesMapsRowsAndKeepsAmountsInMinorUnits(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(json: [
            'Balances' => [
                ['Currency' => 'DKK', 'Balance' => 50_000, 'Master' => true],
                ['Currency' => 'EUR', 'Balance' => 1234, 'Master' => false],
            ],
        ]));

        $balances = $client->balances->list()->balances;

        self::assertSame('GET', $transport->calls[0]->method);
        self::assertSame('/v4/Balance', $transport->calls[0]->path);
        self::assertTrue($transport->calls[0]->bodyOmitted);
        self::assertEquals([new Balance('DKK', 50_000, true), new Balance('EUR', 1234, false)], $balances);
    }

    public function testBalancesReturnsAnEmptyListWhenTheApiSendsNull(): void
    {
        [$client] = TestClient::make(new MockResponse(json: ['Balances' => null]));

        self::assertSame([], $client->balances->list()->balances);
    }

    // -------------------------------------------------------------- catalogue

    public function testCatalogueDefaultsAllToFalseYourProductsWithTokensAndDiscount(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(json: ['Products' => []]));

        $client->catalogue->list();

        self::assertSame('POST', $transport->calls[0]->method);
        self::assertSame(['All' => false], $transport->calls[0]->body);
    }

    public function testCataloguePassesAllThroughWhenRequestingTheWholeCatalogue(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(json: ['Products' => []]));

        $client->catalogue->list(all: true);

        self::assertSame(['All' => true], $transport->calls[0]->body);
    }

    public function testCatalogueMapsProductFields(): void
    {
        [$client] = TestClient::make(new MockResponse(json: [
            'Products' => [[
                'ProductToken' => 'tok',
                'BrandName' => 'Example',
                'Country' => 'Denmark',
                'CountryCode' => 'DK',
                'Discount' => 4.5,
                'Denominations' => '100,200',
                'Currency' => 'DKK',
                'RealTimeStock' => 'RealTime',
                'Categories' => 'Food,Fun',
                'LanguageCode' => 'da',
                'Active' => true,
                'BrandDescription' => 'An invented brand',
                'RedemptionInstructions' => 'Show the code',
                'LogoFile' => 'https://logo.example/x.png',
            ]],
        ]));

        $product = $client->catalogue->list()->products[0];

        self::assertSame('tok', $product->productToken);
        self::assertSame('Example', $product->brandName);
        self::assertSame('Denmark', $product->country);
        self::assertSame('DK', $product->countryCode);
        self::assertSame(4.5, $product->discount);
        self::assertSame('100,200', $product->denominations);
        self::assertSame('DKK', $product->currency);
        self::assertSame('RealTime', $product->realTimeStock);
        self::assertSame('Food,Fun', $product->categories);
        self::assertSame('da', $product->languageCode);
        self::assertTrue($product->active);
        self::assertSame('An invented brand', $product->brandDescription);
        self::assertSame('Show the code', $product->redemptionInstructions);
        self::assertSame('https://logo.example/x.png', $product->logoFile);
    }

    public function testCatalogueWithAllReturnsNullTokensRatherThanInventingThem(): void
    {
        [$client] = TestClient::make(new MockResponse(json: ['Products' => [['BrandName' => 'Example', 'Active' => true]]]));

        $product = $client->catalogue->list(all: true)->products[0];

        self::assertNull($product->productToken);
        self::assertNull($product->discount);
    }

    // -------------------------------------------------------------- templates

    public function testTemplatesSendsNoRequestBodyBecauseTheSpecDeclaresNone(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(json: ['Templates' => []]));

        $client->templates->list();

        self::assertSame('POST', $transport->calls[0]->method);
        self::assertSame('/v4/Template', $transport->calls[0]->path);
        self::assertTrue($transport->calls[0]->bodyOmitted);
        self::assertNull($transport->calls[0]->rawBody);
        self::assertArrayNotHasKey('Content-Type', $transport->calls[0]->headers);
    }

    public function testTemplatesMapsTemplateFields(): void
    {
        [$client] = TestClient::make(new MockResponse(json: [
            'Templates' => [[
                'Id' => 42,
                'Name' => 'Default',
                'Type' => 'Email',
                'Language' => 'da',
                'Sender' => 'rewards@example.com',
                'Subject' => 'A gift',
                'FormattedText' => '<p>Hi</p>',
                'PlainText' => 'Hi',
            ]],
        ]));

        self::assertEquals(
            [new Template(42, 'Default', 'Email', 'da', 'rewards@example.com', 'A gift', '<p>Hi</p>', 'Hi')],
            $client->templates->list()->templates,
        );
    }

    public function testTemplatesMapsEveryPdfTemplateFieldKeepingNullCountryAndBrandAsNull(): void
    {
        [$client] = TestClient::make(new MockResponse(json: [
            'Templates' => [],
            'PDFTemplates' => [
                [
                    'Uid' => '00000000-0000-4000-8000-00000000a001',
                    'Name' => 'Example PDF - Any',
                    'Type' => 'Example type',
                    'Language' => 'en',
                    'Country' => null,
                    'BrandName' => null,
                ],
                [
                    'Uid' => '00000000-0000-4000-8000-00000000a002',
                    'Name' => 'Example PDF - Scoped',
                    'Type' => 'Example type',
                    'Language' => 'da',
                    'Country' => 'Examplestan',
                    'BrandName' => 'Example Brand',
                ],
            ],
        ]));

        self::assertEquals(
            new ListTemplatesResult(
                templates: [],
                pdfTemplates: [
                    new PdfTemplate('00000000-0000-4000-8000-00000000a001', 'Example PDF - Any', 'Example type', 'en', null, null),
                    new PdfTemplate('00000000-0000-4000-8000-00000000a002', 'Example PDF - Scoped', 'Example type', 'da', 'Examplestan', 'Example Brand'),
                ],
            ),
            $client->templates->list(),
        );
    }

    public function testTemplatesReturnsPdfTemplatesEvenWhenThereAreNoDeliveryTemplates(): void
    {
        // The bug this guards: mapping only Templates silently dropped every PDF template.
        [$client] = TestClient::make(new MockResponse(json: [
            'Templates' => [],
            'PDFTemplates' => [['Uid' => 'pdf-1', 'Name' => 'Example PDF']],
        ]));

        $result = $client->templates->list();

        self::assertSame([], $result->templates);
        self::assertEquals([new PdfTemplate('pdf-1', 'Example PDF', null, null, null, null)], $result->pdfTemplates);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function missingPdfTemplates(): iterable
    {
        yield 'null' => [['Templates' => [], 'PDFTemplates' => null]];
        yield 'absent' => [['Templates' => []]];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('missingPdfTemplates')]
    public function testTemplatesMapsANullOrAbsentPdfTemplatesToAnEmptyList(array $body): void
    {
        [$client] = TestClient::make(new MockResponse(json: $body));

        self::assertEquals(new ListTemplatesResult([], []), $client->templates->list());
    }

    public function testAnAccountWithNoTemplatesGetsA404NotAnEmptyList(): void
    {
        [$client] = TestClient::make(new MockResponse(status: 404, json: ['Status' => 404, 'StatusMessage' => 'There were no active templates']));

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('There were no active templates');

        $client->templates->list();
    }

    // ------------------------------------------------------------------ stock

    public function testStockOmitsValueWhenNotSupplied(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(json: ['Stock' => 10]));

        $result = $client->stock->check(productToken: 'tok');

        self::assertSame(['ProductToken' => 'tok'], $transport->calls[0]->body);
        self::assertSame(10, $result->stock);
    }

    public function testStockSendsValueWhenSupplied(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(json: ['Stock' => null]));

        $result = $client->stock->check(productToken: 'tok', value: 5000);

        self::assertSame(['ProductToken' => 'tok', 'Value' => 5000], $transport->calls[0]->body);
        self::assertNull($result->stock);
    }

    /** @return iterable<string, array{float}> */
    public static function floatValues(): iterable
    {
        yield 'fractional' => [50.5];
        yield 'whole-number float' => [50.00];
    }

    #[DataProvider('floatValues')]
    public function testStockRejectsAFloatValueBeforeSendingAnything(float $value): void
    {
        [$client, $transport] = TestClient::make();

        try {
            // @phpstan-ignore argument.type (deliberately wrong, as an untyped caller might pass)
            $client->stock->check(productToken: 'tok', value: $value);
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('integer in minor units', $e->getMessage());
        }

        self::assertCount(0, $transport->calls);
    }

    // --------------------------------------------------------- exchange rates

    public function testExchangeRatesSendsTheCurrenciesAsQueryParameters(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(json: ['ExchangeRate' => 7.46, 'Spread' => 2]));

        $rate = $client->exchangeRates->get(from: 'EUR', to: 'DKK');

        self::assertSame('GET', $transport->calls[0]->method);
        self::assertSame('/v4/ExchangeRates', $transport->calls[0]->path);
        self::assertSame(['FromCurrency' => 'EUR', 'ToCurrency' => 'DKK'], $transport->calls[0]->query);
        self::assertTrue($transport->calls[0]->bodyOmitted);
        self::assertSame(7.46, $rate->exchangeRate);
        self::assertSame(2, $rate->spread);
    }

    // --------------------------------------------------------- read retries

    public function testEveryReadIsRetryable(): void
    {
        $reads = [
            'balances' => static fn(HuurayClient $client): object => $client->balances->list(),
            'catalogue' => static fn(HuurayClient $client): object => $client->catalogue->list(),
            'templates' => static fn(HuurayClient $client): object => $client->templates->list(),
            'stock' => static fn(HuurayClient $client): object => $client->stock->check(productToken: 'tok'),
            'exchangeRates' => static fn(HuurayClient $client): object => $client->exchangeRates->get(from: 'EUR', to: 'DKK'),
            'search' => static fn(HuurayClient $client): object => $client->orders->search(refId: 'r'),
        ];

        foreach ($reads as $name => $read) {
            [$client, $transport] = TestClient::make(
                [new MockResponse(status: 503), new MockResponse(json: new \stdClass())],
                retry: new RetryOptions(maxRetries: 1, baseDelayMs: 1),
            );

            $read($client);

            self::assertCount(2, $transport->calls, $name . ' should be retried once after a 503');
        }
    }
}
