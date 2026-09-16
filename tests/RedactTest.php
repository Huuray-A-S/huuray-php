<?php

declare(strict_types=1);

namespace Huuray\Tests;

use Huuray\Http\HttpRequest;
use Huuray\Http\HttpResponse;
use Huuray\HuurayClient;
use Huuray\Recipient;
use Huuray\Redact;
use Huuray\Result\CreateSyncOrderResult;
use Huuray\Result\SearchOrdersResult;
use Huuray\Result\Voucher;
use Huuray\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class RedactTest extends TestCase
{
    // ------------------------------------------------------------- redact()

    public function testRemovesVoucherCodesTheyAreBearerInstruments(): void
    {
        $out = Redact::safeJson([
            'Vouchers' => [['ID' => 1, 'Code' => 'REAL-CODE-123', 'CVV' => '999', 'RedeemLink' => 'https://r/abc']],
        ]);

        self::assertStringNotContainsString('REAL-CODE-123', $out);
        self::assertStringNotContainsString('999', $out);
        self::assertStringNotContainsString('https://r/abc', $out);
        self::assertStringContainsString(Redact::BEARER_MARKER, $out);
    }

    public function testRedactsTheMappedCamelCaseFieldsToo(): void
    {
        $out = Redact::safeJson(['vouchers' => [['code' => 'REAL', 'cvv' => '1', 'redeemLink' => 'https://x']]]);

        self::assertStringNotContainsString('REAL', $out);
        self::assertStringNotContainsString('https://x', $out);
    }

    public function testRedactsResultObjectsNotOnlyRawBodies(): void
    {
        $result = new SearchOrdersResult('uid', 'ref', [self::voucher()]);

        $out = Redact::safeJson($result);

        self::assertStringNotContainsString('REAL-CODE-123', $out);
        self::assertStringNotContainsString('jane@example.com', $out);
        self::assertStringContainsString('"orderUid":"uid"', $out);
    }

    public function testKeepsIdsAndExpiryWhichAreSafeAndUsefulInALog(): void
    {
        $out = Redact::redact(['ID' => 42, 'Expires' => '2027-01-01', 'Code' => 'SECRET']);

        self::assertSame(['ID' => 42, 'Expires' => '2027-01-01', 'Code' => Redact::BEARER_MARKER], $out);
    }

    public function testMasksPersonalDataWithoutDestroyingItEntirely(): void
    {
        $out = Redact::redact(['Email' => 'jane@example.com']);

        self::assertSame(['Email' => 'ja***om'], $out);
    }

    public function testMasksShortPersonalDataCompletely(): void
    {
        self::assertSame(['phone' => '***'], Redact::redact(['phone' => '1234']));
    }

    public function testMasksCredentials(): void
    {
        $out = Redact::safeJson(['apiToken' => 'tok_live_abcdef', 'apiSecret' => 'shhh-secret']);

        self::assertStringNotContainsString('tok_live_abcdef', $out);
        self::assertStringNotContainsString('shhh-secret', $out);
    }

    public function testMasksTheAuthHeaders(): void
    {
        $out = Redact::safeJson(['X-API-TOKEN' => 'tok_live_abcdef', 'X-API-HASH' => str_repeat('a', 128)]);

        self::assertStringNotContainsString('tok_live_abcdef', $out);
        self::assertStringNotContainsString(str_repeat('a', 128), $out);
    }

    public function testLeavesEmptyAndNullValuesAloneRatherThanInventingAMarker(): void
    {
        self::assertSame(['Code' => null, 'CVV' => ''], Redact::redact(['Code' => null, 'CVV' => '']));
    }

    public function testWalksNestedStructures(): void
    {
        $out = Redact::safeJson(['a' => ['b' => ['c' => [['Code' => 'DEEP']]]]]);

        self::assertStringNotContainsString('DEEP', $out);
    }

    public function testDoesNotRecurseForeverOnACycle(): void
    {
        $cyclic = new \stdClass();
        $cyclic->name = 'x';
        $cyclic->self = $cyclic;

        self::assertIsArray(Redact::redact($cyclic));
    }

    public function testLeavesScalarsUntouched(): void
    {
        self::assertSame(5, Redact::redact(5));
        self::assertSame('text', Redact::redact('text'));
        self::assertNull(Redact::redact(null));
    }

    // ------------------------------------------------ var_dump() / print_r()

    public function testAVoucherNeverDumpsItsCode(): void
    {
        $voucher = self::voucher();

        foreach ([self::varDump($voucher), print_r($voucher, true)] as $dump) {
            self::assertStringNotContainsString('REAL-CODE-123', $dump);
            self::assertStringNotContainsString('SECRET-CVV-742', $dump);
            self::assertStringNotContainsString('https://redeem.example/abc', $dump);
            self::assertStringNotContainsString('jane@example.com', $dump);
            self::assertStringNotContainsString('+4512345678', $dump);
            self::assertStringContainsString(Redact::BEARER_MARKER, $dump);
            self::assertStringContainsString('2027-01-01', $dump);
        }
    }

    public function testResultsHoldingVouchersNeverDumpTheirCodes(): void
    {
        $results = [
            new CreateSyncOrderResult('uid', 'ref', [self::voucher()]),
            new SearchOrdersResult('uid', 'ref', [self::voucher()]),
        ];

        foreach ($results as $result) {
            foreach ([self::varDump($result), print_r($result, true)] as $dump) {
                self::assertStringNotContainsString('REAL-CODE-123', $dump);
                self::assertStringNotContainsString('jane@example.com', $dump);
                self::assertStringContainsString('uid', $dump);
            }
        }
    }

    public function testARecipientMasksContactDetailsWhenDumped(): void
    {
        $recipient = new Recipient(name: 'Jane', email: 'jane@example.com', phone: '+4512345678', refId: 'r-1');

        $dump = print_r($recipient, true);

        self::assertStringNotContainsString('jane@example.com', $dump);
        self::assertStringNotContainsString('+4512345678', $dump);
        self::assertStringContainsString('ja***om', $dump);
        self::assertStringContainsString('r-1', $dump);
    }

    public function testDeliberateReadsAndJsonEncodeStayUnredacted(): void
    {
        // Reading your own data is not logging it: property access and
        // json_encode() return the real values.
        $voucher = self::voucher();

        self::assertSame('REAL-CODE-123', $voucher->code);
        self::assertSame('jane@example.com', $voucher->recipient?->email);
        self::assertStringContainsString('REAL-CODE-123', json_encode($voucher, JSON_THROW_ON_ERROR));
    }

    public function testTransportObjectsNeverDumpCredentialsOrBodies(): void
    {
        $request = new HttpRequest(
            'POST',
            'https://api.huuray.com/v4/Search',
            ['X-API-TOKEN' => 'tok_live_abcdef', 'X-API-HASH' => str_repeat('b', 128)],
            '{"RecipientEmail":"jane@example.com"}',
            30_000,
        );
        $response = new HttpResponse(200, '{"Vouchers":[{"Code":"REAL-CODE-123"}]}');

        $dump = print_r($request, true) . print_r($response, true);

        self::assertStringNotContainsString('tok_live_abcdef', $dump);
        self::assertStringNotContainsString(str_repeat('b', 128), $dump);
        self::assertStringNotContainsString('jane@example.com', $dump);
        self::assertStringNotContainsString('REAL-CODE-123', $dump);
    }

    public function testTheClientNeverDumpsItsCredentials(): void
    {
        $client = new HuurayClient(apiToken: 'tok_live_abcdef', apiSecret: 'shhh-secret-9f2c', transport: new FakeTransport());

        // A resource holds the client, so dumping one must not reach the secret either.
        $dump = print_r($client, true) . print_r($client->orders, true);
        ob_start();
        var_dump($client);
        $dump .= (string) ob_get_clean();

        self::assertStringContainsString('api.huuray.com', $dump);
        self::assertStringNotContainsString('tok_live_abcdef', $dump);
        self::assertStringNotContainsString('shhh-secret-9f2c', $dump);
    }

    private static function voucher(): Voucher
    {
        return new Voucher(
            id: 1,
            code: 'REAL-CODE-123',
            cvv: 'SECRET-CVV-742',
            redeemLink: 'https://redeem.example/abc',
            expires: '2027-01-01',
            recipient: new Recipient(name: 'Jane', email: 'jane@example.com', phone: '+4512345678'),
        );
    }

    private static function varDump(mixed $value): string
    {
        ob_start();
        var_dump($value);

        return (string) ob_get_clean();
    }
}
