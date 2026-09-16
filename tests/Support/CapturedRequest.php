<?php

declare(strict_types=1);

namespace Huuray\Tests\Support;

use Huuray\Http\HttpRequest;

/** One request the SDK made, as {@see FakeTransport} saw it. */
final readonly class CapturedRequest
{
    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     * @param mixed                 $body         The JSON body decoded to associative arrays, or null when none was sent.
     * @param list<mixed>           $sdkArguments The arguments `$sdkMethod` was called with, in declaration order.
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
    ) {}

    /** @param list<mixed> $sdkArguments */
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

        return new self(
            method: $request->method,
            url: $request->url,
            origin: $origin,
            path: $parts['path'] ?? '',
            query: $query,
            headers: $request->headers,
            rawBody: $request->body,
            body: $request->body === null ? null : json_decode($request->body, true, 512, JSON_THROW_ON_ERROR),
            bodyOmitted: $request->body === null,
            timeoutMs: $request->timeoutMs,
            sdkMethod: $sdkMethod,
            sdkArguments: $sdkArguments,
        );
    }

    /** The body decoded with JSON objects as `stdClass`, so objects and lists stay distinguishable. */
    public function bodyAsObjects(): mixed
    {
        return $this->rawBody === null ? null : json_decode($this->rawBody, false, 512, JSON_THROW_ON_ERROR);
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
}
