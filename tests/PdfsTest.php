<?php

declare(strict_types=1);

namespace Huuray\Tests;

use Huuray\Exception\ApiException;
use Huuray\Exception\AuthException;
use Huuray\Exception\ConnectionException;
use Huuray\Exception\HuurayException;
use Huuray\Exception\IndeterminateOrderException;
use Huuray\Exception\NotFoundException;
use Huuray\Exception\ServerException;
use Huuray\Exception\TimeoutException;
use Huuray\Exception\ValidationException;
use Huuray\Http\HttpRequest;
use Huuray\Http\HttpResponse;
use Huuray\Http\Transport;
use Huuray\Http\TransportException;
use Huuray\Http\TransportTimeoutException;
use Huuray\HuurayClient;
use Huuray\Redact;
use Huuray\Resources\PdfsResource;
use Huuray\Result\PdfDocument;
use Huuray\Result\PdfResult;
use Huuray\RetryOptions;
use Huuray\Tests\Support\CapturedRequest;
use Huuray\Tests\Support\FakeClock;
use Huuray\Tests\Support\FakeTransport;
use Huuray\Tests\Support\MockResponse;
use Huuray\Tests\Support\TestClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PdfsTest extends TestCase
{
    /** Invented identifiers, not taken from any account. */
    private const ORDER_UID = '0f8a3c52-1d6e-4b7a-9c2f-5e4d3b2a1c90';

    private const TEMPLATE_UID = 'c7d1e2f3-4a5b-4c6d-8e9f-0a1b2c3d4e5f';

    /** PDF bytes no text round trip survives: NUL, 0xFF, bare CR and LF. The canary stands in for the code. */
    private const PDF_A = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\r\n\x00\x01\xFF\xFE GIFT-CODE-CANARY-A \r%%EOF";

    private const PDF_B = "%PDF-1.7\n\x00\xFF GIFT-CODE-CANARY-B\n%%EOF\n";

    private const PDF_COMBINED = "%PDF-1.7\n\xFF\x00 GIFT-CODE-CANARY-A GIFT-CODE-CANARY-B\n%%EOF";

    private const NOT_READY = 'The order is still being processed, retry in 30 seconds';

    // ------------------------------------------------------------ the request

    public function testSendsOneSignedJsonPostToV4PdfWithOnlyTheOrderUid(): void
    {
        [$pdfs, $transport] = self::pdfs(new MockResponse(json: self::ready([self::document(5123401, self::PDF_A)])));

        $pdfs->get(orderUid: self::ORDER_UID);

        self::assertCount(1, $transport->calls);
        $call = $transport->calls[0];
        self::assertSame('POST', $call->method);
        self::assertSame('https://api.huuray.com/v4/Pdf', $call->url);
        self::assertSame(TestClient::TOKEN, $call->headers['X-API-TOKEN']);
        self::assertSame(hash('sha512', TestClient::SECRET . $call->headers['X-API-NONCE']), $call->headers['X-API-HASH']);
        self::assertSame('application/json', $call->headers['Content-Type']);
        self::assertSame('application/json', $call->headers['Accept']);
        // The optional fields are omitted, not sent as null.
        self::assertSame('{"OrderUID":"' . self::ORDER_UID . '"}', $call->rawBody);
    }

    public function testSendsEveryOptionalFieldThatIsGivenAndCombineFalseToo(): void
    {
        [$pdfs, $transport] = self::pdfs(new MockResponse(json: self::ready([])));

        $pdfs->get(orderUid: self::ORDER_UID, voucherId: 5123402, pdfTemplateUid: self::TEMPLATE_UID, combine: true);
        $pdfs->get(orderUid: self::ORDER_UID, combine: false);
        $pdfs->get(orderUid: self::ORDER_UID, voucherId: 5123402);

        self::assertSame(
            ['OrderUID' => self::ORDER_UID, 'VoucherID' => 5123402, 'PDFTemplateUid' => self::TEMPLATE_UID, 'Combine' => true],
            $transport->calls[0]->body,
        );
        self::assertSame(['OrderUID' => self::ORDER_UID, 'Combine' => false], $transport->calls[1]->body);
        self::assertSame(['OrderUID' => self::ORDER_UID, 'VoucherID' => 5123402], $transport->calls[2]->body);
    }

    public function testChecksNoArgumentItselfTheApiDecides(): void
    {
        [$pdfs, $transport] = self::pdfs(new MockResponse(json: self::ready([])));

        // Not a GUID, and a template uid that is not one either: sent as given, for the API to judge.
        $pdfs->get(orderUid: ' not-a-guid ', voucherId: -1, pdfTemplateUid: '');

        self::assertSame(['OrderUID' => ' not-a-guid ', 'VoucherID' => -1, 'PDFTemplateUid' => ''], $transport->calls[0]->body);
    }

    // ------------------------------------------------------------- the result

    public function testMapsA200ToReadyDocumentsWithTheContentDecodedToTheExactBytes(): void
    {
        [$pdfs] = self::pdfs(new MockResponse(json: self::ready([
            self::document(5123401, self::PDF_A),
            self::document(5123402, self::PDF_B),
        ])));

        $result = $pdfs->get(orderUid: self::ORDER_UID);

        self::assertEquals(
            new PdfResult(
                ready: true,
                orderUid: self::ORDER_UID,
                documents: [
                    new PdfDocument([5123401], self::TEMPLATE_UID, 'giftcard-5123401.pdf', 'application/pdf', self::PDF_A),
                    new PdfDocument([5123402], self::TEMPLATE_UID, 'giftcard-5123402.pdf', 'application/pdf', self::PDF_B),
                ],
                retryAfter: null,
            ),
            $result,
        );
        // Reading the property is reading your own data: the exact bytes.
        self::assertSame(self::PDF_A, $result->documents[0]->content);
        self::assertSame(self::PDF_B, $result->documents[1]->content);
    }

    public function testMapsACombinedDocumentWithSeveralVoucherIdsAndANullPdfTemplateUid(): void
    {
        [$pdfs] = self::pdfs(new MockResponse(json: self::ready([[
            'VoucherIDs' => [5123401, 5123402, 5123403],
            'PDFTemplateUid' => null,
            'FileName' => 'giftcard-order-' . self::ORDER_UID . '.pdf',
            'ContentType' => 'application/pdf',
            'Content' => base64_encode(self::PDF_COMBINED),
        ]])));

        $result = $pdfs->get(orderUid: self::ORDER_UID, combine: true);

        self::assertTrue($result->ready);
        self::assertCount(1, $result->documents);
        self::assertSame([5123401, 5123402, 5123403], $result->documents[0]->voucherIds);
        self::assertNull($result->documents[0]->pdfTemplateUid);
        self::assertSame('giftcard-order-' . self::ORDER_UID . '.pdf', $result->documents[0]->fileName);
        self::assertSame(self::PDF_COMBINED, $result->documents[0]->content);
    }

    public function testMapsAnAbsentOrMistypedFieldToNullOrEmptyRatherThanGuessing(): void
    {
        [$pdfs] = self::pdfs(new MockResponse(json: [
            'OrderUID' => 42,
            'Documents' => [
                ['VoucherIDs' => [1, 'two', 3.0, 4.5, null], 'FileName' => null, 'Content' => null],
                ['Content' => 12345],
                ['Content' => ''],
                'not an object',
            ],
        ]));

        $result = $pdfs->get(orderUid: self::ORDER_UID);

        self::assertNull($result->orderUid);
        self::assertEquals(
            [
                new PdfDocument([1, 3], null, null, null, null),
                new PdfDocument([], null, null, null, null),
                new PdfDocument([], null, null, null, ''),
                new PdfDocument([], null, null, null, null),
            ],
            $result->documents,
        );
    }

    public function testMapsANullOrAbsentDocumentsListToNoDocuments(): void
    {
        [$pdfs] = self::pdfs([new MockResponse(json: ['Documents' => null]), new MockResponse(json: new \stdClass())]);

        self::assertSame([], $pdfs->get(orderUid: self::ORDER_UID)->documents);
        self::assertSame([], $pdfs->get(orderUid: self::ORDER_UID)->documents);
    }

    public function testMapsA202ToNotReadyWithTheRetryAfterInWholeSeconds(): void
    {
        [$pdfs] = self::pdfs(self::notReady(retryAfter: '30'));

        $result = $pdfs->get(orderUid: self::ORDER_UID);

        self::assertEquals(new PdfResult(ready: false, orderUid: self::ORDER_UID, documents: [], retryAfter: 30), $result);
    }

    /** @return iterable<string, array{array<string, string>, ?int}> */
    public static function retryAfterHeaders(): iterable
    {
        yield 'absent' => [[], null];
        yield '30' => [['Retry-After' => '30'], 30];
        yield 'lower-cased name' => [['retry-after' => '30'], 30];
        yield 'surrounding whitespace' => [['Retry-After' => " 30\t"], 30];
        yield 'zero' => [['Retry-After' => '0'], 0];
        yield 'leading zero' => [['Retry-After' => '030'], 30];
        yield 'empty' => [['Retry-After' => ''], null];
        yield 'negative' => [['Retry-After' => '-5'], null];
        yield 'fractional' => [['Retry-After' => '1.5'], null];
        yield 'words' => [['Retry-After' => 'soon'], null];
        yield 'an HTTP date' => [['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT'], null];
        yield 'repeated, so joined' => [['Retry-After' => '30, 30'], null];
        yield 'too large for an int, saturated' => [['Retry-After' => '99999999999999999999999'], PHP_INT_MAX];
        yield 'one past PHP_INT_MAX, saturated' => [['Retry-After' => '9223372036854775808'], PHP_INT_MAX];
        yield 'as many digits as PHP_INT_MAX, saturated without a cast' => [['Retry-After' => '1000000000000000000'], PHP_INT_MAX];
        yield 'one digit fewer than PHP_INT_MAX, exact' => [['Retry-After' => '999999999999999999'], 999_999_999_999_999_999];
        yield 'long only by its leading zeros, exact' => [['Retry-After' => '000000000000000000000000030'], 30];
        yield 'only zeros' => [['Retry-After' => '00000000000000000000000000'], 0];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('retryAfterHeaders')]
    public function testReadsRetryAfterAsWholeSecondsOrNull(array $headers, ?int $expected): void
    {
        [$pdfs] = self::pdfs(new MockResponse(status: 202, json: self::pending(), headers: $headers));

        self::assertSame($expected, $pdfs->get(orderUid: self::ORDER_UID)->retryAfter);
    }

    public function testReadsTheResultPastAHeaderWhoseNameIsOnlyDigits(): void
    {
        // PHP stores the name "1" as the int key 1. Under strict_types, a lookup that
        // handed that key to a string function threw a TypeError on every call, even a 200.
        $headers = ['1' => 'x', 'Retry-After' => '30'];
        [$pdfs] = self::pdfs([
            // @phpstan-ignore argument.type (a header named "1", which PHP keys as an int, is the case under test)
            new MockResponse(status: 202, json: self::pending(), headers: $headers),
            // @phpstan-ignore argument.type (a header named "1", which PHP keys as an int, is the case under test)
            new MockResponse(json: self::ready([self::document(5123401, self::PDF_A)]), headers: $headers),
        ]);

        self::assertSame(30, $pdfs->get(orderUid: self::ORDER_UID)->retryAfter);
        self::assertSame(self::PDF_A, $pdfs->get(orderUid: self::ORDER_UID)->documents[0]->content);
    }

    public function testReadsAHeaderValueThatIsNotAStringAsAbsent(): void
    {
        // A custom transport may pass PSR-7 style lists of values, against the Transport contract.
        [$pdfs] = self::pdfs([
            // @phpstan-ignore argument.type (deliberately wrong, as an untyped custom transport might pass)
            new MockResponse(status: 202, json: self::pending(), headers: ['Retry-After' => ['30']]),
            // @phpstan-ignore argument.type (deliberately wrong, as an untyped custom transport might pass)
            new MockResponse(json: self::ready([self::document(5123401, self::PDF_A)]), headers: ['Retry-After' => 30]),
        ]);

        $notReady = $pdfs->get(orderUid: self::ORDER_UID);
        self::assertFalse($notReady->ready);
        self::assertNull($notReady->retryAfter);
        self::assertSame(self::PDF_A, $pdfs->get(orderUid: self::ORDER_UID)->documents[0]->content);
    }

    // ------------------------------------------------------ a garbled document

    /** @return iterable<string, array{string}> */
    public static function contentThatIsNotValidBase64(): iterable
    {
        yield 'characters outside the alphabet' => ['JVBERi0x-GIFT-CODE-CANARY!'];
        yield 'the URL-safe alphabet' => ['JVBERi0x_-8='];
        yield 'missing padding' => ['JVBERi0'];
        yield 'too much padding' => ['JVBERi0==='];
        yield 'padding in the middle' => ['JV=ERi0x'];
        // Twelve characters each, and valid once the whitespace is dropped, as a lenient decoder would.
        yield 'line breaks' => ["JVBE\r\nRi0x\r\n"];
        yield 'spaces' => ['JVBE    Ri0x'];
    }

    #[DataProvider('contentThatIsNotValidBase64')]
    public function testTreatsContentThatIsNotValidBase64AsATransportFaultWithoutQuotingIt(string $content): void
    {
        [$pdfs] = self::pdfs(new MockResponse(json: self::ready([
            self::document(5123401, self::PDF_A),
            ['VoucherIDs' => [5123402], 'Content' => $content],
        ])));

        try {
            $pdfs->get(orderUid: self::ORDER_UID);
            self::fail('Expected a ConnectionException.');
        } catch (ConnectionException $e) {
            // The same class as any unreadable 2xx body: never a result, never the order-specific error.
            self::assertSame(ConnectionException::class, $e::class);
            self::assertSame('POST', $e->method);
            self::assertSame('/v4/Pdf', $e->path);
            self::assertNull($e->getPrevious());
            self::assertMatchesRegularExpression(
                '/^POST \/v4\/Pdf returned HTTP 200 but the body was not usable: Documents\[1\]\.Content is not valid base64 \(\d+ bytes\)\./',
                $e->getMessage(),
            );
            self::assertStringNotContainsString($content, $e->getMessage());
            self::assertStringNotContainsString(base64_encode(self::PDF_A), $e->getMessage());
        }
    }

    public function testRetriesGarbledContentLikeAnyUnreadableRead(): void
    {
        [$pdfs, $transport] = self::pdfs(
            [
                new MockResponse(json: self::ready([['VoucherIDs' => [5123401], 'Content' => 'JVBERi0x-garbled']])),
                new MockResponse(json: self::ready([self::document(5123401, self::PDF_A)])),
            ],
            new RetryOptions(maxRetries: 1, baseDelayMs: 1),
        );

        $result = $pdfs->get(orderUid: self::ORDER_UID);

        self::assertSame(self::PDF_A, $result->documents[0]->content);
        self::assertCount(2, $transport->calls);
        self::assertNotSame($transport->calls[0]->headers['X-API-NONCE'], $transport->calls[1]->headers['X-API-NONCE']);
    }

    // ------------------------------------------------------ retried as a read

    public function testRetriesA503WithAFreshNonceAndTheSameBody(): void
    {
        [$pdfs, $transport] = self::pdfs(
            [new MockResponse(status: 503), new MockResponse(json: self::ready([self::document(5123401, self::PDF_A)]))],
            new RetryOptions(maxRetries: 2, baseDelayMs: 1),
        );

        $result = $pdfs->get(orderUid: self::ORDER_UID, voucherId: 5123401);

        self::assertTrue($result->ready);
        self::assertCount(2, $transport->calls);
        self::assertNotSame($transport->calls[0]->headers['X-API-NONCE'], $transport->calls[1]->headers['X-API-NONCE']);
        self::assertSame($transport->calls[0]->rawBody, $transport->calls[1]->rawBody);
    }

    public function testRetriesAConnectionFailure(): void
    {
        [$pdfs, $transport] = self::pdfs(
            [
                new MockResponse(throws: new TransportException('cURL error 7: Failed to connect')),
                new MockResponse(json: self::ready([self::document(5123401, self::PDF_A)])),
            ],
            new RetryOptions(maxRetries: 1, baseDelayMs: 1),
        );

        self::assertTrue($pdfs->get(orderUid: self::ORDER_UID)->ready);
        self::assertCount(2, $transport->calls);
    }

    /** @return iterable<string, array{MockResponse, class-string<HuurayException>}> */
    public static function failuresOnceRetriesRunOut(): iterable
    {
        yield '503' => [new MockResponse(status: 503), ServerException::class];
        yield 'connection refused' => [new MockResponse(throws: new TransportException('cURL error 7: Failed to connect')), ConnectionException::class];
        yield 'timeout' => [new MockResponse(throws: new TransportTimeoutException('cURL error 28: Operation timed out')), TimeoutException::class];
    }

    /** @param class-string<HuurayException> $expected */
    #[DataProvider('failuresOnceRetriesRunOut')]
    public function testThrowsTheOrdinaryExceptionNeverTheIndeterminateOrderOne(MockResponse $response, string $expected): void
    {
        [$pdfs, $transport] = self::pdfs($response, new RetryOptions(maxRetries: 2, baseDelayMs: 1));

        try {
            $pdfs->get(orderUid: self::ORDER_UID);
            self::fail('Expected the call to fail.');
        } catch (HuurayException $e) {
            self::assertSame($expected, $e::class);
            self::assertNotInstanceOf(IndeterminateOrderException::class, $e);
        }

        self::assertCount(3, $transport->calls);
    }

    // ------------------------------------------------------------------ errors

    /** @return iterable<string, array{int, string, class-string<ApiException>}> */
    public static function errorStatuses(): iterable
    {
        yield '400' => [400, 'OrderUID is required', ApiException::class];
        yield '401' => [401, 'Restricted Access', AuthException::class];
        yield '404' => [404, 'No order was found with the given OrderUID', NotFoundException::class];
        yield '422' => [422, 'The PDF can only be fetched for orders with at most 3 receivers', ValidationException::class];
        yield '500' => [500, 'The PDF could not be generated', ServerException::class];
    }

    /** @param class-string<ApiException> $expected */
    #[DataProvider('errorStatuses')]
    public function testMapsAnErrorEnvelopeToTheExistingExceptionTypes(int $status, string $statusMessage, string $expected): void
    {
        [$pdfs, $transport] = self::pdfs(new MockResponse(status: $status, json: [
            'OrderUID' => self::ORDER_UID,
            'Documents' => [],
            'Status' => $status,
            'Message' => 'Short form',
            'StatusMessage' => $statusMessage,
        ]));

        try {
            $pdfs->get(orderUid: self::ORDER_UID);
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertSame($expected, $e::class);
            self::assertSame($status, $e->httpStatus);
            self::assertSame($statusMessage, $e->statusMessage);
            self::assertSame('POST /v4/Pdf failed with HTTP ' . $status . ' — ' . $statusMessage, $e->getMessage());
        }

        self::assertCount(1, $transport->calls);
    }

    public function testAnErrorBodyCarryingContentNeverPutsItOnTheException(): void
    {
        // The envelope has an empty Documents on every error; this one does not, to be sure.
        [$pdfs] = self::pdfs(new MockResponse(status: 422, json: [
            'Documents' => [self::document(5123401, self::PDF_A)],
            'Status' => 422,
            'StatusMessage' => 'Rejected',
        ]));

        try {
            $pdfs->get(orderUid: self::ORDER_UID);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            // The exception keeps a redacted copy of the body: the document, with its content replaced.
            self::assertSame(
                ['Documents' => [[
                    'VoucherIDs' => [5123401],
                    'PDFTemplateUid' => self::TEMPLATE_UID,
                    'FileName' => 'gi***df',
                    'ContentType' => 'application/pdf',
                    'Content' => Redact::BEARER_MARKER,
                ]], 'Status' => 422, 'StatusMessage' => 'Rejected'],
                $e->body,
            );
            foreach ([$e->getMessage(), print_r($e->body, true), $e->getTraceAsString()] as $text) {
                self::assertStringNotContainsString(base64_encode(self::PDF_A), $text);
                self::assertStringNotContainsString('GIFT-CODE-CANARY', $text);
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function failuresWithTheContentInTheBody(): iterable
    {
        yield 'content that is not valid base64' => ['garbled'];
        yield 'a 422 carrying a document' => ['422'];
    }

    #[DataProvider('failuresWithTheContentInTheBody')]
    public function testNoExceptionTraceCarriesTheContent(string $failure): void
    {
        // Off is PHP's built-in default: exception traces then keep every argument.
        // Forced here so this assertion can never pass vacuously.
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        // Built in the test body, so no test frame's own arguments hold the content.
        [$pdfs] = self::pdfs(match ($failure) {
            'garbled' => new MockResponse(json: self::ready([['VoucherIDs' => [1], 'Content' => 'JVBERi0x-GIFT-CODE-CANARY-A']])),
            default => new MockResponse(status: 422, json: ['Documents' => [self::document(1, self::PDF_A)], 'Status' => 422]),
        });
        $caught = null;

        try {
            $pdfs->get(orderUid: 'args-are-kept');
        } catch (HuurayException $e) {
            $caught = $e;
        } finally {
            ini_set('zend.exception_ignore_args', $ignoreArgs === false ? '0' : $ignoreArgs);
        }

        self::assertNotNull($caught, 'Expected the call to throw.');
        $traces = '';
        for ($exception = $caught; $exception !== null; $exception = $exception->getPrevious()) {
            $traces .= print_r($exception->getTrace(), true) . $exception->getMessage();
        }

        // Arguments were recorded, and the sensitive ones were replaced.
        self::assertStringContainsString('args-are-kept', $traces);
        self::assertStringContainsString('SensitiveParameterValue', $traces);
        self::assertStringNotContainsString('GIFT-CODE-CANARY', $traces);
        self::assertStringNotContainsString(base64_encode(self::PDF_A), $traces);
    }

    // ------------------------------------------------------------ getWhenReady

    public function testGetWhenReadyReturnsAtOnceWhenTheFirstAnswerIsReady(): void
    {
        [$pdfs, $transport, $clock] = self::pdfs([new MockResponse(json: self::ready([self::document(5123401, self::PDF_A)]))]);

        $result = $pdfs->getWhenReady(orderUid: self::ORDER_UID);

        self::assertTrue($result->ready);
        self::assertSame(self::PDF_A, $result->documents[0]->content);
        self::assertCount(1, $transport->calls);
        self::assertSame([], $clock->waits);
    }

    public function testGetWhenReadyWaitsTheRetryAfterBetweenAsksAndSignsEachAfresh(): void
    {
        [$pdfs, $transport, $clock] = self::pdfs([
            self::notReady(retryAfter: '5'),
            self::notReady(retryAfter: '7'),
            new MockResponse(json: self::ready([self::document(5123401, self::PDF_A)])),
        ]);

        $result = $pdfs->getWhenReady(orderUid: self::ORDER_UID, voucherId: 5123401, pdfTemplateUid: self::TEMPLATE_UID, combine: true);

        self::assertSame(self::PDF_A, $result->documents[0]->content);
        self::assertSame([5, 7], $clock->waits);
        self::assertCount(3, $transport->calls);
        $nonces = array_map(static fn(CapturedRequest $call): string => $call->headers['X-API-NONCE'], $transport->calls);
        self::assertCount(3, array_unique($nonces), 'Every ask is a new signed request.');
        foreach ($transport->calls as $call) {
            self::assertSame(hash('sha512', TestClient::SECRET . $call->headers['X-API-NONCE']), $call->headers['X-API-HASH']);
            self::assertSame(
                ['OrderUID' => self::ORDER_UID, 'VoucherID' => 5123401, 'PDFTemplateUid' => self::TEMPLATE_UID, 'Combine' => true],
                $call->body,
            );
        }
    }

    /** @return iterable<string, array{?string}> */
    public static function retryAfterValuesThatNameNoWait(): iterable
    {
        yield 'no header' => [null];
        yield 'an HTTP date' => ['Wed, 21 Oct 2026 07:28:00 GMT'];
        yield 'a negative number' => ['-5'];
    }

    #[DataProvider('retryAfterValuesThatNameNoWait')]
    public function testGetWhenReadyWaits30SecondsWhenThe202NamesNoUsableWait(?string $retryAfter): void
    {
        [$pdfs, , $clock] = self::pdfs([
            self::notReady(retryAfter: $retryAfter),
            new MockResponse(json: self::ready([self::document(5123401, self::PDF_A)])),
        ]);

        $pdfs->getWhenReady(orderUid: self::ORDER_UID);

        self::assertSame([30], $clock->waits);
    }

    public function testGetWhenReadyWaitsASecondWhenTheApiAsksForNoWait(): void
    {
        [$pdfs, , $clock] = self::pdfs([self::notReady(retryAfter: '0'), new MockResponse(json: self::ready([]))]);

        $pdfs->getWhenReady(orderUid: self::ORDER_UID);

        // Never back to back: a Retry-After of 0 still gets the one-second floor.
        self::assertSame([1], $clock->waits);
    }

    public function testGetWhenReadyGivesUpWhenEvenTheOneSecondFloorWouldPassMaxWait(): void
    {
        [$pdfs, $transport, $clock] = self::pdfs([self::notReady(retryAfter: '0'), self::notReady(retryAfter: '0')]);

        try {
            $pdfs->getWhenReady(orderUid: self::ORDER_UID, maxWaitMs: 1_500);
            self::fail('Expected a TimeoutException.');
        } catch (TimeoutException $e) {
            self::assertSame(1_500, $e->timeoutMs);
            self::assertStringContainsString('Waiting another 1 second would pass maxWaitMs', $e->getMessage());
        }

        // At 0 seconds a 1 s wait fitted in 1.5 s; at 1 second another did not.
        self::assertSame([1], $clock->waits);
        self::assertCount(2, $transport->calls);
    }

    public function testGetWhenReadyGivesUpBeforeTheNextWaitWouldPassMaxWaitQuotingTheLastStatusMessage(): void
    {
        [$pdfs, $transport, $clock] = self::pdfs([
            self::notReady(retryAfter: '30', statusMessage: 'The order is still being processed, retry in 30 seconds'),
            self::notReady(retryAfter: '30', statusMessage: 'The order is still being processed, retry in 30 seconds'),
            self::notReady(retryAfter: '30', statusMessage: 'The giftcard code is not ready yet, retry in 30 seconds'),
        ]);

        try {
            $pdfs->getWhenReady(orderUid: self::ORDER_UID, maxWaitMs: 60_000);
            self::fail('Expected a TimeoutException.');
        } catch (TimeoutException $e) {
            // The SDK's ordinary timeout, never the order-specific error.
            self::assertSame(TimeoutException::class, $e::class);
            self::assertSame(60_000, $e->timeoutMs);
            self::assertSame('POST', $e->method);
            self::assertSame('/v4/Pdf', $e->path);
            self::assertNull($e->getPrevious());
            self::assertSame(
                'POST /v4/Pdf gave up waiting for the gift card PDF within maxWaitMs (60000 ms). The gift card PDF '
                . 'was still not ready: "The giftcard code is '
                . 'not ready yet, retry in 30 seconds". Waiting another 30 seconds would pass maxWaitMs, so '
                . 'getWhenReady() stopped asking; the call is read-only, so asking again later is safe.',
                $e->getMessage(),
            );
        }

        // At 0 and 30 seconds it waited; at 60, another 30 would have passed the limit.
        self::assertSame([30, 30], $clock->waits);
        self::assertCount(3, $transport->calls);
    }

    public function testGetWhenReadyWaitsRightUpToMaxWaitButNotPastIt(): void
    {
        [$pdfs, , $clock] = self::pdfs([self::notReady(retryAfter: '30'), new MockResponse(json: self::ready([]))]);

        self::assertTrue($pdfs->getWhenReady(orderUid: self::ORDER_UID, maxWaitMs: 30_000)->ready);
        self::assertSame([30], $clock->waits);
    }

    public function testGetWhenReadyCountsTheTimeRequestsTakeAgainstMaxWait(): void
    {
        $clock = new FakeClock();
        $fake = new FakeTransport([self::notReady(retryAfter: '30'), self::notReady(retryAfter: '30')]);
        // Each request takes 20 seconds of the budget.
        $transport = new class ($fake, $clock) implements Transport {
            public function __construct(
                private readonly FakeTransport $inner,
                private readonly FakeClock $clock,
            ) {}

            public function send(
                #[\SensitiveParameter]
                HttpRequest $request,
            ): HttpResponse {
                $this->clock->now += 20;

                return $this->inner->send($request);
            }
        };
        $client = new HuurayClient(apiToken: TestClient::TOKEN, apiSecret: TestClient::SECRET, retry: new RetryOptions(maxRetries: 0), transport: $transport);
        $pdfs = new PdfsResource($client, $clock->sleep(...), $clock->now(...));

        try {
            $pdfs->getWhenReady(orderUid: self::ORDER_UID, maxWaitMs: 60_000);
            self::fail('Expected a TimeoutException.');
        } catch (TimeoutException) {
        }

        // 20 s in, a 30 s wait still fitted; 70 s in, none did.
        self::assertSame([30], $clock->waits);
        self::assertCount(2, $fake->calls);
    }

    public function testGetWhenReadyWithAMaxWaitOfZeroAsksOnceAndNeverWaits(): void
    {
        [$pdfs, $transport, $clock] = self::pdfs([self::notReady(retryAfter: '30')]);

        try {
            $pdfs->getWhenReady(orderUid: self::ORDER_UID, maxWaitMs: 0);
            self::fail('Expected a TimeoutException.');
        } catch (TimeoutException $e) {
            self::assertSame(0, $e->timeoutMs);
        }

        self::assertCount(1, $transport->calls);
        self::assertSame([], $clock->waits);
    }

    public function testGetWhenReadyGivesUpAtOnceOnARetryAfterTooLargeForAnInt(): void
    {
        [$pdfs, $transport, $clock] = self::pdfs([self::notReady(retryAfter: '99999999999999999999999')]);

        try {
            $pdfs->getWhenReady(orderUid: self::ORDER_UID, maxWaitMs: PHP_INT_MAX);
            self::fail('Expected a TimeoutException.');
        } catch (TimeoutException $e) {
            self::assertSame(PHP_INT_MAX, $e->timeoutMs);
            self::assertStringContainsString('Waiting another ' . PHP_INT_MAX . ' seconds would pass maxWaitMs', $e->getMessage());
        }

        self::assertCount(1, $transport->calls);
        self::assertSame([], $clock->waits);
    }

    public function testGetWhenReadyWaitsTenMinutesByDefault(): void
    {
        [$pdfs, $transport, $clock] = self::pdfs(self::notReady(retryAfter: '30'));

        try {
            $pdfs->getWhenReady(orderUid: self::ORDER_UID);
            self::fail('Expected a TimeoutException.');
        } catch (TimeoutException $e) {
            self::assertSame(600_000, $e->timeoutMs);
        }

        self::assertSame(600_000, PdfsResource::DEFAULT_MAX_WAIT_MS);
        self::assertSame(array_fill(0, 20, 30), $clock->waits);
        self::assertCount(21, $transport->calls);
    }

    /** @return iterable<string, array{list<MockResponse>, class-string<HuurayException>, list<int>}> */
    public static function errorsWhilePolling(): iterable
    {
        yield '404 at once' => [[new MockResponse(status: 404)], NotFoundException::class, []];
        yield '422 after one wait' => [[self::notReady(retryAfter: '1'), new MockResponse(status: 422)], ValidationException::class, [1]];
        // 429 is not in the specification, so it gets no 202-style wait: it is an error like any other.
        yield '429 with a Retry-After' => [[new MockResponse(status: 429, headers: ['Retry-After' => '5'])], ApiException::class, []];
    }

    /**
     * @param list<MockResponse>            $responses
     * @param class-string<HuurayException> $expected
     * @param list<int>                     $waits
     */
    #[DataProvider('errorsWhilePolling')]
    public function testGetWhenReadyThrowsAnErrorAtOnceWithoutWaitingForIt(array $responses, string $expected, array $waits): void
    {
        [$pdfs, $transport, $clock] = self::pdfs($responses);

        try {
            $pdfs->getWhenReady(orderUid: self::ORDER_UID);
            self::fail('Expected the call to fail.');
        } catch (HuurayException $e) {
            self::assertSame($expected, $e::class);
        }

        self::assertSame($waits, $clock->waits);
        self::assertCount(count($responses), $transport->calls);
    }

    // -------------------------------------------------------------- redaction

    public function testNoDumpOrRedactionShowsTheBytesOnlyTheirSize(): void
    {
        $document = new PdfDocument([5123401], self::TEMPLATE_UID, 'giftcard-5123401.pdf', 'application/pdf', self::PDF_A);
        $result = new PdfResult(true, self::ORDER_UID, [$document], null);

        foreach ([$document, $result] as $object) {
            foreach ([print_r($object, true), self::varDump($object)] as $dump) {
                self::assertStringNotContainsString('GIFT-CODE-CANARY', $dump);
                self::assertStringContainsString(sprintf('[%d bytes]', strlen(self::PDF_A)), $dump);
                self::assertStringContainsString('5123401', $dump);
                self::assertStringContainsString('giftcard-5123401.pdf', $dump);
            }
            foreach ([Redact::safeJson($object), print_r(Redact::redact($object), true)] as $redacted) {
                self::assertStringNotContainsString('GIFT-CODE-CANARY', $redacted);
                self::assertStringContainsString('5123401', $redacted);
            }
        }
        $redacted = Redact::redact($document);
        self::assertIsArray($redacted);
        self::assertSame(Redact::BEARER_MARKER, $redacted['content'] ?? null);
    }

    public function testNoDumpOfTheRawResponseOrTheTransportShowsTheContent(): void
    {
        $body = json_encode(self::ready([self::document(5123401, self::PDF_A)]), JSON_THROW_ON_ERROR);
        [$client] = TestClient::make(new MockResponse(text: $body));

        $raw = $client->send('POST', '/v4/Pdf', ['OrderUID' => self::ORDER_UID]);
        $response = new HttpResponse(200, $body);

        foreach ([print_r($raw, true), self::varDump($raw), print_r($response, true), Redact::safeJson($raw)] as $dump) {
            self::assertStringNotContainsString(base64_encode(self::PDF_A), $dump);
        }
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A PdfsResource on a client wired to a FakeTransport, with a fake clock and sleep.
     * Retries are off unless a test asks for them.
     *
     * @param MockResponse|list<MockResponse> $responses
     *
     * @return array{PdfsResource, FakeTransport, FakeClock}
     */
    private static function pdfs(MockResponse|array $responses, ?RetryOptions $retry = null): array
    {
        [$client, $transport] = TestClient::make($responses, retry: $retry);
        $clock = new FakeClock();

        return [new PdfsResource($client, $clock->sleep(...), $clock->now(...)), $transport, $clock];
    }

    /**
     * A 200 envelope with the given documents.
     *
     * @param list<array<string, mixed>> $documents
     *
     * @return array<string, mixed>
     */
    private static function ready(array $documents): array
    {
        return ['OrderUID' => self::ORDER_UID, 'Documents' => $documents, 'Status' => 200, 'Message' => 'OK', 'StatusMessage' => 'OK'];
    }

    /** @return array<string, mixed> */
    private static function pending(string $statusMessage = self::NOT_READY): array
    {
        return ['OrderUID' => self::ORDER_UID, 'Documents' => [], 'Status' => 202, 'Message' => 'OK, order still processing', 'StatusMessage' => $statusMessage];
    }

    private static function notReady(?string $retryAfter, string $statusMessage = self::NOT_READY): MockResponse
    {
        return new MockResponse(status: 202, json: self::pending($statusMessage), headers: $retryAfter === null ? [] : ['Retry-After' => $retryAfter]);
    }

    /** @return array<string, mixed> One voucher's document, as the API sends it. */
    private static function document(int $voucherId, string $pdf): array
    {
        return [
            'VoucherIDs' => [$voucherId],
            'PDFTemplateUid' => self::TEMPLATE_UID,
            'FileName' => 'giftcard-' . $voucherId . '.pdf',
            'ContentType' => 'application/pdf',
            'Content' => base64_encode($pdf),
        ];
    }

    private static function varDump(mixed $value): string
    {
        ob_start();
        var_dump($value);

        return (string) ob_get_clean();
    }
}
