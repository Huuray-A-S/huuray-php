<?php

declare(strict_types=1);

namespace Huuray\Tests;

use Huuray\Http\CurlTransport;
use Huuray\Http\HttpRequest;
use Huuray\Http\TransportException;
use Huuray\HuurayClient;
use PHPUnit\Framework\TestCase;

/**
 * The default transport's cURL options, pinned without a network call.
 *
 * The timeout options are the ones that matter most: without them a hung
 * POST /v4/Order never fails, IndeterminateOrderException is never thrown, and
 * the caller gets no signal to reconcile.
 */
final class CurlTransportTest extends TestCase
{
    public function testEnforcesTheTimeoutForTheWholeExchangeAndForConnecting(): void
    {
        $options = (new CurlTransport())->buildOptions(self::request('POST', '{"Sync":false}', 45_000));

        self::assertSame(45_000, $options[CURLOPT_TIMEOUT_MS]);
        self::assertSame(45_000, $options[CURLOPT_CONNECTTIMEOUT_MS]);
        self::assertTrue($options[CURLOPT_NOSIGNAL]);
    }

    public function testTheClientDefaultTimeoutReachesCurl(): void
    {
        $options = (new CurlTransport())->buildOptions(self::request('GET', null, HuurayClient::DEFAULT_TIMEOUT_MS));

        self::assertSame(30_000, $options[CURLOPT_TIMEOUT_MS]);
    }

    public function testRefusesToSendWithoutATimeout(): void
    {
        $this->expectException(TransportException::class);

        (new CurlTransport())->buildOptions(self::request('GET', null, 0));
    }

    public function testSendsNoBodyAtAllForABodylessPost(): void
    {
        // POST /v4/Template declares no request body.
        $options = (new CurlTransport())->buildOptions(self::request('POST', null));

        self::assertSame('POST', $options[CURLOPT_CUSTOMREQUEST]);
        self::assertArrayNotHasKey(CURLOPT_POSTFIELDS, $options);
        self::assertContains('Content-Length: 0', self::headers($options));
    }

    public function testSendsTheJsonBodyOnDelete(): void
    {
        // DELETE /v4/Cancel takes a JSON body.
        $options = (new CurlTransport())->buildOptions(self::request('DELETE', '{"OrderUID":"uid"}'));

        self::assertSame('DELETE', $options[CURLOPT_CUSTOMREQUEST]);
        self::assertSame('{"OrderUID":"uid"}', $options[CURLOPT_POSTFIELDS]);
        self::assertNotContains('Content-Length: 0', self::headers($options));
    }

    public function testSendsNoBodyForAGet(): void
    {
        $options = (new CurlTransport())->buildOptions(self::request('GET', null));

        self::assertArrayNotHasKey(CURLOPT_POSTFIELDS, $options);
        self::assertNotContains('Content-Length: 0', self::headers($options));
    }

    public function testPassesEveryHeaderThrough(): void
    {
        $options = (new CurlTransport())->buildOptions(self::request('GET', null));
        $headers = self::headers($options);

        self::assertContains('X-API-TOKEN: tok', $headers);
        self::assertContains('X-API-NONCE: nonce', $headers);
        self::assertContains('X-API-HASH: hash', $headers);
        self::assertContains('Expect:', $headers);
    }

    public function testNeverFollowsARedirectAndVerifiesTls(): void
    {
        $options = (new CurlTransport())->buildOptions(self::request('GET', null));

        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        self::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
        self::assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
        self::assertSame('https://api.huuray.com/v4/Balance', $options[CURLOPT_URL]);
    }

    private static function request(string $method, ?string $body, int $timeoutMs = 30_000): HttpRequest
    {
        return new HttpRequest(
            $method,
            'https://api.huuray.com/v4/Balance',
            ['X-API-TOKEN' => 'tok', 'X-API-NONCE' => 'nonce', 'X-API-HASH' => 'hash'],
            $body,
            $timeoutMs,
        );
    }

    /**
     * @param array<int, mixed> $options
     *
     * @return list<string>
     */
    private static function headers(array $options): array
    {
        $headers = $options[CURLOPT_HTTPHEADER] ?? null;
        if (!is_array($headers)) {
            self::fail('No CURLOPT_HTTPHEADER set.');
        }

        return array_values(array_filter($headers, 'is_string'));
    }
}
