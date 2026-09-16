<?php

declare(strict_types=1);

namespace Huuray;

use Huuray\Exception\ApiException;
use Huuray\Exception\ConfigurationException;
use Huuray\Exception\ConnectionException;
use Huuray\Exception\HuurayException;
use Huuray\Exception\IndeterminateOrderException;
use Huuray\Exception\TimeoutException;
use Huuray\Http\CurlTransport;
use Huuray\Http\HttpRequest;
use Huuray\Http\Transport;
use Huuray\Http\TransportTimeoutException;
use Huuray\Internal\RetryPolicy;
use Huuray\Resources\BalancesResource;
use Huuray\Resources\CatalogueResource;
use Huuray\Resources\ExchangeRatesResource;
use Huuray\Resources\OrdersResource;
use Huuray\Resources\StockResource;
use Huuray\Resources\TemplatesResource;
use Huuray\Result\CreateOrderResult;

/**
 * Client for the Huuray API v4.
 *
 *     $huuray = new HuurayClient(
 *         apiToken: (string) getenv('HUURAY_API_TOKEN'),
 *         apiSecret: (string) getenv('HUURAY_API_SECRET'),
 *     );
 *
 *     $balances = $huuray->balances->list()->balances;
 *
 * Every resource method maps onto a single documented v4 operation. Request and
 * response field names match the Huuray API reference exactly, differing only in
 * casing (`OrderUID` becomes `orderUid`).
 */
class HuurayClient
{
    /** This SDK's version, sent in the User-Agent. */
    public const VERSION = '0.1.0';

    /** The production API. The specification declares no `servers` block, so the host is set here. */
    public const DEFAULT_BASE_URL = 'https://api.huuray.com';

    /** Per-request timeout in milliseconds, applied when the client is built without one. */
    public const DEFAULT_TIMEOUT_MS = 30_000;

    public readonly BalancesResource $balances;
    public readonly CatalogueResource $catalogue;
    public readonly TemplatesResource $templates;
    public readonly StockResource $stock;
    public readonly ExchangeRatesResource $exchangeRates;
    public readonly OrdersResource $orders;

    private readonly string $apiToken;
    private readonly string $apiSecret;
    private readonly string $baseUrl;
    private readonly HashEncoding $hashEncoding;
    private readonly int $timeoutMs;
    private readonly RetryOptions $retry;
    private readonly Transport $transport;
    private readonly string $userAgent;

    /** @var \Closure(): string */
    private readonly \Closure $nonceFactory;

