<?php

declare(strict_types=1);

namespace Huuray\Tests;

use Huuray\Exception\ConnectionException;
use Huuray\Exception\HuurayException;
use Huuray\Exception\IndeterminateOrderException;
use Huuray\Exception\ServerException;
use Huuray\Exception\ValidationException;
use Huuray\Http\TransportException;
use Huuray\Http\TransportTimeoutException;
use Huuray\HuurayClient;
use Huuray\Recipient;
use Huuray\Resources\OrdersResource;
use Huuray\Result\CancelledVoucher;
use Huuray\RetryOptions;
use Huuray\Tests\Support\MockResponse;
use Huuray\Tests\Support\TestClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrdersTest extends TestCase
{
    private const PDF_UID = '00000000-0000-4000-8000-00000000b001';

    // ------------------------------------------------------------ minor units

    public function testRejectsAFractionalValueBeforeSendingAnything(): void
    {
        [$client, $transport] = TestClient::make();

        $this->assertInvalidArgument(
            // @phpstan-ignore argument.type (deliberately wrong, as an untyped caller might pass)
            static fn() => $client->orders->create(productToken: 'tok', value: 50.0001, currency: 'DKK', quantity: 1),
            '/integer in minor units/',
        );
        self::assertCount(0, $transport->calls);
    }

    public function testRejectsAWholeNumberFloatTooPhpCatchesWhatJavaScriptCannot(): void
    {
        // In a caller's file without strict_types, an `int` parameter would silently
        // turn 50.00 into 50 and order 0.50. The float never reaches the API.
        [$client, $transport] = TestClient::make();

        $this->assertInvalidArgument(
            // @phpstan-ignore argument.type (deliberately wrong, as an untyped caller might pass)
            static fn() => $client->orders->create(productToken: 'tok', value: 50.00, currency: 'DKK', quantity: 1),
            '/received the float 50\.0\./',
        );
        self::assertCount(0, $transport->calls);
    }

    /** @return iterable<string, array{string}> */
    public static function amountCallsFromANonStrictFile(): iterable
    {
        $recipient = 'recipient: new \Huuray\Recipient(email: "a@example.com"), templateId: 42, refId: "r"';

        yield 'orders->create' => ['$client->orders->create(productToken: "tok", value: 50.00, currency: "DKK", quantity: 1, refId: "r");'];
        yield 'orders->createSync' => ['$client->orders->createSync(productToken: "tok", value: 50.00, currency: "DKK", quantity: 1, refId: "r");'];
        yield 'orders->sendReward' => ['$client->orders->sendReward(productToken: "tok", value: 50.00, currency: "DKK", ' . $recipient . ');'];
        yield 'client->sendReward' => ['$client->sendReward(productToken: "tok", value: 50.00, currency: "DKK", ' . $recipient . ');'];
        yield 'stock->check' => ['$client->stock->check(productToken: "tok", value: 50.00);'];
    }

    #[DataProvider('amountCallsFromANonStrictFile')]
    public function testRejectsAWholeNumberFloatFromACallerWithoutStrictTypes(string $call): void
    {
        // eval()'d code does not inherit this file's strict_types, so it calls the SDK
        // exactly as a caller's file without the declare does — where an `int`
        // parameter would silently coerce 50.00 to 50 and order 0.50.
        [$client, $transport] = TestClient::make(new MockResponse(json: ['OrderUID' => 'x', 'Stock' => 1]));

        $this->assertInvalidArgument(
            static function () use ($client, $call): void {
                eval($call);
            },
            '/received the float 50\.0\./',
        );
        self::assertCount(0, $transport->calls);
    }

    public function testEvalRunsWithoutStrictTypesSoTheNonStrictCallerTestsAreNotVacuous(): void
    {
        // Under strict_types, passing true to an `int` parameter is a TypeError; in a
        // caller's file without the declare, PHP coerces it to 1. This proves eval()'d
        // code is the latter, so the tests that use it exercise real coercion.
        self::assertSame(1, eval('return (static fn(int $n): int => $n)(true);'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function coercibleQuantityAndValueCallsFromANonStrictFile(): iterable
    {
        $floatQuantity = '/^quantity must be a positive integer, received the float %s\. /';
        $boolQuantity = '/^quantity must be a positive integer, received the bool true\. /';
        $boolValue = '/^value must be an integer in minor units \(50\.00 is 5000\), received the bool true\. /';

        foreach (['create', 'createSync'] as $method) {
            $call = '$client->orders->' . $method . '(productToken: "tok", value: %s, currency: "DKK", quantity: %s, refId: "r");';

            // 1.5 would become 1 — `$budget / $denomination` silently ordering fewer codes.
            yield "orders->{$method} quantity 1.5" => [sprintf($call, '5000', '1.5'), sprintf($floatQuantity, '1\.5')];
            yield "orders->{$method} quantity 2.0" => [sprintf($call, '5000', '2.0'), sprintf($floatQuantity, '2\.0')];
            yield "orders->{$method} quantity true" => [sprintf($call, '5000', 'true'), $boolQuantity];
            yield "orders->{$method} value true" => [sprintf($call, 'true', '1'), $boolValue];
        }

        // 25.9 would become 25 and pass the synchronous limit on the truncated value.
        yield 'orders->createSync quantity 25.9' => [
            '$client->orders->createSync(productToken: "tok", value: 5000, currency: "DKK", quantity: 25.9, refId: "r");',
            sprintf($floatQuantity, '25\.9'),
        ];

        $recipient = 'recipient: new \Huuray\Recipient(email: "a@example.com"), templateId: 42, refId: "r"';
        yield 'orders->sendReward value true' => ['$client->orders->sendReward(productToken: "tok", value: true, currency: "DKK", ' . $recipient . ');', $boolValue];
        yield 'client->sendReward value true' => ['$client->sendReward(productToken: "tok", value: true, currency: "DKK", ' . $recipient . ');', $boolValue];
        yield 'stock->check value true' => ['$client->stock->check(productToken: "tok", value: true);', $boolValue];
    }

    #[DataProvider('coercibleQuantityAndValueCallsFromANonStrictFile')]
    public function testRejectsAFloatOrBoolQuantityOrValueFromACallerWithoutStrictTypes(string $call, string $messagePattern): void
    {
        // An `int` (or `int|float`) parameter would coerce these before the SDK saw them.
        [$client, $transport] = TestClient::make(new MockResponse(json: ['OrderUID' => 'x', 'Stock' => 1]));

        $this->assertInvalidArgument(
            static function () use ($client, $call): void {
                eval($call);
            },
            $messagePattern,
        );
        self::assertCount(0, $transport->calls);
    }

    public function testExplainsTheRealFailureMajorUnitsOrderOneHundredth(): void
    {
        [$client] = TestClient::make();

        $this->assertInvalidArgument(
            // @phpstan-ignore argument.type (deliberately wrong, as an untyped caller might pass)
            static fn() => $client->orders->create(productToken: 'tok', value: 50.5, currency: 'DKK', quantity: 1),
            '#1/100th of the intended amount#',
        );
    }

    public function testAdmitsTheMixupNoGuardCanCatch(): void
    {
        [$client] = TestClient::make();

        $this->assertInvalidArgument(
            // @phpstan-ignore argument.type (deliberately wrong, as an untyped caller might pass)
            static fn() => $client->orders->create(productToken: 'tok', value: 50.5, currency: 'DKK', quantity: 1),
            '/whole-number int such as 50, which is a valid order for 0\.50/',
        );
    }

    /** @return iterable<string, array{string}> */
    public static function orderMethods(): iterable
    {
        yield 'create' => ['create'];
        yield 'createSync' => ['createSync'];
        yield 'sendReward' => ['sendReward'];
    }

    #[DataProvider('orderMethods')]
    public function testEveryOrderMethodRejectsAFloatValue(string $method): void
    {
        [$client, $transport] = TestClient::make();

        $this->assertInvalidArgument(
            static fn() => self::order($client, $method, value: 25.0),
            '/integer in minor units/',
        );
        self::assertCount(0, $transport->calls);
    }

    public function testSendsTheIntegerThroughUntouched(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(json: ['OrderUID' => 'x']));
        $client->orders->create(productToken: 'tok', value: 5000, currency: 'DKK', quantity: 1);

        self::assertSame(5000, $transport->calls[0]->field('Product', 'Value'));
    }

    public function testRejectsANonPositiveQuantity(): void
    {
        [$client, $transport] = TestClient::make();

        $this->assertInvalidArgument(
            static fn() => $client->orders->create(productToken: 'tok', value: 5000, currency: 'DKK', quantity: 0),
            '/quantity must be a positive integer/',
        );
        self::assertCount(0, $transport->calls);
    }

    // ---------------------------------------------------- sync vs async ordering

    public function testCreateSendsSyncFalse(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(json: ['OrderUID' => 'x', 'RefID' => 'r']));
        $result = $client->orders->create(productToken: 'tok', value: 5000, currency: 'DKK', quantity: 1);

        self::assertFalse($transport->calls[0]->field('Sync'));
        self::assertSame('x', $result->orderUid);
        self::assertSame('r', $result->refId);
    }

    public function testCreateSyncSendsSyncTrue(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(json: ['OrderUID' => 'x', 'Vouchers' => []]));
        $client->orders->createSync(productToken: 'tok', value: 5000, currency: 'DKK', quantity: 1);

        self::assertTrue($transport->calls[0]->field('Sync'));
    }

    public function testCreateSyncEnforcesTheDocumented25CodeLimit(): void
    {
        [$client, $transport] = TestClient::make();

        $this->assertInvalidArgument(
            static fn() => $client->orders->createSync(
                productToken: 'tok',
                value: 5000,
                currency: 'DKK',
                quantity: OrdersResource::SYNC_QUANTITY_LIMIT + 1,
            ),
            '/limited to 25/',
        );
        self::assertCount(0, $transport->calls);
    }

    public function testCreateHasNoSuchLimit(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(json: ['OrderUID' => 'x']));
        $client->orders->create(productToken: 'tok', value: 5000, currency: 'DKK', quantity: 200);

        self::assertSame(200, $transport->calls[0]->field('Product', 'Quantity'));
    }

    public function testCreateSyncReturnsVouchers(): void
    {
        [$client] = TestClient::make(new MockResponse(json: [
            'OrderUID' => 'x',
            'RefID' => 'r',
            'Vouchers' => [[
                'ID' => 1,
                'Code' => 'ABC',
                'CVV' => '123',
                'RedeemLink' => 'https://r/1',
                'Expires' => '2027-01-01T00:00:00',
                'Recipient' => ['Name' => 'Jane', 'Email' => 'jane@example.com', 'RefID' => 'r-jane'],
            ]],
        ]));

        $result = $client->orders->createSync(productToken: 'tok', value: 5000, currency: 'DKK', quantity: 1);
        $voucher = $result->vouchers[0];

        self::assertSame(1, $voucher->id);
        self::assertSame('ABC', $voucher->code);
        self::assertSame('123', $voucher->cvv);
        self::assertSame('https://r/1', $voucher->redeemLink);
        self::assertSame('2027-01-01T00:00:00', $voucher->expires);
        self::assertEquals(new Recipient(name: 'Jane', email: 'jane@example.com', refId: 'r-jane'), $voucher->recipient);
    }

    public function testSurfacesBlankedCodesAsNullRatherThanPretending(): void
    {
        // Codes come back empty unless ReturnCode is enabled on the account.
        [$client] = TestClient::make(new MockResponse(json: [
            'OrderUID' => 'x',
            'Vouchers' => [['ID' => 1, 'Code' => null, 'CVV' => null, 'RedeemLink' => null]],
        ]));

        $voucher = $client->orders->createSync(productToken: 'tok', value: 5000, currency: 'DKK', quantity: 1)->vouchers[0];

        self::assertSame(1, $voucher->id);
        self::assertNull($voucher->code);
        self::assertNull($voucher->cvv);
        self::assertNull($voucher->redeemLink);
        self::assertNull($voucher->recipient);
    }

    // --------------------------------------------------- recipient validation

    public function testRequiresRecipientsWhenADeliveryTemplateIsSet(): void
    {
        [$client, $transport] = TestClient::make();

        $this->assertInvalidArgument(
            static fn() => $client->orders->create(productToken: 'tok', value: 5000, currency: 'DKK', quantity: 1, templateId: 42),
            '/recipients is required/',
        );
        self::assertCount(0, $transport->calls);
    }

    public function testAcceptsExactlyOneRecipientForAMultiCodeOrder(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(json: ['OrderUID' => 'x']));
        $client->orders->create(
            productToken: 'tok',
            value: 5000,
            currency: 'DKK',
            quantity: 5,
            templateId: 42,
            recipients: [new Recipient(email: 'a@example.com')],
        );

        self::assertCount(1, $transport->calls);
    }

    public function testAcceptsARecipientCountMatchingQuantity(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(json: ['OrderUID' => 'x']));
        $client->orders->create(
            productToken: 'tok',
            value: 5000,
            currency: 'DKK',
            quantity: 2,
            templateId: 42,
            recipients: [new Recipient(email: 'a@example.com'), new Recipient(email: 'b@example.com')],
        );

        self::assertCount(1, $transport->calls);
    }

    public function testRejectsACountThatIsNeitherOneNorQuantity(): void
    {
        [$client, $transport] = TestClient::make();

        $this->assertInvalidArgument(
            static fn() => $client->orders->create(
                productToken: 'tok',
                value: 5000,
                currency: 'DKK',
                quantity: 5,
                templateId: 42,
                recipients: [new Recipient(email: 'a@example.com'), new Recipient(email: 'b@example.com')],
            ),
            '/either 1 entry or exactly quantity/',
        );
        self::assertCount(0, $transport->calls);
    }

    public function testRejectsARecipientThatIsNotARecipientObject(): void
    {
        [$client, $transport] = TestClient::make();

        $this->assertInvalidArgument(
            // @phpstan-ignore argument.type (deliberately wrong, as an untyped caller might pass)
            static fn() => $client->orders->create(productToken: 'tok', value: 5000, currency: 'DKK', quantity: 1, templateId: 42, recipients: [['email' => 'a@example.com']]),
            '/list of Huuray\\\\Recipient objects/',
        );
        self::assertCount(0, $transport->calls);
    }

    public function testAllowsNoRecipientsWhenThereIsNoDeliveryTemplate(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(json: ['OrderUID' => 'x']));
        $client->orders->create(productToken: 'tok', value: 5000, currency: 'DKK', quantity: 1);

        self::assertCount(1, $transport->calls);
        self::assertFalse($transport->calls[0]->hasField('Recipients'));
    }

    public function testOmitsRecipientFieldsThatWereNotSupplied(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(json: ['OrderUID' => 'x']));
        $client->orders->create(
            productToken: 'tok',
            value: 5000,
            currency: 'DKK',
            quantity: 1,
            templateId: 42,
            recipients: [new Recipient(email: 'a@example.com')],
        );

        self::assertSame([['Email' => 'a@example.com']], $transport->calls[0]->field('Recipients'));
    }

    public function testSendsAnEmptyRecipientAsAJsonObjectNotAList(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(json: ['OrderUID' => 'x']));
        $client->orders->create(productToken: 'tok', value: 5000, currency: 'DKK', quantity: 1, recipients: [new Recipient()]);

        self::assertStringContainsString('"Recipients":[{}]', (string) $transport->calls[0]->rawBody);
    }

    // ------------------------------------------------------------- date-times

    public function testFormatsADateTimeInUtcLikeJavaScriptToIsoString(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(json: ['OrderUID' => 'x']));
        $client->orders->create(
            productToken: 'tok',
            value: 5000,
            currency: 'DKK',
            quantity: 1,
            expires: new \DateTimeImmutable('2027-01-01 01:00:00', new \DateTimeZone('Europe/Copenhagen')),
            deliveryDatetime: new \DateTime('2026-09-01 11:00:00.25', new \DateTimeZone('Europe/Copenhagen')),
        );

        self::assertSame('2027-01-01T00:00:00.000Z', $transport->calls[0]->field('Product', 'Expires'));
        self::assertSame('2026-09-01T09:00:00.250Z', $transport->calls[0]->field('DeliveryDatetime'));
    }

    public function testPassesAPreformattedStringThroughUntouched(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(json: ['OrderUID' => 'x']));
        $client->orders->create(productToken: 'tok', value: 5000, currency: 'DKK', quantity: 1, deliveryDatetime: '2026-09-01T09:00:00+02:00');

        self::assertSame('2026-09-01T09:00:00+02:00', $transport->calls[0]->field('DeliveryDatetime'));
    }

    // ------------------------------------------------------------ PDF templates

    #[DataProvider('orderMethods')]
    public function testSendsDeliveryPdfTemplateUidWhenPdfTemplateUidIsGiven(string $method): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(json: ['OrderUID' => 'x', 'Vouchers' => []]));
        self::order($client, $method, templateId: 42, pdfTemplateUid: self::PDF_UID);

        self::assertCount(1, $transport->calls);
        self::assertSame(42, $transport->calls[0]->field('DeliveryTemplateId'));
        self::assertSame(self::PDF_UID, $transport->calls[0]->field('DeliveryPDFTemplateUid'));
    }

    public function testClientSendRewardPassesItThroughToo(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(json: ['OrderUID' => 'x']));
        $client->sendReward(
            productToken: 'tok',
            value: 5000,
            currency: 'DKK',
            recipient: new Recipient(email: 'jane@example.com'),
            templateId: 42,
            refId: 'r-pdf',
            pdfTemplateUid: self::PDF_UID,
        );

        self::assertCount(1, $transport->calls);
        self::assertSame(self::PDF_UID, $transport->calls[0]->field('DeliveryPDFTemplateUid'));
    }

    #[DataProvider('orderMethods')]
    public function testOmitsTheDeliveryPdfTemplateUidKeyWhenNotGiven(string $method): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(json: ['OrderUID' => 'x', 'Vouchers' => []]));
        self::order($client, $method, templateId: 42);

        self::assertFalse($transport->calls[0]->hasField('DeliveryPDFTemplateUid'));
    }

    /** @return iterable<string, array{string}> */
    public static function createMethods(): iterable
    {
        yield 'create' => ['create'];
        yield 'createSync' => ['createSync'];
    }

    #[DataProvider('createMethods')]
    public function testRejectsPdfTemplateUidWithoutTemplateIdBeforeAnyHttpRequest(string $method): void
    {
        [$client, $transport] = TestClient::make();

        $this->assertInvalidArgument(
            static fn() => self::order($client, $method, templateId: null, pdfTemplateUid: self::PDF_UID),
            '/^templateId is required when pdfTemplateUid is set/',
        );
        self::assertCount(0, $transport->calls);
    }

    public function testTheRejectionSaysTheApiNeedsAnEmailTemplate(): void
    {
        [$client] = TestClient::make();

        $this->assertInvalidArgument(
            static fn() => self::order($client, 'create', templateId: null, pdfTemplateUid: self::PDF_UID),
            '/must be an email template/',
        );
    }

    public function testTreatsPdfTemplateUidNullAsNotSuppliedSoItNeedsNoTemplateId(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(json: ['OrderUID' => 'x']));
        $client->orders->create(productToken: 'tok', value: 5000, currency: 'DKK', quantity: 1, pdfTemplateUid: null);

        self::assertCount(1, $transport->calls);
        self::assertFalse($transport->calls[0]->hasField('DeliveryPDFTemplateUid'));
    }

    #[DataProvider('orderMethods')]
    public function testChecksOnlyThatTemplateIdIsPresentNotWhatKindOfTemplateItIs(string $method): void
    {
        // Whether templateId is an email template, and whether the PDF template fits
        // the product's brand and country, is the API's call; the client cannot know.
        [$client, $transport] = TestClient::make(new MockResponse(json: ['OrderUID' => 'x', 'Vouchers' => []]));
        self::order($client, $method, templateId: 7, pdfTemplateUid: self::PDF_UID);

        self::assertCount(1, $transport->calls);
    }

    public function testAnUnavailablePdfTemplateIsTheApisValidationErrorNotAClientCheck(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(
            status: 422,
            json: ['Status' => 422, 'StatusMessage' => 'The PDF template is not available for this product'],
        ));

        try {
            self::order($client, 'create', templateId: 42, pdfTemplateUid: self::PDF_UID);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->httpStatus);
        }
        self::assertCount(1, $transport->calls);
    }

    // -------------------------------------------------------------- sendReward

    public function testSendRewardMakesExactlyOnePostOrderWithQuantity1AndSyncFalse(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(json: ['OrderUID' => 'x', 'RefID' => 'r']));
        $client->orders->sendReward(
            productToken: 'tok',
            value: 5000,
            currency: 'DKK',
            recipient: new Recipient(name: 'Jane', email: 'jane@example.com'),
            templateId: 42,
            refId: 'payroll-2026-08-jane',
        );

        self::assertCount(1, $transport->calls);
        self::assertSame('POST', $transport->calls[0]->method);
        self::assertSame('/v4/Order', $transport->calls[0]->path);
        self::assertSame(1, $transport->calls[0]->field('Product', 'Quantity'));
        self::assertFalse($transport->calls[0]->field('Sync'));
        self::assertSame('payroll-2026-08-jane', $transport->calls[0]->field('RefID'));
        self::assertSame([['Name' => 'Jane', 'Email' => 'jane@example.com']], $transport->calls[0]->field('Recipients'));
    }

    public function testSendRewardRefusesWithoutARefIdAndNeverGeneratesOne(): void
    {
        [$client, $transport] = TestClient::make();

        $this->assertInvalidArgument(
            static fn() => $client->orders->sendReward(
                productToken: 'tok',
                value: 5000,
                currency: 'DKK',
                recipient: new Recipient(email: 'jane@example.com'),
                templateId: 42,
                refId: '',
            ),
            '/refId is required/',
        );
        self::assertCount(0, $transport->calls);
    }

    /** @return iterable<string, array{string}> */
    public static function sendRewardEntryPoints(): iterable
    {
        yield 'client->sendReward' => ['client'];
        yield 'orders->sendReward' => ['orders'];
    }

    #[DataProvider('sendRewardEntryPoints')]
    public function testSendRewardForwardsEveryArgumentIntoTheWireBody(string $entryPoint): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(json: ['OrderUID' => 'x']));
        $arguments = [
            'productToken' => 'tok-forward',
            'value' => 4321,
            'currency' => 'SEK',
            'recipient' => new Recipient(name: 'Fwd', email: 'fwd@example.com', phone: '+4511110000', refId: 'r-fwd'),
            'templateId' => 77,
            'refId' => 'ref-forward',
            'pdfTemplateUid' => self::PDF_UID,
            'expires' => '2027-03-01T00:00:00Z',
            'deliveryDatetime' => '2026-10-01T08:00:00Z',
            'personalMessage' => 'Forwarded',
        ];

        if ($entryPoint === 'client') {
            $client->sendReward(...$arguments);
        } else {
            $client->orders->sendReward(...$arguments);
        }

        self::assertCount(1, $transport->calls);
        self::assertSame(
            [
                'Product' => [
                    'Token' => 'tok-forward',
                    'Value' => 4321,
                    'Currency' => 'SEK',
                    'Quantity' => 1,
                    'Expires' => '2027-03-01T00:00:00Z',
                ],
                'Sync' => false,
                'RefID' => 'ref-forward',
                'DeliveryTemplateId' => 77,
                'DeliveryPDFTemplateUid' => self::PDF_UID,
                'DeliveryDatetime' => '2026-10-01T08:00:00Z',
                'PersonalMessage' => 'Forwarded',
                'Recipients' => [['Name' => 'Fwd', 'Email' => 'fwd@example.com', 'Phone' => '+4511110000', 'RefID' => 'r-fwd']],
            ],
            $transport->calls[0]->body,
        );
    }

    public function testSendRewardIsAlsoReachableFromTheClientForTheOneCallCase(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(json: ['OrderUID' => 'x']));
        $result = $client->sendReward(
            productToken: 'tok',
            value: 5000,
            currency: 'DKK',
            recipient: new Recipient(email: 'jane@example.com'),
            templateId: 42,
            refId: 'r-1',
        );

        self::assertCount(1, $transport->calls);
        self::assertSame('x', $result->orderUid);
    }

    // ------------------------------------------------------ indeterminate orders

    /** @return iterable<string, array{MockResponse}> */
    public static function indeterminateOutcomes(): iterable
    {
        yield 'connection drops before the response' => [new MockResponse(throws: new TransportException('cURL error 7: Failed to connect'))];
        yield 'connection drops mid-body, after the request was sent' => [new MockResponse(throws: new TransportException('cURL error 18: transfer closed with outstanding read data remaining'))];
        yield 'timeout while the body streams' => [new MockResponse(throws: new TransportTimeoutException('cURL error 28: Operation timed out'))];
        yield 'garbled 2xx body — the order may well have landed' => [new MockResponse(status: 200, text: 'not json at all')];
        yield 'empty 2xx body' => [new MockResponse(status: 200, text: '')];
        yield '500' => [new MockResponse(status: 500)];
        yield '502' => [new MockResponse(status: 502)];
        yield '503' => [new MockResponse(status: 503)];
    }

    #[DataProvider('indeterminateOutcomes')]
    public function testThrowsIndeterminateOrderExceptionWhenTheOutcomeIsUnknown(MockResponse $response): void
    {
        [$client, $transport] = TestClient::make($response, retry: new RetryOptions(maxRetries: 3, baseDelayMs: 1));

        $e = $this->captureIndeterminate(
            static fn() => $client->orders->create(productToken: 'tok', value: 5000, currency: 'DKK', quantity: 1, refId: 'ref-9'),
        );

        self::assertSame('ref-9', $e->refId);
        self::assertInstanceOf(HuurayException::class, $e);
        self::assertTrue($e->getPrevious() instanceof ConnectionException || $e->getPrevious() instanceof ServerException);
        self::assertCount(1, $transport->calls);
    }

    public function testCarriesTheRefIdAndSaysDoNotRetry(): void
    {
        [$client] = TestClient::make(new MockResponse(status: 502));

        $e = $this->captureIndeterminate(
            static fn() => $client->orders->create(productToken: 'tok', value: 5000, currency: 'DKK', quantity: 1, refId: 'ref-9'),
        );

        self::assertSame('ref-9', $e->refId);
        self::assertStringContainsString('Do NOT retry', $e->getMessage());
        self::assertStringContainsString("search(refId: 'ref-9')", $e->getMessage());
    }

    public function testSaysSoPlainlyWhenNoRefIdWasSent(): void
    {
        [$client] = TestClient::make(new MockResponse(status: 500));

        $e = $this->captureIndeterminate(
            static fn() => $client->orders->create(productToken: 'tok', value: 5000, currency: 'DKK', quantity: 1),
        );

        self::assertNull($e->refId);
        self::assertStringContainsString('No RefID was sent', $e->getMessage());
    }

    #[DataProvider('orderMethods')]
    public function testEveryOrderMethodWrapsAnUnknownOutcome(string $method): void
    {
        [$client] = TestClient::make(new MockResponse(status: 503));

        $this->expectException(IndeterminateOrderException::class);

        self::order($client, $method, templateId: 42);
    }

    public function testAnInvalidCustomNonceIsRejectedBeforeSendingNotReportedAsIndeterminate(): void
    {
        // Refused before the request exists, so the outcome is known: nothing was ordered.
        [$client, $transport] = TestClient::make(nonceFactory: static fn(): string => "abc\ndef");

        try {
            $client->orders->create(productToken: 'tok', value: 5000, currency: 'DKK', quantity: 1, refId: 'ref-9');
            self::fail('Expected the nonce to be rejected.');
        } catch (\InvalidArgumentException|HuurayException $e) {
            self::assertNotInstanceOf(IndeterminateOrderException::class, $e);
            self::assertInstanceOf(\InvalidArgumentException::class, $e);
            self::assertStringNotContainsString(TestClient::TOKEN, $e->getMessage());
        }

        self::assertCount(0, $transport->calls);
    }

    public function testAHeaderRefusedByTheBackstopIsNotReportedAsIndeterminate(): void
    {
        // The constructor check is bypassed, so only the backstop in send() stands
        // between this token and the wire. Refused there, nothing was ordered: were
        // the check inside the transport's error handling, this would read as
        // IndeterminateOrderException; were it after the send, as a call.
        [$client, $transport] = TestClient::withUncheckedApiToken("tok-7f3a9c\r\nX-Injected: yes", new MockResponse(status: 503));

        try {
            $client->orders->create(productToken: 'tok', value: 5000, currency: 'DKK', quantity: 1, refId: 'ref-9');
            self::fail('Expected the request to be refused.');
        } catch (\InvalidArgumentException|HuurayException $e) {
            self::assertNotInstanceOf(IndeterminateOrderException::class, $e);
            self::assertInstanceOf(\InvalidArgumentException::class, $e);
            self::assertStringNotContainsString('tok-7f3a9c', $e->getMessage());
        }

        self::assertCount(0, $transport->calls);
    }

    public function testDoesNotMaskA422ThatOrderWasDefinitivelyRejected(): void
    {
        [$client] = TestClient::make(new MockResponse(status: 422, json: ['Status' => 422, 'StatusMessage' => 'bad']));

        $this->expectException(ValidationException::class);

        $client->orders->create(productToken: 'tok', value: 5000, currency: 'DKK', quantity: 1, refId: 'r');
    }

    public function testWrapsOnlyOrderingNotResendOrCancel(): void
    {
        [$client] = TestClient::make(new MockResponse(status: 503));

        foreach ([static fn() => $client->orders->resend(orderUid: 'x'), static fn() => $client->orders->cancel(orderUid: 'x')] as $call) {
            try {
                $call();
                self::fail('Expected a ServerException.');
            } catch (HuurayException $e) {
                // Exactly the plain 5xx: only ordering is wrapped in IndeterminateOrderException.
                self::assertSame(ServerException::class, $e::class);
            }
        }
    }

    // ------------------------------------------------------------ stack traces

    /** @return iterable<string, array{string}> */
    public static function failingCallsCarryingRecipientData(): iterable
    {
        // Only the call name travels through the provider: the contact details are
        // literals in the test body, so no test frame's own arguments can hold them.
        yield 'orders->create, 422' => ['create'];
        yield 'orders->createSync, 422' => ['createSync'];
        yield 'orders->sendReward, 422' => ['orders->sendReward'];
        yield 'client->sendReward, 422' => ['client->sendReward'];
        yield 'orders->create, 422 whose body echoes the recipient' => ['create-echo'];
        yield 'orders->create, 503 — indeterminate, with the cause chained' => ['create-indeterminate'];
        yield 'orders->search by recipient, 404' => ['search'];
    }

    public function testABodyThatCannotBeEncodedKeepsTheRecipientsOutOfTheTrace(): void
    {
        // A Latin-1 name is not valid UTF-8, so encoding fails before anything is sent.
        // json_encode's own frame would carry the whole body as an argument.
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        [$client, $transport] = TestClient::make();
        $recipient = new Recipient(name: "S\xF8ren Canary", email: 'trace-canary@example.invalid', phone: '+4500990011');
        $traces = '';

        try {
            $client->orders->create(productToken: 'tok', value: 5000, currency: 'DKK', quantity: 1, refId: 'ref-trace', templateId: 42, recipients: [$recipient]);
            self::fail('Expected the body to be rejected.');
        } catch (\InvalidArgumentException $e) {
            for ($exception = $e; $exception !== null; $exception = $exception->getPrevious()) {
                $traces .= print_r($exception->getTrace(), true);
            }
        } finally {
            ini_set('zend.exception_ignore_args', $ignoreArgs === false ? '0' : $ignoreArgs);
        }

        self::assertCount(0, $transport->calls);
        self::assertStringNotContainsString('trace-canary@example.invalid', $traces);
        self::assertStringNotContainsString('+4500990011', $traces);
    }

    #[DataProvider('failingCallsCarryingRecipientData')]
    public function testNoExceptionTraceCarriesTheRecipientsEmailOrPhone(string $call): void
    {
        // Off is PHP's built-in default: exception traces then keep every argument.
        // Forced here so this assertion can never pass vacuously.
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        $status = match ($call) {
            'create-indeterminate' => 503,
            'search' => 404,
            default => 422,
        };

        try {
            // The API may quote a rejected field back; the error path must not leak it either.
            $response = $call === 'create-echo'
                ? new MockResponse(status: 422, json: ['Status' => 422, 'StatusMessage' => 'Invalid recipient trace-canary@example.invalid +4500990011 Trace Canary'])
                : new MockResponse(status: $status);
            [$client, $transport] = TestClient::make($response);
            $recipient = new Recipient(name: 'Trace Canary', email: 'trace-canary@example.invalid', phone: '+4500990011', refId: 'r-trace');
            $caught = null;

            try {
                match ($call) {
                    'create', 'create-echo', 'create-indeterminate' => $client->orders->create(productToken: 'tok-args-are-kept', value: 5000, currency: 'DKK', quantity: 1, refId: 'ref-trace', templateId: 42, recipients: [$recipient]),
                    'createSync' => $client->orders->createSync(productToken: 'tok-args-are-kept', value: 5000, currency: 'DKK', quantity: 1, refId: 'ref-trace', templateId: 42, recipients: [$recipient]),
                    'orders->sendReward' => $client->orders->sendReward(productToken: 'tok-args-are-kept', value: 5000, currency: 'DKK', recipient: $recipient, templateId: 42, refId: 'ref-trace'),
                    'client->sendReward' => $client->sendReward(productToken: 'tok-args-are-kept', value: 5000, currency: 'DKK', recipient: $recipient, templateId: 42, refId: 'ref-trace'),
                    'search' => $client->orders->search(productToken: 'tok-args-are-kept', recipientName: 'Trace Canary', recipientEmail: 'trace-canary@example.invalid', recipientPhone: '+4500990011'),
                    default => throw new \LogicException('Unknown call ' . $call),
                };
            } catch (HuurayException $e) {
                $caught = $e;
            }
        } finally {
            ini_set('zend.exception_ignore_args', $ignoreArgs === false ? '0' : $ignoreArgs);
        }

        self::assertNotNull($caught, 'Expected the call to throw.');
        self::assertCount(1, $transport->calls);
        // The request really carried them, so their absence below means something.
        self::assertStringContainsString('trace-canary@example.invalid', (string) $transport->calls[0]->rawBody);

        $traces = '';
        for ($exception = $caught; $exception !== null; $exception = $exception->getPrevious()) {
            $traces .= print_r($exception->getTrace(), true);
        }

        // Arguments were recorded, and the sensitive ones were replaced.
        self::assertStringContainsString('tok-args-are-kept', $traces);
        self::assertStringContainsString('SensitiveParameterValue', $traces);

        self::assertStringNotContainsString('trace-canary@example.invalid', $traces);
        self::assertStringNotContainsString('+4500990011', $traces);
        self::assertStringNotContainsString('Trace Canary', $traces);
    }

    // --------------------------------------------------------- partial success

    public function testFlagsAPartialCancelAndExposesThePerVoucherOutcome(): void
    {
        [$client] = TestClient::make(new MockResponse(status: 206, json: [
            'OrderUID' => 'uid',
            'OrderCancelled' => false,
            'Vouchers' => [['ID' => 1, 'Cancelled' => true], ['ID' => 2, 'Cancelled' => false]],
        ]));

        $result = $client->orders->cancel(orderUid: 'uid');

        self::assertTrue($result->partial);
        self::assertFalse($result->orderCancelled);
        self::assertSame('uid', $result->orderUid);
        self::assertEquals([new CancelledVoucher(1, true), new CancelledVoucher(2, false)], $result->vouchers);
    }

    public function testDoesNotFlagACleanCancelAsPartial(): void
    {
        [$client] = TestClient::make(new MockResponse(json: ['OrderUID' => 'uid', 'OrderCancelled' => true, 'Vouchers' => []]));

        $result = $client->orders->cancel(orderUid: 'uid');

        self::assertFalse($result->partial);
        self::assertTrue($result->orderCancelled);
    }

    public function testFlagsAPartialResend(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(status: 206, json: ['NumberOfResends' => 3]));

        $result = $client->orders->resend(orderUid: 'uid', voucherId: 7);

        self::assertSame(3, $result->numberOfResends);
        self::assertTrue($result->partial);
        self::assertSame(['OrderUID' => 'uid', 'VoucherID' => 7], $transport->calls[0]->body);
    }

    public function testCancelIsADeleteCarryingAJsonBody(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(json: ['OrderUID' => 'uid', 'OrderCancelled' => true]));

        $client->orders->cancel(orderUid: 'uid', voucherId: 7);

        self::assertSame('DELETE', $transport->calls[0]->method);
        self::assertSame('/v4/Cancel', $transport->calls[0]->path);
        self::assertFalse($transport->calls[0]->bodyOmitted);
        self::assertSame(['OrderUID' => 'uid', 'VoucherID' => 7], $transport->calls[0]->body);
        self::assertSame('application/json', $transport->calls[0]->headers['Content-Type']);
    }

    public function testCancelOmitsVoucherIdToCancelTheWholeOrder(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(json: ['OrderUID' => 'uid', 'OrderCancelled' => true]));

        $client->orders->cancel(orderUid: 'uid');

        self::assertSame(['OrderUID' => 'uid'], $transport->calls[0]->body);
    }

    // ------------------------------------------------------------------ search

    public function testSearchOmitsEveryParameterThatWasNotSupplied(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(json: ['OrderUID' => 'x', 'Vouchers' => []]));

        $client->orders->search(refId: 'ref-1');

        self::assertSame(['RefID' => 'ref-1'], $transport->calls[0]->body);
    }

    public function testSearchWithNoFiltersSendsAnEmptyObject(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(json: ['Vouchers' => []]));

        $client->orders->search();

        self::assertSame('{}', $transport->calls[0]->rawBody);
    }

    public function testSearchIsTheDocumentedWayToReconcileAfterAnIndeterminateOrder(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(json: [
            'OrderUID' => 'uid-7',
            'RefID' => 'ref-9',
            'Vouchers' => [['ID' => 3, 'Expires' => '2027-01-01T00:00:00', 'Recipient' => ['Name' => 'Jane', 'RefID' => 'r-jane']]],
        ]));

        $found = $client->orders->search(refId: 'ref-9');

        self::assertSame('POST', $transport->calls[0]->method);
        self::assertSame('/v4/Search', $transport->calls[0]->path);
        self::assertSame('uid-7', $found->orderUid);
        self::assertSame('ref-9', $found->refId);
        self::assertSame(3, $found->vouchers[0]->id);
        self::assertSame('Jane', $found->vouchers[0]->recipient?->name);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Calls one of the three order methods with a valid order, overriding the given fields.
     */
    private static function order(
        HuurayClient $client,
        string $method,
        int|float $value = 5000,
        ?int $templateId = 42,
        ?string $pdfTemplateUid = null,
    ): object {
        $recipient = new Recipient(email: 'a@example.com');

        return match ($method) {
            'create' => $client->orders->create(
                productToken: 'tok',
                // @phpstan-ignore argument.type (a float here is how the float guards are tested)
                value: $value,
                currency: 'DKK',
                quantity: 1,
                refId: 'r-create',
                templateId: $templateId,
                pdfTemplateUid: $pdfTemplateUid,
                recipients: [$recipient],
            ),
            'createSync' => $client->orders->createSync(
                productToken: 'tok',
                // @phpstan-ignore argument.type (a float here is how the float guards are tested)
                value: $value,
                currency: 'DKK',
                quantity: 1,
                refId: 'r-sync',
                templateId: $templateId,
                pdfTemplateUid: $pdfTemplateUid,
                recipients: [$recipient],
            ),
            'sendReward' => $client->orders->sendReward(
                productToken: 'tok',
                // @phpstan-ignore argument.type (a float here is how the float guards are tested)
                value: $value,
                currency: 'DKK',
                recipient: $recipient,
                templateId: $templateId ?? 42,
                refId: 'r-reward',
                pdfTemplateUid: $pdfTemplateUid,
            ),
            default => throw new \LogicException('Unknown order method ' . $method),
        };
    }

    /** @param \Closure(): mixed $call */
    private function assertInvalidArgument(\Closure $call, string $messagePattern): void
    {
        try {
            $call();
        } catch (\InvalidArgumentException $e) {
            self::assertMatchesRegularExpression($messagePattern, $e->getMessage());

            return;
        }

        self::fail('Expected an InvalidArgumentException.');
    }

    /** @param \Closure(): mixed $call */
    private function captureIndeterminate(\Closure $call): IndeterminateOrderException
    {
        try {
            $call();
        } catch (IndeterminateOrderException $e) {
            return $e;
        }

        self::fail('Expected an IndeterminateOrderException.');
    }
}
