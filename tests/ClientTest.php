<?php

declare(strict_types=1);

namespace Huuray\Tests;

use Huuray\Exception\ApiException;
use Huuray\Exception\AuthException;
use Huuray\Exception\ConfigurationException;
use Huuray\Exception\ConnectionException;
use Huuray\Exception\HuurayException;
use Huuray\Exception\NotFoundException;
use Huuray\Exception\ServerException;
use Huuray\Exception\TimeoutException;
use Huuray\Exception\ValidationException;
use Huuray\Http\TransportException;
use Huuray\Http\TransportTimeoutException;
use Huuray\HuurayClient;
use Huuray\RetryOptions;
use Huuray\Tests\Support\CapturedRequest;
use Huuray\Tests\Support\FakeTransport;
use Huuray\Tests\Support\MockResponse;
use Huuray\Tests\Support\TestClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    // ---------------------------------------------------------- construction

    public function testRequiresAnApiToken(): void
    {
        $this->expectException(ConfigurationException::class);

        new HuurayClient(apiToken: '', apiSecret: 's', transport: new FakeTransport());
    }

    public function testRequiresAnApiSecret(): void
    {
        $this->expectException(ConfigurationException::class);

        new HuurayClient(apiToken: 't', apiSecret: '', transport: new FakeTransport());
    }

    public function testTreatsAnApiTokenOfOnlySpacesAsMissingBeforeSendingAnything(): void
    {
        // cURL drops a header whose value is only spaces, so the request would go
        // out with no X-API-TOKEN at all.
        $transport = new FakeTransport();

        try {
            $client = new HuurayClient(apiToken: '   ', apiSecret: 's', transport: $transport);
            $client->balances->list();
            self::fail('Expected a blank apiToken to be rejected.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('apiToken is required', $e->getMessage());
        }

        self::assertCount(0, $transport->calls);
    }

    /** @return iterable<string, array{string}> */
    public static function tokensWithControlCharacters(): iterable
    {
        yield 'CRLF injecting a header' => ["tok-7f3a9c\r\nX-Injected: yes"];
        yield 'trailing newline, as read from a file' => ["tok-7f3a9c\n"];
        yield 'tab' => ["tok-7f3a9c\tx"];
        yield 'NUL' => ["tok-7f3a9c\0x"];
        yield 'DEL' => ["tok-7f3a9c\x7F"];
    }

    #[DataProvider('tokensWithControlCharacters')]
    public function testRejectsAnApiTokenWithAControlCharacterBeforeSendingAnything(string $apiToken): void
    {
        // Rejected rather than trimmed, without quoting the token. A line break
        // reaching the wire injects headers, or ends the header block early.
        $transport = new FakeTransport();

        try {
            $client = new HuurayClient(apiToken: $apiToken, apiSecret: 's', transport: $transport);
            $client->balances->list();
            self::fail('Expected the apiToken to be rejected.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('apiToken', $e->getMessage());
            self::assertStringNotContainsString('tok-7f3a9c', $e->getMessage());
        }

        self::assertCount(0, $transport->calls);
    }

    public function testRejectsAUserAgentWithALineBreakBeforeSendingAnything(): void
    {
        $transport = new FakeTransport();

        try {
            $client = new HuurayClient(apiToken: 'tok-7f3a9c', apiSecret: 's', transport: $transport, userAgent: "my-app/1.0\r\nX-Injected: yes");
            $client->balances->list();
            self::fail('Expected the userAgent to be rejected.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('userAgent', $e->getMessage());
            self::assertStringNotContainsString('X-Injected', $e->getMessage());
            self::assertStringNotContainsString('tok-7f3a9c', $e->getMessage());
        }

        self::assertCount(0, $transport->calls);
    }

    public function testAcceptsAnOrdinaryTokenUserAgentAndNonce(): void
    {
        $transport = new FakeTransport();
        $client = new HuurayClient(
            apiToken: 'tok-7f3a9c',
            apiSecret: 's',
            transport: $transport,
            userAgent: 'my-app/1.0 (+https://example.com)',
            nonceFactory: static fn(): string => '!visible-ASCII_0123~',
        );
        $client->balances->list();

        self::assertCount(1, $transport->calls);
        self::assertSame('tok-7f3a9c', $transport->calls[0]->headers['X-API-TOKEN']);
        self::assertSame('huuray-php/' . HuurayClient::VERSION . ' my-app/1.0 (+https://example.com)', $transport->calls[0]->headers['User-Agent']);
        self::assertSame('!visible-ASCII_0123~', $transport->calls[0]->headers['X-API-NONCE']);
    }

    public function testDefaultsToTheProductionHost(): void
    {
        [$client, $transport] = TestClient::make();
        $client->balances->list();

        // Pins the actual origin, not just the path — a typo in DEFAULT_BASE_URL
        // must not ship green.
        self::assertSame('https://api.huuray.com', $transport->calls[0]->origin);
        self::assertSame('/v4/Balance', $transport->calls[0]->path);
    }

    /** @return iterable<string, array{string}> */
    public static function badBaseUrls(): iterable
    {
        yield 'relative path' => ['/v4'];
        yield 'bare word' => ['v4'];
        yield 'host without scheme' => ['api.huuray.com'];
        yield 'file scheme' => ['file:///x'];
        yield 'ftp scheme' => ['ftp://x'];
        yield 'empty' => [''];
        yield 'NUL' => ["http://127.0.0.1:8080/a\0b"];
        yield 'CRLF injecting a header' => ["https://api.huuray.com/v4\r\nX-Injected: yes"];
        yield 'space' => ['https://api.huuray.com /v4'];
        yield 'non-ASCII' => ["https://api.huur\u{E4}y.com"];
    }

    #[DataProvider('badBaseUrls')]
    public function testRejectsABaseUrlThatIsNotAbsoluteHttp(string $baseUrl): void
    {
        $this->expectException(ConfigurationException::class);

        new HuurayClient(apiToken: 't', apiSecret: 's', baseUrl: $baseUrl, transport: new FakeTransport());
    }

    /** @return iterable<string, array{string}> */
    public static function goodBaseUrls(): iterable
    {
        yield 'https' => ['https://api.huuray.com'];
        yield 'http with port' => ['http://localhost:8080'];
    }

    #[DataProvider('goodBaseUrls')]
    public function testAcceptsAnAbsoluteHttpBaseUrl(string $baseUrl): void
    {
        $client = new HuurayClient(apiToken: 't', apiSecret: 's', baseUrl: $baseUrl, transport: new FakeTransport());

        self::assertInstanceOf(HuurayClient::class, $client);
    }

    public function testAcceptsABaseUrlWithATrailingSlash(): void
    {
        [$client, $transport] = TestClient::make(baseUrl: 'https://example.test/');
        $client->balances->list();

        self::assertSame('https://example.test', $transport->calls[0]->origin);
        self::assertSame('/v4/Balance', $transport->calls[0]->path);
    }

    public function testRejectsAnUnknownHashEncodingRatherThanGuessing(): void
    {
        $this->expectException(ConfigurationException::class);

        new HuurayClient(apiToken: 't', apiSecret: 's', hashEncoding: 'sha512-hex', transport: new FakeTransport());
    }

    public function testRejectsATimeoutThatWouldMeanNoTimeoutAtAll(): void
    {
        // The default transport reads 0 as "wait forever", and a hung order that
        // never fails can never be reconciled.
        $this->expectException(ConfigurationException::class);

        new HuurayClient(apiToken: 't', apiSecret: 's', timeoutMs: 0, transport: new FakeTransport());
    }

    /** @return iterable<string, array{int}> */
    public static function timeoutsCurlCannotHold(): iterable
    {
        // Where a C long is 32 bits (Windows), cURL refuses 2^31 and reads 2^32 as 0 — no timeout.
        yield '2^31' => [2 ** 31];
        yield '2^32' => [2 ** 32];
    }

    #[DataProvider('timeoutsCurlCannotHold')]
    public function testRejectsATimeoutTooLargeForCurl(int $timeoutMs): void
    {
        $this->expectException(ConfigurationException::class);

        new HuurayClient(apiToken: 't', apiSecret: 's', timeoutMs: $timeoutMs, transport: new FakeTransport());
    }

    public function testHandsTheTimeoutToTheTransportOnEveryRequest(): void
    {
        [$client, $transport] = TestClient::make(timeoutMs: 45_000);
        $client->balances->list();

        self::assertSame(45_000, $transport->calls[0]->timeoutMs);
    }

    public function testDefaultsTheTimeoutTo30Seconds(): void
    {
        [$client, $transport] = TestClient::make();
        $client->balances->list();

        self::assertSame(30_000, $transport->calls[0]->timeoutMs);
    }

    // ------------------------------------------------------ signing per request

    public function testSendsTheThreeAuthHeadersOnEveryCall(): void
    {
        [$client, $transport] = TestClient::make();
        $client->balances->list();
        $client->templates->list();

        foreach ($transport->calls as $call) {
            self::assertSame('test-token', $call->headers['X-API-TOKEN']);
            self::assertNotSame('', $call->headers['X-API-NONCE']);
            self::assertMatchesRegularExpression('/^[0-9a-f]{128}$/', $call->headers['X-API-HASH']);
            self::assertSame(hash('sha512', 'test-secret' . $call->headers['X-API-NONCE']), $call->headers['X-API-HASH']);
        }
    }

    public function testUsesAFreshNonceForEveryRequest(): void
    {
        [$client, $transport] = TestClient::make();
        $client->balances->list();
        $client->balances->list();
        $client->balances->list();

        $nonces = array_map(static fn(CapturedRequest $call): string => $call->headers['X-API-NONCE'], $transport->calls);

        self::assertCount(3, array_unique($nonces));
    }

    public function testNeverSendsTheSecret(): void
    {
        [$client, $transport] = TestClient::make();
        $client->balances->list();

        self::assertStringNotContainsString('test-secret', serialize($transport->calls[0]));
    }

    public function testHonoursAHashEncodingOverride(): void
    {
        [$client, $transport] = TestClient::make(hashEncoding: 'base64');
        $client->balances->list();

        self::assertDoesNotMatchRegularExpression('/^[0-9a-f]{128}$/', $transport->calls[0]->headers['X-API-HASH']);
    }

    public function testIdentifiesItselfAndAppendsACallerSuppliedAgent(): void
    {
        [$client, $transport] = TestClient::make(userAgent: 'my-app/1.0');
        $client->balances->list();

        self::assertSame('huuray-php/' . HuurayClient::VERSION . ' my-app/1.0', $transport->calls[0]->headers['User-Agent']);
        self::assertSame('application/json', $transport->calls[0]->headers['Accept']);
    }

    public function testUsesACustomNonceFactoryForSigning(): void
    {
        [$client, $transport] = TestClient::make(nonceFactory: static fn(): string => 'fixed-nonce-for-test');
        $client->balances->list();

        self::assertSame('fixed-nonce-for-test', $transport->calls[0]->headers['X-API-NONCE']);
    }

    public function testRejectsACustomNonceOverTheLimitBeforeSending(): void
    {
        [$client, $transport] = TestClient::make(nonceFactory: static fn(): string => str_repeat('x', 64));

        try {
            $client->balances->list();
            self::fail('Expected an over-long nonce to be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('at most 50', $e->getMessage());
        }

        self::assertCount(0, $transport->calls);
    }

    /** @return iterable<string, array{string}> */
    public static function noncesOutsideVisibleAscii(): iterable
    {
        yield 'line break' => ["abc\ndef"];
        yield 'a space' => [' '];
        yield 'an inner space' => ['abc def'];
        yield 'empty, which cURL would drop as a header' => [''];
    }

    #[DataProvider('noncesOutsideVisibleAscii')]
    public function testRejectsACustomNonceOutsideVisibleAsciiBeforeSending(string $nonce): void
    {
        [$client, $transport] = TestClient::make(nonceFactory: static fn(): string => $nonce);

        try {
            $client->balances->list();
            self::fail('Expected the nonce to be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('visible ASCII', $e->getMessage());
            self::assertStringNotContainsString(TestClient::TOKEN, $e->getMessage());
        }

        self::assertCount(0, $transport->calls);
    }

    /** @return iterable<string, array{string}> */
    public static function headerBreaks(): iterable
    {
        yield 'CRLF' => ["\r\n"];
        yield 'CR' => ["\r"];
        yield 'LF' => ["\n"];
        yield 'NUL' => ["\0"];
    }

    #[DataProvider('headerBreaks')]
    public function testRefusesAHeaderWithALineBreakEvenIfTheConstructorCheckWasBypassed(string $break): void
    {
        // The backstop in send() is unreachable through the public API — the
        // constructor rejects such a token first — so a client holding one is
        // built through reflection.
        [$client, $transport] = TestClient::withUncheckedApiToken("tok-7f3a9c{$break}X-Injected: yes");

        try {
            $client->request('POST', '/v4/Order', ['RefID' => 'r']);
            self::fail('Expected the request to be refused.');
        } catch (\InvalidArgumentException|HuurayException $e) {
            // Never mapped to ConnectionException: nothing was sent.
            self::assertInstanceOf(\InvalidArgumentException::class, $e);
            self::assertStringContainsString('X-API-TOKEN', $e->getMessage());
            self::assertStringNotContainsString('tok-7f3a9c', $e->getMessage());
            self::assertStringNotContainsString('X-Injected', $e->getMessage());
        }

        self::assertCount(0, $transport->calls);
    }

    // ---------------------------------------------------------- error mapping

    /** @return iterable<string, array{int, class-string<ApiException>}> */
    public static function statusMappings(): iterable
    {
        yield '401' => [401, AuthException::class];
        yield '403' => [403, AuthException::class];
        yield '404' => [404, NotFoundException::class];
        yield '422' => [422, ValidationException::class];
        yield '500' => [500, ServerException::class];
        yield '503' => [503, ServerException::class];
        yield '400' => [400, ApiException::class];
    }

    /** @param class-string<ApiException> $expected */
    #[DataProvider('statusMappings')]
    public function testMapsEachHttpStatusToTheRightExceptionType(int $status, string $expected): void
    {
        [$client] = TestClient::make(new MockResponse(status: $status, json: ['Status' => $status, 'StatusMessage' => 'nope']));

        try {
            $client->balances->list();
            self::fail('Expected an exception.');
        } catch (ApiException $e) {
            self::assertSame($expected, $e::class);
            self::assertInstanceOf(HuurayException::class, $e);
        }
    }

    public function testPrefersStatusMessageOverTheDeprecatedMessageField(): void
    {
        [$client] = TestClient::make(new MockResponse(status: 400, json: ['Status' => 400, 'Message' => 'old text', 'StatusMessage' => 'new text']));

        $e = $this->captureApiException(static fn() => $client->balances->list());

        self::assertSame('new text', $e->statusMessage);
        self::assertStringContainsString('new text', $e->getMessage());
    }

    public function testFallsBackToMessageWhenStatusMessageIsAbsent(): void
    {
        [$client] = TestClient::make(new MockResponse(status: 400, json: ['Status' => 400, 'Message' => 'old text']));

        self::assertSame('old text', $this->captureApiException(static fn() => $client->balances->list())->statusMessage);
    }

    public function testExposesTheHttpStatusAndTheParsedBody(): void
    {
        [$client] = TestClient::make(new MockResponse(status: 422, json: ['Status' => 422, 'StatusMessage' => 'bad']));

        $e = $this->captureApiException(static fn() => $client->balances->list());

        self::assertSame(422, $e->httpStatus);
        self::assertSame(422, $e->getCode());
        self::assertSame(422, $e->status);
        self::assertSame('GET', $e->method);
        self::assertSame('/v4/Balance', $e->path);
        self::assertSame(['Status' => 422, 'StatusMessage' => 'bad'], $e->body);
    }

    public function testRedactsBearerAndContactFieldsFromTheRetainedErrorBody(): void
    {
        [$client] = TestClient::make(new MockResponse(
            status: 400,
            json: ['Status' => 400, 'StatusMessage' => 'bad', 'Code' => 'LEAKED-CODE', 'Email' => 'jane@example.com'],
        ));

        $e = $this->captureApiException(static fn() => $client->balances->list());
        $dumped = json_encode($e->body, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('LEAKED-CODE', $dumped);
        self::assertStringNotContainsString('jane@example.com', $dumped);
    }

    public function testSurvivesANonJsonErrorBody(): void
    {
        [$client] = TestClient::make(new MockResponse(status: 502, text: '<html>Bad gateway</html>'));

        $e = $this->captureApiException(static fn() => $client->balances->list());

        self::assertSame(502, $e->httpStatus);
        self::assertNull($e->statusMessage);
        self::assertNull($e->body);
    }

    // ------------------------------------------------------------ retry policy

    public function testRetriesAReadOn503(): void
    {
        [$client, $transport] = TestClient::make(
            [new MockResponse(status: 503), new MockResponse(json: ['Balances' => []])],
            retry: new RetryOptions(maxRetries: 2, baseDelayMs: 1),
        );
        $client->balances->list();

        self::assertCount(2, $transport->calls);
    }

    public function testNeverRetriesAnOrderEvenOn503(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(status: 503), retry: new RetryOptions(maxRetries: 3, baseDelayMs: 1));

        try {
            $client->orders->create(productToken: 't', value: 100, currency: 'DKK', quantity: 1);
        } catch (HuurayException) {
        }

        self::assertCount(1, $transport->calls);
    }

    public function testNeverRetriesAResendItWouldReDeliverRealValue(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(status: 503), retry: new RetryOptions(maxRetries: 3, baseDelayMs: 1));

        try {
            $client->orders->resend(orderUid: 'x');
        } catch (HuurayException) {
        }

        self::assertCount(1, $transport->calls);
    }

    public function testNeverRetriesACancel(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(status: 503), retry: new RetryOptions(maxRetries: 3, baseDelayMs: 1));

        try {
            $client->orders->cancel(orderUid: 'x');
        } catch (HuurayException) {
        }

        self::assertCount(1, $transport->calls);
    }

    public function testNeverRetriesAnOrderAfterAConnectionFailure(): void
    {
        [$client, $transport] = TestClient::make(
            new MockResponse(throws: new TransportException('connection reset')),
            retry: new RetryOptions(maxRetries: 3, baseDelayMs: 1),
        );

        try {
            $client->orders->create(productToken: 't', value: 100, currency: 'DKK', quantity: 1, refId: 'r');
        } catch (HuurayException) {
        }

        self::assertCount(1, $transport->calls);
    }

    public function testDoesNotRetryA400TheRequestIsWrongRepeatingWillNotHelp(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(status: 400), retry: new RetryOptions(maxRetries: 3, baseDelayMs: 1));

        try {
            $client->balances->list();
        } catch (HuurayException) {
        }

        self::assertCount(1, $transport->calls);
    }

    public function testRetriesAReadAfterAConnectionFailure(): void
    {
        [$client, $transport] = TestClient::make(
            [new MockResponse(throws: new TransportException('connection refused')), new MockResponse(json: ['Balances' => []])],
            retry: new RetryOptions(maxRetries: 2, baseDelayMs: 1),
        );

        self::assertSame([], $client->balances->list()->balances);
        self::assertCount(2, $transport->calls);
    }

    public function testTreatsAnUnsetRetryOptionAsTheDefaultNotAsDisabled(): void
    {
        // RetryOptions(maxRetries: null) is the natural result of threading optional
        // config. It must fall back to the default of 2 retries, never disable them.
        $retry = new RetryOptions(maxRetries: null, baseDelayMs: 1, maxDelayMs: null);
        self::assertSame(RetryOptions::DEFAULT_MAX_RETRIES, $retry->maxRetries);
        self::assertSame(RetryOptions::DEFAULT_MAX_DELAY_MS, $retry->maxDelayMs);

        [$client, $transport] = TestClient::make(
            [new MockResponse(status: 503), new MockResponse(status: 503), new MockResponse(json: ['Balances' => []])],
            retry: $retry,
        );

        self::assertSame([], $client->balances->list()->balances);
        self::assertCount(3, $transport->calls);
    }

    public function testClampsANegativeMaxRetriesToZeroInsteadOfNeverSending(): void
    {
        [$client, $transport] = TestClient::make(retry: new RetryOptions(maxRetries: -3));
        $client->balances->list();

        self::assertCount(1, $transport->calls);
    }

    public function testSignsEveryRetryAfresh(): void
    {
        [$client, $transport] = TestClient::make(
            [new MockResponse(status: 503), new MockResponse(json: ['Balances' => []])],
            retry: new RetryOptions(maxRetries: 1, baseDelayMs: 1),
        );
        $client->balances->list();

        self::assertNotSame($transport->calls[0]->headers['X-API-NONCE'], $transport->calls[1]->headers['X-API-NONCE']);
    }

    // ----------------------------------------------------------- transport faults

    public function testMapsATransportFailureIntoTheTaxonomyNotARawException(): void
    {
        [$client] = TestClient::make(new MockResponse(throws: new TransportException('cURL error 7: Failed to connect')));

        try {
            $client->balances->list();
            self::fail('Expected a ConnectionException.');
        } catch (ConnectionException $e) {
            self::assertNotInstanceOf(TimeoutException::class, $e);
            self::assertSame('GET', $e->method);
            self::assertInstanceOf(TransportException::class, $e->getPrevious());
        }
    }

    public function testMapsAMidBodyConnectionDropIntoTheTaxonomy(): void
    {
        // cURL error 18: the body stopped arriving after the headers.
        [$client] = TestClient::make(new MockResponse(throws: new TransportException('cURL error 18: transfer closed with outstanding read data remaining')));

        $this->expectException(ConnectionException::class);

        $client->balances->list();
    }

    public function testMapsAnyThrowableFromACustomTransportIntoTheTaxonomy(): void
    {
        [$client] = TestClient::make(new MockResponse(throws: new \RuntimeException('a custom HTTP stack failed')));

        $this->expectException(ConnectionException::class);

        $client->balances->list();
    }

    public function testMapsATimeoutToTimeoutException(): void
    {
        [$client] = TestClient::make(new MockResponse(throws: new TransportTimeoutException('cURL error 28: Operation timed out')), timeoutMs: 1234);

        try {
            $client->balances->list();
            self::fail('Expected a TimeoutException.');
        } catch (TimeoutException $e) {
            self::assertSame(1234, $e->timeoutMs);
            self::assertStringContainsString('timed out after 1234ms', $e->getMessage());
        }
    }

    public function testTreatsAGarbled200BodyAsATransportFaultNeverAsAnEmptyResult(): void
    {
        // An empty result from a garbled /v4/Search response would tell the
        // reconciliation flow "the order did not land" — inviting a double order.
        [$client] = TestClient::make(new MockResponse(status: 200, text: '<html>gateway error</html>'));

        $this->expectException(ConnectionException::class);

        $client->orders->search(refId: 'r');
    }

    public function testTreatsAnEmpty200BodyTheSameWay(): void
    {
        [$client] = TestClient::make(new MockResponse(status: 200, text: ''));

        $this->expectException(ConnectionException::class);

        $client->balances->list();
    }

    public function testNeverQuotesTheBodyInTheErrorItCouldHoldACode(): void
    {
        [$client] = TestClient::make(new MockResponse(status: 200, text: '{"Vouchers":[{"Code":"SECRET-CODE"'));

        try {
            $client->orders->search(refId: 'r');
            self::fail('Expected a ConnectionException.');
        } catch (ConnectionException $e) {
            self::assertStringNotContainsString('SECRET-CODE', $e->getMessage());
        }
    }

    public function testRetriesARetryableReadAfterAGarbledBody(): void
    {
        [$client, $transport] = TestClient::make(
            [new MockResponse(status: 200, text: 'not json'), new MockResponse(json: ['Balances' => []])],
            retry: new RetryOptions(maxRetries: 2, baseDelayMs: 1),
        );

        self::assertSame([], $client->balances->list()->balances);
        self::assertCount(2, $transport->calls);
    }

    public function testToleratesAByteOrderMarkBeforeTheJson(): void
    {
        [$client] = TestClient::make(new MockResponse(text: "\xEF\xBB\xBF" . '{"Balances":[{"Currency":"DKK","Balance":1,"Master":true}]}'));

        self::assertSame('DKK', $client->balances->list()->balances[0]->currency);
    }

    // -------------------------------------------------------- request() escape hatch

    public function testRequestCallsAnyEndpointWithSigningHandled(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(json: ['OrderUID' => 'abc']));

        $out = $client->request('POST', '/v4/Search', ['RefID' => 'payroll-2026-08-jane']);

        self::assertSame(['OrderUID' => 'abc'], $out);
        self::assertSame(['RefID' => 'payroll-2026-08-jane'], $transport->calls[0]->body);
        self::assertSame('application/json', $transport->calls[0]->headers['Content-Type']);
        self::assertNotSame('', $transport->calls[0]->headers['X-API-HASH']);
    }

    public function testRequestDoesNotRetryUnlessAskedTo(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(status: 503), retry: new RetryOptions(maxRetries: 3, baseDelayMs: 1));

        try {
            $client->request('POST', '/v4/Search', ['RefID' => 'x']);
        } catch (ServerException) {
        }

        self::assertCount(1, $transport->calls);
    }

    public function testRequestSendsNoBodyAndNoContentTypeWhenTheBodyIsNull(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(json: ['Templates' => []]));

        $client->request('POST', '/v4/Template');

        self::assertTrue($transport->calls[0]->bodyOmitted);
        self::assertArrayNotHasKey('Content-Type', $transport->calls[0]->headers);
    }

    /** @return iterable<string, array{string, string}> */
    public static function methodsAndPathsThatAreNotSafeToSend(): iterable
    {
        // The method is written into the request line as given, and the path is
        // appended to the base URL — so either could inject a header, smuggle a
        // second request carrying the credentials, or move the host.
        yield 'method: CRLF injecting a header' => ["GET /v4/Balance HTTP/1.1\r\nX-Injected: yes\r\nX-Pad: GET", '/v4/Balance'];
        yield 'method: smuggling a second request' => ["GET /v4/Balance HTTP/1.1\r\nHost: x\r\nContent-Length: 0\r\n\r\nPOST", '/v4/Order'];
        yield 'method: NUL' => ["GET\0", '/v4/Balance'];
        yield 'method: bare CR' => ["GET\r", '/v4/Balance'];
        yield 'path: bare CR' => ['GET', "/v4/Balance\r"];
        yield 'method: space' => ['GET /v4/Balance', '/v4/Balance'];
        yield 'method: empty' => ['', '/v4/Balance'];
        yield 'method: tab' => ["GET\t", '/v4/Balance'];
        yield 'method: trailing line feed' => ["GET\n", '/v4/Balance'];
        yield 'path: userinfo moving the host' => ['GET', '@attacker.example/v4/Balance'];
        yield 'path: extending the host name' => ['GET', '.attacker.example/v4/Balance'];
        yield 'path: changing the port' => ['GET', ':8443/v4/Balance'];
        yield 'path: no leading slash' => ['GET', 'v4/Balance'];
        yield 'path: CRLF injecting a header' => ['GET', "/v4/Balance\r\nX-Injected: yes"];
        yield 'path: trailing line feed' => ['GET', "/v4/Balance\n"];
        yield 'path: space' => ['GET', '/v4/Ba lance'];
        yield 'path: NUL' => ['GET', "/v4/Balance\0"];
        yield 'path: U+2028' => ['GET', "/v4/\u{2028}"];
        yield 'path: empty' => ['GET', ''];
    }

    #[DataProvider('methodsAndPathsThatAreNotSafeToSend')]
    public function testRequestRefusesAMethodOrPathThatCouldRewriteTheRequestOrMoveTheHost(string $method, string $path): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(json: ['Balances' => []]));

        try {
            $client->request($method, $path);
            self::fail('Expected the request to be refused.');
        } catch (\InvalidArgumentException|HuurayException $e) {
            // Never mapped to ConnectionException: nothing was sent. Neither value is quoted.
            self::assertInstanceOf(\InvalidArgumentException::class, $e);
            if ($path !== '') {
                self::assertStringNotContainsString($path, $e->getMessage());
            }
            self::assertStringNotContainsString('X-Injected', $e->getMessage());
            self::assertStringNotContainsString(TestClient::TOKEN, $e->getMessage());
            self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $e->getMessage());
        }

        self::assertCount(0, $transport->calls);
    }

    public function testRequestStillSendsAnOrdinaryMethodAndPath(): void
    {
        [$client, $transport] = TestClient::make(new MockResponse(json: ['Balances' => []]));

        self::assertSame(['Balances' => []], $client->request('GET', '/v4/Balance'));
        self::assertCount(1, $transport->calls);
        self::assertSame('GET', $transport->calls[0]->method);
        self::assertSame('https://api.huuray.com', $transport->calls[0]->origin);
        self::assertSame('/v4/Balance', $transport->calls[0]->path);
    }

    // ----------------------------------------------------------------- version

    public function testVersionMatchesTheNewestVersionedHeadingInTheChangelog(): void
    {
        // VERSION is sent in every User-Agent; a release must not misreport itself.
        $changelog = file_get_contents(dirname(__DIR__) . '/CHANGELOG.md');
        if ($changelog === false || preg_match('/^## \[(\d+\.\d+\.\d+)\]/m', $changelog, $match) !== 1) {
            self::fail('CHANGELOG.md has no versioned heading such as "## [0.1.0]".');
        }

        self::assertSame($match[1], HuurayClient::VERSION, 'HuurayClient::VERSION must match the newest version in CHANGELOG.md.');
    }

    /** @param \Closure(): mixed $call */
    private function captureApiException(\Closure $call): ApiException
    {
        try {
            $call();
        } catch (ApiException $e) {
            return $e;
        }

        self::fail('Expected an ApiException.');
    }
}