    /**
     * @param string                   $apiToken     Your API token. Sent as `X-API-TOKEN`, so it must not contain a
     *                                               control character — trim a value read from a file.
     * @param string                   $apiSecret    Your API secret. Used to sign each request; never sent and never logged.
     * @param string                   $baseUrl      Override the API host. Must be an absolute http(s) URL with no
     *                                               user-info, query or fragment. A trailing slash is fine.
     * @param HashEncoding|string      $hashEncoding Encoding of the `X-API-HASH` digest: 'hex' (default),
     *                                               'hex-upper', 'base64' or 'base64url'. If you see a 401
     *                                               with credentials you know are good, try another value.
     * @param int                      $timeoutMs    Per-request timeout in milliseconds, 1 to 2147483647. Orders have
     *                                               been observed live to take longer than 30 seconds (see
     *                                               CHANGELOG); an order that times out throws
     *                                               IndeterminateOrderException — reconcile it, never retry it.
     * @param RetryOptions|null        $retry        Retry behaviour for read operations. Writes are never retried.
     * @param Transport|null           $transport    Inject a transport — used by the test suite, and for custom
     *                                               HTTP stacks. Defaults to CurlTransport. A custom transport must
     *                                               enforce the timeout itself; see the Transport interface.
     * @param string|null              $userAgent    Appended to the `User-Agent`, e.g. your app name and version.
     *                                               Must not contain a control character.
     * @param (callable(): string)|null $nonceFactory Supply your own nonce. Must be unique per request, unused for
     *                                               60 days, and 1 to 50 characters of visible ASCII. The
     *                                               default (24 random bytes, base64url) is right for almost everyone.
     *
     * @throws ConfigurationException for missing credentials (an apiToken of only spaces counts as missing), a
     *                                control character in apiToken or userAgent, or an invalid baseUrl,
     *                                hashEncoding or timeoutMs
     */
    public function __construct(
        #[\SensitiveParameter]
        string $apiToken,
        #[\SensitiveParameter]
        string $apiSecret,
        #[\SensitiveParameter]
        string $baseUrl = self::DEFAULT_BASE_URL,
        HashEncoding|string $hashEncoding = Auth::DEFAULT_HASH_ENCODING,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
        ?RetryOptions $retry = null,
        ?Transport $transport = null,
        ?string $userAgent = null,
        ?callable $nonceFactory = null,
    ) {
        // A token of only spaces counts as missing: cURL drops a header whose value is blank,
        // so the request would go out with no X-API-TOKEN at all.
        if (trim($apiToken, ' ') === '') {
            throw new ConfigurationException(
                "apiToken is required. Pass it explicitly, e.g. from getenv('HUURAY_API_TOKEN').",
            );
        }
        if ($apiSecret === '') {
            throw new ConfigurationException(
                "apiSecret is required. Pass it explicitly, e.g. from getenv('HUURAY_API_SECRET').",
            );
        }
        // Rejected, never trimmed: a line break in a header value injects headers,
        // or ends the header block so the signature is sent as body. The secret is
        // not checked because it is never sent. Neither message quotes the value.
        if (self::hasControlCharacter($apiToken)) {
            throw new ConfigurationException(
                'apiToken contains a control character (a line break, tab, NUL or similar) and cannot be sent as '
                . 'the X-API-TOKEN header. A value read from a file often ends in a newline; trim it first.',
            );
        }

        $this->apiToken = $apiToken;
        $this->apiSecret = $apiSecret;
        $this->baseUrl = self::validateBaseUrl($baseUrl);

        if (is_string($hashEncoding)) {
            $resolved = HashEncoding::tryFrom($hashEncoding);
            if ($resolved === null) {
                throw new ConfigurationException(sprintf(
                    'hashEncoding %s is not supported. Use one of: hex, hex-upper, base64, base64url.',
                    var_export($hashEncoding, true),
                ));
            }
            $hashEncoding = $resolved;
        }
        $this->hashEncoding = $hashEncoding;

        // Checked because the default transport reads 0 as "no timeout": a hung
        // order would then never fail, IndeterminateOrderException would never be
        // thrown, and the caller would get no signal to reconcile. The upper bound is
        // cURL's: where a C long is 32 bits (Windows), 2^32 wraps to 0 and 2^31 is refused.
        if ($timeoutMs < 1 || $timeoutMs > 2_147_483_647) {
            throw new ConfigurationException(sprintf(
                'timeoutMs must be between 1 and 2147483647, received %d. A request without a timeout can hang forever, '
                . 'and an order that never returns can never be reconciled.',
                $timeoutMs,
            ));
        }
        $this->timeoutMs = $timeoutMs;

        $this->retry = $retry ?? new RetryOptions();
        $this->transport = $transport ?? new CurlTransport();
        $this->userAgent = 'huuray-php/' . self::VERSION . ($userAgent !== null && $userAgent !== '' ? ' ' . $userAgent : '');
        if (self::hasControlCharacter($this->userAgent)) {
            throw new ConfigurationException(
                'userAgent contains a control character (a line break, tab, NUL or similar) and cannot be sent as '
                . 'the User-Agent header.',
            );
        }
        $this->nonceFactory = $nonceFactory !== null ? $nonceFactory(...) : Auth::generateNonce(...);

        $this->balances = new BalancesResource($this);
        $this->catalogue = new CatalogueResource($this);
        $this->templates = new TemplatesResource($this);
        $this->stock = new StockResource($this);
        $this->exchangeRates = new ExchangeRatesResource($this);
        $this->orders = new OrdersResource($this);
    }

