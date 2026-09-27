<?php

declare(strict_types=1);

namespace Huuray\Tests;

use Huuray\Http\CurlTransport;
use Huuray\Http\HttpRequest;
use Huuray\Http\TransportException;
use Huuray\HuurayClient;
use Huuray\Internal\ResponseHeaders;
use Huuray\RawResponse;
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

    public function testSendsAMultipartBodyByteForByteWithItsContentType(): void
    {
        // An upload's body: binary, NUL bytes included, delimited by the boundary in the header.
        $body = "--b\r\nContent-Disposition: form-data; name=\"File\"; filename=\"po.pdf\"\r\n"
            . "Content-Type: application/pdf\r\n\r\n%PDF\x00\xFF\r\n--b--\r\n";
        $request = new HttpRequest(
            'POST',
            'https://api.huuray.com/v4/Upload',
            ['X-API-TOKEN' => 'tok', 'Content-Type' => 'multipart/form-data; boundary=b'],
            $body,
            30_000,
        );

        $options = (new CurlTransport())->buildOptions($request);

        self::assertSame($body, $options[CURLOPT_POSTFIELDS]);
        self::assertContains('Content-Type: multipart/form-data; boundary=b', self::headers($options));
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

    // -------------------------------------------------------- response headers

    public function testCollectsTheResponseHeadersLowerCasedAndTrimmed(): void
    {
        $headers = self::collect([
            "HTTP/1.1 202 Accepted\r\n",
            "Content-Type: application/json; charset=utf-8\r\n",
            "Retry-After:   30  \r\n",
            "\r\n",
        ]);

        self::assertSame(['content-type' => 'application/json; charset=utf-8', 'retry-after' => '30'], $headers);
    }

    public function testKeepsOnlyTheLastResponsesHeaders(): void
    {
        // An interim 100 Continue, or a proxy's answer to CONNECT, comes first in the same exchange.
        $headers = self::collect([
            "HTTP/1.1 100 Continue\r\n",
            "Retry-After: 5\r\n",
            "\r\n",
            "HTTP/2 200\r\n",
            "content-type: application/json\r\n",
            "\r\n",
        ]);

        self::assertSame(['content-type' => 'application/json'], $headers);
    }

    public function testJoinsARepeatedHeaderAndUnfoldsAFoldedOne(): void
    {
        $headers = self::collect([
            "HTTP/1.1 200 OK\r\n",
            "Vary: Accept\r\n",
            "vary: Accept-Encoding\r\n",
            "X-Folded: first\r\n",
            " second\r\n",
            "\r\n",
        ]);

        self::assertSame(['vary' => 'Accept, Accept-Encoding', 'x-folded' => 'first second'], $headers);
    }

    public function testAHeaderWhoseNameIsOnlyDigitsDoesNotStopTheClientReadingTheOthers(): void
    {
        $headers = self::collect(["HTTP/1.1 202 Accepted\r\n", "1: x\r\n", "Retry-After: 30\r\n", "\r\n"]);

        // PHP keys the name "1" as an int.
        self::assertSame([1 => 'x', 'retry-after' => '30'], $headers);
        self::assertSame('30', (new RawResponse(null, 202, $headers))->header('Retry-After'));
        self::assertSame('x', (new RawResponse(null, 202, $headers))->header('1'));
    }

    public function testTellsCurlEveryHeaderLineWasHandled(): void
    {
        // Any other return value makes cURL abort the transfer.
        $handle = curl_init();
        self::assertInstanceOf(\CurlHandle::class, $handle);
        $collector = new ResponseHeaders();

        foreach (["HTTP/1.1 200 OK\r\n", "Retry-After: 30\r\n", " folded\r\n", "no colon\r\n", "\r\n"] as $line) {
            self::assertSame(strlen($line), $collector($handle, $line));
        }
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
     * What the header collector keeps after cURL hands it these lines, in order.
     *
     * @param list<string> $lines
     *
     * @return array<string, string>
     */
    private static function collect(array $lines): array
    {
        $handle = curl_init();
        self::assertInstanceOf(\CurlHandle::class, $handle);
        $collector = new ResponseHeaders();
        foreach ($lines as $line) {
            $collector($handle, $line);
        }

        return $collector->all();
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
