<?php

declare(strict_types=1);

namespace Huuray\Tests;

use Huuray\Exception\ApiException;
use Huuray\Exception\AuthException;
use Huuray\Exception\ConnectionException;
use Huuray\Exception\HuurayException;
use Huuray\Exception\IndeterminateOrderException;
use Huuray\Exception\ServerException;
use Huuray\Exception\TimeoutException;
use Huuray\Exception\ValidationException;
use Huuray\Http\HttpRequest;
use Huuray\Http\HttpResponse;
use Huuray\Http\Transport;
use Huuray\Http\TransportException;
use Huuray\Http\TransportTimeoutException;
use Huuray\HuurayClient;
use Huuray\Internal\MultipartBody;
use Huuray\Redact;
use Huuray\Result\UploadResult;
use Huuray\RetryOptions;
use Huuray\Tests\Support\CapturedRequest;
use Huuray\Tests\Support\MockResponse;
use Huuray\Tests\Support\TestClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UploadsTest extends TestCase
{
    /** Bytes no text round trip survives: NUL, bare CR and LF, a "--" line, the boundary's prefix, and invalid UTF-8. */
    private const BYTES = "%PDF-1.7\r\n\x00\x01\xFF\xFE\r\n--\r\n--huuray-\n\r%%EOF";

    // ------------------------------------------------------------ the request

    public function testSendsOneSignedMultipartPostToV4UploadWithOnePartNamedFile(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(status: 201, json: self::uploaded()));

        $client->uploads->create(file: self::BYTES, fileName: 'purchase-order-4711.pdf', contentType: 'application/pdf');

        self::assertCount(1, $transport->calls);
        $call = $transport->calls[0];
        self::assertSame('POST', $call->method);
        self::assertSame('https://api.huuray.com/v4/Upload', $call->url);
        self::assertSame(TestClient::TOKEN, $call->headers['X-API-TOKEN']);
        self::assertSame(hash('sha512', TestClient::SECRET . $call->headers['X-API-NONCE']), $call->headers['X-API-HASH']);
        self::assertSame('application/json', $call->headers['Accept']);

        $boundary = self::boundary($call);
        self::assertSame(
            '--' . $boundary . "\r\n"
            . "Content-Disposition: form-data; name=\"File\"; filename=\"purchase-order-4711.pdf\"\r\n"
            . "Content-Type: application/pdf\r\n"
            . "\r\n"
            . self::BYTES . "\r\n"
            . '--' . $boundary . "--\r\n",
            $call->rawBody,
        );

        self::assertNull($call->multipartError);
        self::assertCount(1, $call->parts ?? []);
        $part = ($call->parts ?? [])[0];
        self::assertSame('File', $part->name);
        self::assertSame('purchase-order-4711.pdf', $part->filename);
        self::assertSame('application/pdf', $part->contentType());
        self::assertSame(self::BYTES, $part->content, 'The bytes arrive intact.');
    }

    public function testSendsThePartAsOctetStreamWhenNoContentTypeIsGiven(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(status: 201, json: self::uploaded()));

        $client->uploads->create(file: self::BYTES, fileName: 'purchase-order-4711.pdf');

        self::assertSame('application/octet-stream', ($transport->calls[0]->parts ?? [])[0]->contentType());
    }

    public function testUsesAFreshBoundaryForEveryUpload(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(status: 201, json: self::uploaded()));

        $client->uploads->create(file: self::BYTES, fileName: 'a.pdf');
        $client->uploads->create(file: self::BYTES, fileName: 'a.pdf');

        self::assertNotSame(self::boundary($transport->calls[0]), self::boundary($transport->calls[1]));
    }

    public function testReadsAStreamFromItsCurrentPositionAndLeavesItOpen(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(status: 201, json: self::uploaded()));
        $stream = self::memoryStream('SKIPPED' . self::BYTES);
        fseek($stream, 7);

        $client->uploads->create(file: $stream, fileName: 'purchase-order-4711.pdf', contentType: 'application/pdf');

        self::assertSame(self::BYTES, ($transport->calls[0]->parts ?? [])[0]->content);
        self::assertTrue(is_resource($stream), 'The stream is the caller\'s to close.');
        fclose($stream);
    }

    /** @return iterable<string, array{string}> */
    public static function filesThatAreNeitherBytesNorAReadableStream(): iterable
    {
        yield 'an int' => ['int'];
        yield 'an array of bytes' => ['array'];
        yield 'a closed stream' => ['closed'];
        yield 'a stream open only for writing' => ['write-only'];
    }

    #[DataProvider('filesThatAreNeitherBytesNorAReadableStream')]
    public function testRejectsAFileThatIsNeitherBytesNorAReadableStreamBeforeSending(string $kind): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(status: 201, json: self::uploaded()));
        $file = match ($kind) {
            'int' => 42,
            'array' => [0x25, 0x50, 0x44, 0x46],
            'closed' => self::closedStream(),
            default => fopen('php://output', 'w'),
        };

        try {
            // @phpstan-ignore argument.type (deliberately wrong, as an untyped caller might pass)
            $client->uploads->create(file: $file, fileName: 'purchase-order-4711.pdf');
            self::fail('Expected the file to be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringStartsWith('POST /v4/Upload was not sent: file must be', $e->getMessage());
        }

        self::assertCount(0, $transport->calls);
    }

    /** @return iterable<string, array{string}> */
    public static function contentTypesThatCouldInjectAPartHeader(): iterable
    {
        yield 'CRLF' => ["application/pdf\r\nX-Injected: yes"];
        yield 'bare LF' => ["application/pdf\nX-Injected: yes"];
        yield 'bare CR' => ["application/pdf\rX-Injected: yes"];
        yield 'NUL' => ["application/pdf\0X-Injected: yes"];
    }

    #[DataProvider('contentTypesThatCouldInjectAPartHeader')]
    public function testRefusesAContentTypeThatCouldInjectAPartHeaderBeforeSending(string $contentType): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(status: 201, json: self::uploaded()));

        try {
            $client->uploads->create(file: self::BYTES, fileName: 'purchase-order-4711.pdf', contentType: $contentType);
            self::fail('Expected the content type to be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString('X-Injected', $e->getMessage());
        }

        self::assertCount(0, $transport->calls);
    }

    public function testEscapesAFileNameSoItCannotEndTheQuotedParameterOrThePartHeaders(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(status: 201, json: self::uploaded()));

        $client->uploads->create(file: self::BYTES, fileName: "po\"; name=\"Other\r\nX-Injected: yes\n.pdf");

        $parts = $transport->calls[0]->parts ?? [];
        self::assertNull($transport->calls[0]->multipartError);
        self::assertCount(1, $parts);
        self::assertSame('File', $parts[0]->name);
        // The HTML standard's multipart/form-data escaping, as browsers and fetch() apply it.
        self::assertSame('po%22; name=%22Other%0D%0AX-Injected: yes%0A.pdf', $parts[0]->filename);
        self::assertArrayNotHasKey('x-injected', $parts[0]->headers);
        self::assertSame(self::BYTES, $parts[0]->content);
    }

    public function testSendsAnyOtherFileNameAsGivenNonAsciiIncluded(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(status: 201, json: self::uploaded()));

        $client->uploads->create(file: self::BYTES, fileName: "indkøbsordre æøå; nr. 4711\\2026.PDF");

        self::assertSame("indkøbsordre æøå; nr. 4711\\2026.PDF", ($transport->calls[0]->parts ?? [])[0]->filename);
    }

    // ------------------------------------------------------------- the result

    public function testTreatsTheDocumented201AsSuccessAndMapsTheResult(): void
    {
        [$client] = TestClient::make(new MockResponse(status: 201, json: self::uploaded() + ['Status' => 201, 'Message' => 'OK', 'StatusMessage' => 'OK']));

        $result = $client->uploads->create(file: self::BYTES, fileName: 'purchase-order-4711.pdf', contentType: 'application/pdf');

        self::assertEquals(
            new UploadResult(
                token: '60050460-7a2d-42a8-a4dd-5cef88ad8374',
                fileName: 'purchase-order-4711.pdf',
                contentType: 'application/pdf',
                size: 48213,
            ),
            $result,
        );
    }

    public function testMapsAnAbsentOrMistypedFieldToNullRatherThanGuessing(): void
    {
        [$client] = TestClient::make(new MockResponse(status: 201, json: ['Token' => '60050460-7a2d-42a8-a4dd-5cef88ad8374', 'ContentType' => null, 'Size' => '48213']));

        $result = $client->uploads->create(file: self::BYTES, fileName: 'purchase-order-4711.pdf');

        self::assertSame('60050460-7a2d-42a8-a4dd-5cef88ad8374', $result->token);
        self::assertNull($result->fileName);
        self::assertNull($result->contentType);
        self::assertNull($result->size);
    }

    // ---------------------------------------------------------- never retried

    /** @return iterable<string, array{MockResponse, class-string<HuurayException>}> */
    public static function failuresThatAReadWouldRetry(): iterable
    {
        yield '503' => [new MockResponse(status: 503), ServerException::class];
        yield 'connection refused' => [new MockResponse(throws: new TransportException('cURL error 7: Failed to connect')), ConnectionException::class];
        yield 'connection dropped mid-body' => [new MockResponse(throws: new TransportException('cURL error 18: transfer closed')), ConnectionException::class];
        yield 'timeout' => [new MockResponse(throws: new TransportTimeoutException('cURL error 28: Operation timed out')), TimeoutException::class];
        yield 'garbled 2xx body' => [new MockResponse(status: 201, text: '<html>gateway</html>'), ConnectionException::class];
    }

    /** @param class-string<HuurayException> $expected */
    #[DataProvider('failuresThatAReadWouldRetry')]
    public function testIsNeverRetried(MockResponse $response, string $expected): void
    {
        [$client, $transport] = TestClient::make($response, retry: new RetryOptions(maxRetries: 3, baseDelayMs: 1));

        try {
            $client->uploads->create(file: self::BYTES, fileName: 'purchase-order-4711.pdf');
            self::fail('Expected the upload to fail.');
        } catch (HuurayException $e) {
            // The ordinary exception for the failure, never the order-specific one.
            self::assertSame($expected, $e::class);
        }

        self::assertCount(1, $transport->calls);
    }

    public function testATimeoutIsTheOrdinaryTimeoutExceptionSayingTheUploadMayStillHaveBeenStored(): void
    {
        $cause = new TransportTimeoutException('cURL error 28: Operation timed out');
        [$client] = TestClient::make(new MockResponse(throws: $cause), timeoutMs: 1234);

        try {
            $client->uploads->create(file: self::BYTES, fileName: 'purchase-order-4711.pdf');
            self::fail('Expected a TimeoutException.');
        } catch (TimeoutException $e) {
            self::assertSame(1234, $e->timeoutMs);
            self::assertSame('POST', $e->method);
            self::assertSame('/v4/Upload', $e->path);
            self::assertSame($cause, $e->getPrevious());
            self::assertSame(
                'POST /v4/Upload timed out after 1234ms. The upload may still have been stored, and may hold one of '
                . 'the account\'s pending upload slots until it is used or cleaned up. It was not retried.',
                $e->getMessage(),
            );
        }
    }

    public function testAConnectionFailureIsTheOrdinaryConnectionExceptionSayingTheSame(): void
    {
        $cause = new TransportException('cURL error 7: Failed to connect');
        [$client] = TestClient::make(new MockResponse(throws: $cause));

        try {
            $client->uploads->create(file: self::BYTES, fileName: 'purchase-order-4711.pdf');
            self::fail('Expected a ConnectionException.');
        } catch (ConnectionException $e) {
            self::assertNotInstanceOf(TimeoutException::class, $e);
            self::assertSame($cause, $e->getPrevious());
            self::assertStringStartsWith('POST /v4/Upload failed to reach the Huuray API: cURL error 7: Failed to connect The upload may still have been stored', $e->getMessage());
        }
    }

    /** @return iterable<string, array{int, class-string<ApiException>}> */
    public static function statusesWithTheGenericMapping(): iterable
    {
        yield '400' => [400, ApiException::class];
        yield '401' => [401, AuthException::class];
        yield '413, which the specification does not list' => [413, ApiException::class];
        yield '422' => [422, ValidationException::class];
        yield '500' => [500, ServerException::class];
    }

    /** @param class-string<ApiException> $expected */
    #[DataProvider('statusesWithTheGenericMapping')]
    public function testEveryOtherStatusKeepsTheGenericMapping(int $status, string $expected): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(status: $status, json: ['Status' => $status, 'StatusMessage' => 'Rejected']));

        try {
            $client->uploads->create(file: self::BYTES, fileName: 'purchase-order-4711.pdf');
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertSame($expected, $e::class);
            self::assertSame($status, $e->httpStatus);
            self::assertSame('POST /v4/Upload failed with HTTP ' . $status . ' — Rejected', $e->getMessage());
        }

        self::assertCount(1, $transport->calls);
    }

    // -------------------------------------------------------------- redaction

    public function testAResultMasksTheFileNameWhenDumpedOrRedacted(): void
    {
        $result = new UploadResult('60050460-7a2d-42a8-a4dd-5cef88ad8374', 'jane-doe-po.pdf', 'application/pdf', 48213);

        foreach ([print_r($result, true), self::varDump($result), Redact::safeJson($result)] as $dump) {
            self::assertStringNotContainsString('jane-doe-po.pdf', $dump);
            self::assertStringContainsString('ja***df', $dump);
            self::assertStringContainsString('60050460-7a2d-42a8-a4dd-5cef88ad8374', $dump);
        }
        // Reading your own data is not logging it.
        self::assertSame('jane-doe-po.pdf', $result->fileName);
    }

    public function testRedactMasksTheFileNameInAResponseBody(): void
    {
        self::assertSame(['FileName' => 'ja***df', 'Token' => 't'], Redact::redact(['FileName' => 'jane-doe-po.pdf', 'Token' => 't']));
    }

    public function testAnErrorBodyEchoingTheFileNameIsRedactedOnTheException(): void
    {
        [$client] = TestClient::make(new MockResponse(status: 422, json: ['Status' => 422, 'StatusMessage' => 'Rejected', 'FileName' => 'jane-doe-po.pdf']));

        try {
            $client->uploads->create(file: self::BYTES, fileName: 'jane-doe-po.pdf');
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertStringNotContainsString('jane-doe-po.pdf', print_r($e->body, true));
        }
    }

    public function testNoDumpOrRedactionOfTheRequestShowsTheFileBytesOrName(): void
    {
        $transport = new class implements Transport {
            /** @var list<HttpRequest> */
            public array $requests = [];

            public function send(
                #[\SensitiveParameter]
                HttpRequest $request,
            ): HttpResponse {
                $this->requests[] = $request;

                return new HttpResponse(201, '{"Token":"t"}');
            }
        };
        $client = new HuurayClient(apiToken: TestClient::TOKEN, apiSecret: TestClient::SECRET, transport: $transport);
        $client->uploads->create(file: 'Fil3-Byt3s-Canary', fileName: 'Jane Doe purchase order.pdf');

        $request = $transport->requests[0];
        $body = MultipartBody::withFile('File', 'Jane Doe purchase order.pdf', 'application/pdf', 'Fil3-Byt3s-Canary');
        // The request really carried them, so their absence below means something.
        self::assertStringContainsString('Fil3-Byt3s-Canary', (string) $request->body);
        self::assertStringContainsString('Jane Doe purchase order.pdf', (string) $request->body);

        foreach ([$request, $body] as $object) {
            foreach ([print_r($object, true), self::varDump($object), Redact::safeJson($object)] as $dump) {
                self::assertStringNotContainsString('Fil3-Byt3s-Canary', $dump);
                self::assertStringNotContainsString('Jane Doe purchase order', $dump);
                self::assertMatchesRegularExpression('/\[\d+ bytes\]/', $dump);
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function failingUploads(): iterable
    {
        yield '422' => ['422'];
        yield 'timeout, rethrown with the note' => ['timeout'];
        yield 'connection failure, rethrown with the note' => ['connection'];
    }

    #[DataProvider('failingUploads')]
    public function testNoExceptionTraceCarriesTheFileBytesOrName(string $failure): void
    {
        // Off is PHP's built-in default: exception traces then keep every argument.
        // Forced here so this assertion can never pass vacuously.
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        [$client, $transport] = TestClient::make(match ($failure) {
            '422' => new MockResponse(status: 422),
            'timeout' => new MockResponse(throws: new TransportTimeoutException('cURL error 28')),
            default => new MockResponse(throws: new TransportException('cURL error 7')),
        });
        $caught = null;

        try {
            // Literals in the test body, so no test frame's own arguments hold them.
            $client->uploads->create(file: 'Fil3-Byt3s-Canary', fileName: 'Jane Doe purchase order.pdf', contentType: 'application/x-args-are-kept');
        } catch (HuurayException $e) {
            $caught = $e;
        } finally {
            ini_set('zend.exception_ignore_args', $ignoreArgs === false ? '0' : $ignoreArgs);
        }

        self::assertNotNull($caught, 'Expected the call to throw.');
        self::assertNotInstanceOf(IndeterminateOrderException::class, $caught);
        self::assertCount(1, $transport->calls);
        self::assertStringContainsString('Fil3-Byt3s-Canary', (string) $transport->calls[0]->rawBody);

        $traces = '';
        for ($exception = $caught; $exception !== null; $exception = $exception->getPrevious()) {
            $traces .= print_r($exception->getTrace(), true) . $exception->getMessage();
        }

        // Arguments were recorded, and the sensitive ones were replaced.
        self::assertStringContainsString('application/x-args-are-kept', $traces);
        self::assertStringContainsString('SensitiveParameterValue', $traces);
        self::assertStringNotContainsString('Fil3-Byt3s-Canary', $traces);
        self::assertStringNotContainsString('Jane Doe purchase order', $traces);
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, int|string> */
    private static function uploaded(): array
    {
        return [
            'Token' => '60050460-7a2d-42a8-a4dd-5cef88ad8374',
            'FileName' => 'purchase-order-4711.pdf',
            'ContentType' => 'application/pdf',
            'Size' => 48213,
        ];
    }

    private static function boundary(CapturedRequest $call): string
    {
        $contentType = $call->headers['Content-Type'] ?? '';
        if (preg_match('/^multipart\/form-data; boundary=([0-9A-Za-z\'()+_,.\/:=?-]{1,70})$/D', $contentType, $match) !== 1) {
            self::fail('Not a multipart/form-data Content-Type with a valid boundary: ' . $contentType);
        }

        return $match[1];
    }

    /** @return resource */
    private static function memoryStream(string $bytes)
    {
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            self::fail('Could not open a memory stream.');
        }
        fwrite($stream, $bytes);

        return $stream;
    }

    /** @return resource */
    private static function closedStream()
    {
        $stream = self::memoryStream(self::BYTES);
        fclose($stream);

        return $stream;
    }

    private static function varDump(mixed $value): string
    {
        ob_start();
        var_dump($value);

        return (string) ob_get_clean();
    }
}
