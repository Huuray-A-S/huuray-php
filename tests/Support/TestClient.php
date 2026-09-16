<?php

declare(strict_types=1);

namespace Huuray\Tests\Support;

use Huuray\HashEncoding;
use Huuray\HuurayClient;
use Huuray\Resources\AbstractResource;
use Huuray\RetryOptions;

/** A client wired to a {@see FakeTransport}, with throwaway credentials. */
final class TestClient
{
    public const TOKEN = 'test-token';
    public const SECRET = 'test-secret';

    private function __construct() {}

    /**
     * Retries are off unless a test asks for them, so a failing read is one call.
     *
     * @param MockResponse|list<MockResponse>|null $responses
     * @param (callable(): string)|null            $nonceFactory
     *
     * @return array{HuurayClient, FakeTransport}
     */
    public static function make(
        MockResponse|array|null $responses = null,
        ?RetryOptions $retry = null,
        string $baseUrl = HuurayClient::DEFAULT_BASE_URL,
        HashEncoding|string $hashEncoding = HashEncoding::Hex,
        int $timeoutMs = HuurayClient::DEFAULT_TIMEOUT_MS,
        ?string $userAgent = null,
        ?callable $nonceFactory = null,
    ): array {
        $transport = new FakeTransport($responses);
        $client = new HuurayClient(
            apiToken: self::TOKEN,
            apiSecret: self::SECRET,
            baseUrl: $baseUrl,
            hashEncoding: $hashEncoding,
            timeoutMs: $timeoutMs,
            retry: $retry ?? new RetryOptions(maxRetries: 0),
            transport: $transport,
            userAgent: $userAgent,
            nonceFactory: $nonceFactory,
        );

        return [$client, $transport];
    }

    /**
     * A client holding an API token the constructor would refuse, built through
     * reflection — the only way to reach the header backstop in `send()`. Every
     * resource is rebound to the new client, so `$client->orders` really sends
     * through it and not through the valid client its properties were copied from.
     *
     * @param MockResponse|list<MockResponse>|null $responses
     *
     * @return array{HuurayClient, FakeTransport}
     */
    public static function withUncheckedApiToken(
        #[\SensitiveParameter]
        string $apiToken,
        MockResponse|array|null $responses = null,
    ): array {
        [$valid, $transport] = self::make($responses);
        $class = new \ReflectionClass(HuurayClient::class);
        $client = $class->newInstanceWithoutConstructor();
        foreach ($class->getProperties() as $property) {
            $type = $property->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : '';
            $property->setValue($client, match (true) {
                $property->getName() === 'apiToken' => $apiToken,
                is_subclass_of($typeName, AbstractResource::class) => new $typeName($client),
                default => $property->getValue($valid),
            });
        }

        return [$client, $transport];
    }
}
