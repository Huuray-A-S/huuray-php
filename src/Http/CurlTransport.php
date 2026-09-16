<?php

declare(strict_types=1);

namespace Huuray\Http;

use Huuray\Exception\ConfigurationException;

/**
 * The default transport, built on ext-curl. No Composer dependencies.
 *
 * It always enforces the request's timeout — both `CURLOPT_TIMEOUT_MS` for the
 * whole exchange and `CURLOPT_CONNECTTIMEOUT_MS` for connecting. That is not a
 * tuning detail: without a timeout a hung `POST /v4/Order` never fails, so the
 * caller never learns the outcome is unknown and never reconciles.
 *
 * Why not PSR-18: it has no timeout abstraction, so a client built on it could
 * not guarantee the rule above. Wrap your PSR-18 client in a {@see Transport}
 * of your own if you need one, and enforce the timeout there.
 */
final class CurlTransport implements Transport
{
    private ?\CurlHandle $handle = null;

    public function __construct()
    {
        if (!\function_exists('curl_init')) {
            throw new ConfigurationException(
                'The default transport needs the curl extension. Enable ext-curl, or pass your own '
                . 'Huuray\Http\Transport to HuurayClient.',
            );
        }
    }

    public function send(
        #[\SensitiveParameter]
        HttpRequest $request,
    ): HttpResponse {
        $handle = $this->handle();

        if (!curl_setopt_array($handle, $this->buildOptions($request))) {
            throw new TransportException(sprintf('Could not configure cURL for %s %s.', $request->method, $request->url));
        }

        // curl_exec reads the entire body before returning, so a connection that
        // drops mid-body fails right here, inside the same error handling as a
        // failure to connect.
        $body = curl_exec($handle);

        if (!is_string($body)) {
            $errno = curl_errno($handle);
            $message = sprintf('cURL error %d: %s', $errno, curl_error($handle));
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new TransportTimeoutException($message);
            }

            throw new TransportException($message);
        }

        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return new HttpResponse(is_int($status) ? $status : 0, $body);
    }

    /**
     * The cURL options for one request.
     *
     * @internal Public so the test suite can pin the options without a network call.
     *
     * @return array<int, mixed>
     */
    public function buildOptions(
        #[\SensitiveParameter]
        HttpRequest $request,
    ): array {
        if ($request->timeoutMs < 1 || $request->timeoutMs > 2_147_483_647) {
            throw new TransportException('Refusing to send a request without a timeout.');
        }

        // An empty "Expect:" stops cURL from sending "Expect: 100-continue" on
        // larger bodies, which adds a round trip and confuses some proxies.
        $headers = ['Expect:'];
        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            // Never follow a redirect: it would carry the signed headers to
            // wherever the Location points.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Required for millisecond timeouts to work reliably on systems whose
            // resolver would otherwise rely on signals.
            CURLOPT_NOSIGNAL => true,
            CURLOPT_TIMEOUT_MS => $request->timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $request->timeoutMs,
            // Accept any encoding cURL can decode, and decode it transparently.
            CURLOPT_ENCODING => '',
        ];

        if ($request->body !== null) {
            // With CURLOPT_CUSTOMREQUEST this carries the body on DELETE too —
            // DELETE /v4/Cancel takes a JSON body.
            $options[CURLOPT_POSTFIELDS] = $request->body;
        } elseif (in_array(strtoupper($request->method), ['POST', 'PUT', 'PATCH'], true)) {
            // No body at all — POST /v4/Template declares none — but still an
            // explicit zero length, as browsers and other HTTP stacks send, so no
            // server waits for a body that is not coming.
            $headers[] = 'Content-Length: 0';
        }

        $options[CURLOPT_HTTPHEADER] = $headers;

        return $options;
    }

    /** One handle, reused so connections and TLS sessions are kept between requests. */
    private function handle(): \CurlHandle
    {
        if ($this->handle !== null) {
            curl_reset($this->handle);

            return $this->handle;
        }

        $handle = curl_init();
        if ($handle === false) {
            throw new TransportException('curl_init() failed.');
        }

        return $this->handle = $handle;
    }
}
