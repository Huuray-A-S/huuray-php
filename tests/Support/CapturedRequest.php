<?php

declare(strict_types=1);

namespace Huuray\Tests\Support;

use Huuray\Http\HttpRequest;

/** One request the SDK made, as {@see FakeTransport} saw it. */
final readonly class CapturedRequest
{
    /**
     * @param array<string, string>    $query
     * @param array<string, string>    $headers
     * @param mixed                    $body         The JSON body decoded to associative arrays; null when none was
     *                                               sent, when the body is not labelled application/json, or when it
     *                                               does not decode.
     * @param list<mixed>              $sdkArguments The arguments `$sdkMethod` was called with, in declaration order.
     * @param list<MultipartPart>|null $parts        The parts of a multipart/form-data body; null for any other body,
     *                                               and for one that could not be parsed.
     */
    public function __construct(
        public string $method,
        /** Full URL as requested, e.g. `https://api.huuray.com/v4/ExchangeRates?FromCurrency=EUR`. */
        public string $url,
        /** Scheme and host only, e.g. `https://api.huuray.com` — for pinning the base URL. */
        public string $origin,
        /** Path only, e.g. `/v4/Order`. */
        public string $path,
        public array $query,
        public array $headers,
        /** The body exactly as sent, or null when no body was sent. */
        public ?string $rawBody,
        public mixed $body,
        /** True when no body was sent at all — distinct from an empty object. */
        public bool $bodyOmitted,
        public int $timeoutMs,
        /**
         * The public SDK method the caller entered through to make this request, e.g.
         * `OrdersResource::create` or `HuurayClient::sendReward` — the outermost one on
         * the stack, so `sendReward` delegating to `create` is attributed to `sendReward`.
         * Null when no public SDK method was on the stack.
         */
        public ?string $sdkMethod = null,
        public array $sdkArguments = [],
        public ?array $parts = null,
        /** Why a multipart/form-data body could not be parsed; null when it was, or was not multipart. */
        public ?string $multipartError = null,
    ) {}

    /**
     * Records a request without ever throwing on its body: a body the gates cannot
     * read must fail them as an assertion, not crash the harness that feeds them.
     * Only a body labelled application/json is decoded as JSON — a multipart body
     * is split into its parts and never JSON-parsed.
     *
     * @param list<mixed> $sdkArguments
     */
    public static function from(HttpRequest $request, ?string $sdkMethod = null, array $sdkArguments = []): self
    {
        $parts = parse_url($request->url);
        if (!is_array($parts)) {
            throw new \LogicException('The SDK built an unparseable URL: ' . $request->url);
        }

        $origin = ($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');

        $query = [];
        parse_str($parts['query'] ?? '', $parsedQuery);
        foreach ($parsedQuery as $key => $value) {
            if (is_string($value)) {
                $query[(string) $key] = $value;
            }
        }

        $contentType = self::header($request->headers, 'Content-Type');
        $mediaType = self::mediaTypeOf($contentType);
        $body = null;
        $multipart = null;
        $multipartError = null;
        if ($request->body !== null && $mediaType === 'application/json') {
            $body = json_decode($request->body, true, 512);
        } elseif ($request->body !== null && $mediaType === 'multipart/form-data') {
            try {
                $multipart = MultipartPart::parseAll((string) $contentType, $request->body);
            } catch (\UnexpectedValueException $e) {
                $multipartError = $e->getMessage();
            }
        }

        return new self(
            method: $request->method,
            url: $request->url,
            origin: $origin,
            path: $parts['path'] ?? '',
            query: $query,
            headers: $request->headers,
            rawBody: $request->body,
            body: $body,
            bodyOmitted: $request->body === null,
            timeoutMs: $request->timeoutMs,
            sdkMethod: $sdkMethod,
            sdkArguments: $sdkArguments,
            parts: $multipart,
            multipartError: $multipartError,
        );
    }

    /** The media type of the Content-Type header, lower-cased and without parameters; null when none was sent. */
    public function mediaType(): ?string
    {
        return self::mediaTypeOf(self::header($this->headers, 'Content-Type'));
    }

    /**
     * The body decoded with JSON objects as `stdClass`, so objects and lists stay
     * distinguishable. Null when no body was sent, when it is not labelled
     * application/json, or when it does not decode.
     */
    public function bodyAsObjects(): mixed
    {
        return $this->rawBody === null || $this->mediaType() !== 'application/json'
            ? null
            : json_decode($this->rawBody, false, 512);
    }

    /** A nested value from the decoded body, e.g. `field('Product', 'Value')`. Null when absent. */
    public function field(string ...$keys): mixed
    {
        $value = $this->body;
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /** Whether the decoded body has a top-level key. */
    public function hasField(string $key): bool
    {
        return is_array($this->body) && array_key_exists($key, $this->body);
    }

    /** @param array<string, string> $headers */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    private static function mediaTypeOf(?string $contentType): ?string
    {
        return $contentType === null ? null : strtolower(trim(explode(';', $contentType, 2)[0]));
    }
}