    /**
     * Sends one gift card to one recipient — the common case, in a single call.
     *
     * Performs exactly one `POST /v4/Order` with `Sync: false` and `Quantity: 1`.
     * Delivery is handled by Huuray using the template you name, so no voucher
     * codes come back; use `orders->search()` to look the order up later.
     *
     * `refId` is required by this SDK even though the API treats it as optional:
     * without it there is no way to find out whether an order landed after a
     * timeout. See {@see IndeterminateOrderException}.
     *
     * @param int $value                Denomination **in minor units** — 50.00 is `5000`. Any float or bool is
     *                                  rejected.
     * @param string|null $pdfTemplateUid PDF template uid from `templates->list()->pdfTemplates`, attached as a
     *                                  document to the email. The template must be available for the ordered
     *                                  product's brand and country (null on the template means any); otherwise
     *                                  the API rejects the order with a 422, thrown as ValidationException. This
     *                                  client does not pre-check that.
     *
     * @throws \InvalidArgumentException  before any request, for an invalid argument
     * @throws IndeterminateOrderException when the outcome is unknown — do not retry
     * @throws HuurayException
     */
    public function sendReward(
        string $productToken,
        // Natively `mixed`, PHPDoc `int`: a caller without strict_types must not have
        // 50.00 or `true` coerced to an int before the guard sees it. See Wire::requireMinorUnits().
        mixed $value,
        string $currency,
        #[\SensitiveParameter]
        Recipient $recipient,
        int $templateId,
        string $refId,
        ?string $pdfTemplateUid = null,
        \DateTimeInterface|string|null $expires = null,
        \DateTimeInterface|string|null $deliveryDatetime = null,
        #[\SensitiveParameter]
        ?string $personalMessage = null,
    ): CreateOrderResult {
        return $this->orders->sendReward(
            productToken: $productToken,
            value: $value,
            currency: $currency,
            recipient: $recipient,
            templateId: $templateId,
            refId: $refId,
            pdfTemplateUid: $pdfTemplateUid,
            expires: $expires,
            deliveryDatetime: $deliveryDatetime,
            personalMessage: $personalMessage,
        );
    }

    /**
     * Calls any v4 endpoint with signing handled — the escape hatch for anything
     * the typed resources do not cover.
     *
     * Request and response shapes are exactly as documented in the Huuray API
     * reference; this method does no renaming. The decoded JSON comes back as
     * associative arrays.
     *
     *     $huuray->request('POST', '/v4/Search', ['RefID' => 'payroll-2026-08-jane']);
     *
     * `retryable` defaults to false and must be opted into per call. Never set it
     * on `/v4/Order`, `/v4/Resend` or `/v4/Cancel`.
     *
     * @param string                           $method An HTTP method token, such as GET, POST or DELETE.
     * @param string                           $path   Starting with "/", visible ASCII only, e.g. `/v4/Search`.
     * @param mixed                            $body   JSON request body. Null sends no body at all.
     * @param array<string, string|int|null>   $query  Query string parameters. Null values are dropped.
     *
     * @throws \InvalidArgumentException before any request, for a method that is not an HTTP token, a path that does
     *                                   not start with "/" or holds anything but visible ASCII, a body that cannot
     *                                   be encoded as JSON, a nonce that is empty, over 50 characters or outside
     *                                   visible ASCII, or a header with a line break or NUL
     * @throws HuurayException
     */
    public function request(
        string $method,
        string $path,
        #[\SensitiveParameter]
        mixed $body = null,
        #[\SensitiveParameter]
        array $query = [],
        bool $retryable = false,
    ): mixed {
        return $this->send($method, $path, $body, $query, $retryable)->data;
    }

    /**
     * Signs and sends one request, returning the decoded body and the HTTP status.
     *
     * Retries are **opt-in per operation** — never inferred from the HTTP method,
     * because four read-only v4 endpoints are POSTs and two value-moving ones are too.
     *
     * @internal Not part of the semver-stable surface; use {@see self::request()}.
     *
     * @param mixed                          $body  JSON request body. Null sends no body at all.
     * @param array<string, string|int|null> $query Query string parameters. Null values are dropped.
     *
     * @throws \InvalidArgumentException before any request, for a method that is not an HTTP token, a path that does
     *                                   not start with "/" or holds anything but visible ASCII, a body that cannot
     *                                   be encoded as JSON, a nonce that is empty, over 50 characters or outside
     *                                   visible ASCII, or a header with a line break or NUL
     * @throws HuurayException
     */
    public function send(
        string $method,
        string $path,
        #[\SensitiveParameter]
        mixed $body = null,
        #[\SensitiveParameter]
        array $query = [],
        bool $retryable = false,
    ): RawResponse {
        // The method goes into the request line as given, and the path is appended
        // to the base URL: a line break in either injects headers or smuggles a
        // second request carrying the credentials, and a path not starting with "/"
        // ("@host", ".host", ":port") moves the host. Checked outside the try below
        // for the same reason as the header backstop. Neither value is quoted.
        if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D', $method) !== 1) {
            throw new \InvalidArgumentException(
                'The request was not sent: the HTTP method must be a token such as GET, POST or DELETE.',
            );
        }
        if (preg_match('#^/[\x21-\x7E]*$#D', $path) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                '%s request was not sent: the path must start with "/" and contain only visible ASCII.',
                $method,
            ));
        }

        $url = $this->baseUrl . $path;
        $query = array_filter($query, static fn(mixed $value): bool => $value !== null);
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $payload = null;
        if ($body !== null) {
            try {
                $payload = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } catch (\JsonException $e) {
                // Not chained: the JsonException's trace holds json_encode's frame,
                // whose argument is the whole body, recipients included.
                throw new \InvalidArgumentException(sprintf(
                    '%s %s: the request body could not be encoded as JSON (%s). Strings must be valid UTF-8.',
                    $method,
                    $path,
                    $e->getMessage(),
                ));
            }
        }

        $attempts = $retryable ? $this->retry->maxRetries : 0;
        $lastError = null;

        for ($attempt = 0; $attempt <= $attempts; $attempt++) {
            if ($attempt > 0) {
                usleep(RetryPolicy::backoffDelayMs($attempt - 1, $this->retry) * 1000);
            }

            // A fresh nonce every attempt: the API rejects a repeat for 60 days.
            $headers = Auth::buildAuthHeaders($this->apiToken, $this->apiSecret, ($this->nonceFactory)(), $this->hashEncoding);
            $headers['Accept'] = 'application/json';
            $headers['User-Agent'] = $this->userAgent;
            if ($payload !== null) {
                $headers['Content-Type'] = 'application/json';
            }

            // Backstop behind the constructor and nonce checks. It sits before the
            // try below on purpose: a request refused here was never sent, so it
            // must not be mapped to ConnectionException or, for an order, to
            // IndeterminateOrderException. The offending value is never quoted.
            foreach ($headers as $name => $value) {
                if (strpbrk($name . $value, "\r\n\0") !== false) {
                    throw new \InvalidArgumentException(sprintf(
                        '%s %s was not sent: the %s header contains a line break or NUL byte.',
                        $method,
                        $path,
                        strpbrk($name, "\r\n\0") === false ? $name : 'name of a',
                    ));
                }
            }

            $request = new HttpRequest($method, $url, $headers, $payload, $this->timeoutMs);

            // The transport returns the body fully read, so a connection that drops
            // or times out while the body streams fails inside this same try. Every
            // failure maps through one taxonomy — anything escaping raw would bypass
            // the check that wraps order failures in IndeterminateOrderException.
            try {
                $response = $this->transport->send($request);
            } catch (\Throwable $cause) {
                $lastError = $cause instanceof TransportTimeoutException
                    ? new TimeoutException($method, $path, $this->timeoutMs, $cause)
                    : new ConnectionException(
                        sprintf('%s %s failed to reach the Huuray API: %s', $method, $path, $cause->getMessage()),
                        $method,
                        $path,
                        $cause,
                    );
                if ($attempt < $attempts) {
                    continue;
                }

                throw $lastError;
            }

            $text = $response->body;
            // Strip a UTF-8 byte order mark, as a text decoder would.
            if (str_starts_with($text, "\xEF\xBB\xBF")) {
                $text = substr($text, 3);
            }

            $parsed = null;
            $readable = false;
            if ($text !== '') {
                try {
                    $parsed = json_decode($text, true, 512, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
                    $readable = true;
                } catch (\JsonException) {
                    $readable = false;
                }
            }

            $status = $response->status;
            if ($status >= 200 && $status < 300) {
                // Every documented 2xx carries a JSON body. An empty or unparseable
                // body on a success status is a transport-level fault (proxy
                // interference, truncation) — NOT an empty result. Coercing it to an
                // empty result would make orders->search() report "order absent"
                // after a garbled response, and the documented reconciliation flow
                // would re-order. The body is never quoted: it could hold voucher codes.
                if (!$readable) {
                    $lastError = new ConnectionException(
                        sprintf(
                            '%s %s returned HTTP %d but the body was %s (%d bytes). '
                            . 'Treat the outcome as unknown rather than empty.',
                            $method,
                            $path,
                            $status,
                            $text === '' ? 'empty' : 'not valid JSON',
                            strlen($response->body),
                        ),
                        $method,
                        $path,
                    );
                    if ($attempt < $attempts) {
                        continue;
                    }

                    throw $lastError;
                }

                return new RawResponse($parsed, $status);
            }

            $lastError = ApiException::fromResponse($status, $readable ? $parsed : null, $method, $path);
            if ($attempt < $attempts && RetryPolicy::isRetryableStatus($status)) {
                continue;
            }

            throw $lastError;
        }

        // Unreachable: the loop always returns or throws.
        throw $lastError ?? new ConnectionException(sprintf('%s %s was never sent.', $method, $path), $method, $path);
    }

    /**
     * What `print_r()`, `var_dump()` and exception traces show for a client.
     *
     * Without this, dumping the client — or a trace through any function that takes
     * it as an argument — prints the API token and secret in clear text.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'apiToken' => '[redacted]',
            'apiSecret' => '[redacted]',
            'baseUrl' => $this->baseUrl,
            'hashEncoding' => $this->hashEncoding,
            'timeoutMs' => $this->timeoutMs,
            'retry' => $this->retry,
            'userAgent' => $this->userAgent,
        ];
    }

    /** Whether a header value holds an ASCII control character, 0x00-0x1F or 0x7F. */
    private static function hasControlCharacter(
        #[\SensitiveParameter]
        string $value,
    ): bool {
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }

    /**
     * Fails at construction, not at the first request. A base URL of "/v4" or
     * "api.huuray.com" (no scheme) would otherwise only surface later as a
     * confusing transport error — and requiring http(s) keeps credentials from
     * being aimed at a file:// or ftp:// target by a configuration typo.
     *
     * No message quotes the value, or any part of it: it could hold a password.
     */
    private static function validateBaseUrl(
        #[\SensitiveParameter]
        string $baseUrl,
    ): string {
        // A line break would reach the request line, and a NUL makes cURL throw with
        // the signed headers in its trace arguments.
        if (preg_match('/[^\x21-\x7E]/', $baseUrl) === 1) {
            throw new ConfigurationException(sprintf(
                'baseUrl contains a space, control character or non-ASCII byte. Expected something like %s.',
                var_export(self::DEFAULT_BASE_URL, true),
            ));
        }

        $trimmed = rtrim($baseUrl, '/');
        $parts = parse_url($trimmed);

        $scheme = is_array($parts) && isset($parts['scheme']) ? strtolower($parts['scheme']) : null;
        if ($scheme !== null && $scheme !== 'http' && $scheme !== 'https') {
            throw new ConfigurationException(sprintf(
                'baseUrl must use http or https. Expected something like %s.',
                var_export(self::DEFAULT_BASE_URL, true),
            ));
        }

        if ($scheme === null || !is_array($parts) || !isset($parts['host']) || $parts['host'] === '') {
            throw new ConfigurationException(sprintf(
                'baseUrl is not an absolute http(s) URL. Expected something like %s.',
                var_export(self::DEFAULT_BASE_URL, true),
            ));
        }

        // cURL sends user-info to the host as Basic credentials, and an error
        // message built from the URL would print the password.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ConfigurationException(sprintf(
                'baseUrl must not contain user-info ("user@" or "user:password@"). The client authenticates with '
                . 'apiToken and apiSecret. Expected something like %s.',
                var_export(self::DEFAULT_BASE_URL, true),
            ));
        }

        // Every request path is appended to the base URL, so after a "?" or "#" it
        // would land in the query or fragment and every request would go to the wrong path.
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new ConfigurationException(sprintf(
                'baseUrl must not contain a %s: request paths are appended to it, so every request would go to '
                . 'the wrong path. Expected something like %s.',
                isset($parts['query']) ? 'query ("?")' : 'fragment ("#")',
                var_export(self::DEFAULT_BASE_URL, true),
            ));
        }

        return $trimmed;
    }
}
