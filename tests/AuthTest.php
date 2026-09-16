<?php

declare(strict_types=1);

namespace Huuray\Tests;

use Huuray\Auth;
use Huuray\HashEncoding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase
{
    // ---------------------------------------------------------------- nonces

    public function testNoncesStayWithinThe50CharacterLimitTheApiEnforces(): void
    {
        for ($i = 0; $i < 1000; $i++) {
            self::assertLessThanOrEqual(Auth::NONCE_MAX_LENGTH, strlen(Auth::generateNonce()));
        }
    }

    public function testANonceIs32Base64UrlCharacters(): void
    {
        $nonce = Auth::generateNonce();

        self::assertSame(32, strlen($nonce));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $nonce);
    }

    public function testNoncesDoNotRepeatTheApiRejectsAReusedNonceFor60Days(): void
    {
        $seen = [];
        for ($i = 0; $i < 100_000; $i++) {
            $seen[Auth::generateNonce()] = true;
        }

        self::assertCount(100_000, $seen);
    }

    public function testRejectsACustomNonceThatWouldExceedTheApiLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at most 50/');

        Auth::buildAuthHeaders('t', 's', str_repeat('x', 51));
    }

    public function testAcceptsANonceExactlyAtTheLimit(): void
    {
        $headers = Auth::buildAuthHeaders('t', 's', str_repeat('x', 50));

        self::assertSame(str_repeat('x', 50), $headers['X-API-NONCE']);
    }

    public function testRejects32ByteHexTheClassicOverLimitMistake(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Auth::buildAuthHeaders('t', 's', bin2hex(random_bytes(32)));
    }

    /** @return iterable<string, array{string}> */
    public static function noncesOutsideVisibleAscii(): iterable
    {
        yield 'line feed' => ["abc\ndef"];
        yield 'CRLF injecting a header' => ["abc\r\nX-Injected: yes"];
        yield 'space' => ['abc def'];
        yield 'tab' => ["abc\tdef"];
        yield 'NUL' => ["abc\0def"];
        yield 'DEL' => ["abc\x7Fdef"];
        yield 'non-ASCII' => ["abc\u{E9}def"];
        yield 'empty' => [''];
    }

    #[DataProvider('noncesOutsideVisibleAscii')]
    public function testRejectsACustomNonceOutsideVisibleAscii(string $nonce): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/outside visible ASCII/');

        Auth::buildAuthHeaders('t', 's', $nonce);
    }

    public function testAcceptsEveryVisibleAsciiCharacterInANonce(): void
    {
        $visible = implode('', array_map(chr(...), range(0x21, 0x7E)));

        foreach (str_split($visible, Auth::NONCE_MAX_LENGTH) as $nonce) {
            self::assertSame($nonce, Auth::buildAuthHeaders('t', 's', $nonce)['X-API-NONCE']);
        }
    }

    // --------------------------------------------------------------- signing

    public function testSigningIsSha512OverApiSecretThenNonceInThatOrder(): void
    {
        // Computed independently here rather than copied from the implementation,
        // so this fails if the construction changes.
        self::assertSame(hash('sha512', 'sec' . 'non'), Auth::signRequest('sec', 'non'));
    }

    public function testSigningIsOrderSensitive(): void
    {
        self::assertNotSame(Auth::signRequest('ab', 'cd'), Auth::signRequest('cd', 'ab'));
    }

    public function testDefaultsToLowercaseHex(): void
    {
        self::assertSame(HashEncoding::Hex, Auth::DEFAULT_HASH_ENCODING);
        self::assertMatchesRegularExpression('/^[0-9a-f]{128}$/', Auth::signRequest('sec', 'non'));
    }

    /** @return iterable<string, array{HashEncoding, string}> */
    public static function encodings(): iterable
    {
        yield 'hex' => [HashEncoding::Hex, '/^[0-9a-f]{128}$/'];
        yield 'hex-upper' => [HashEncoding::HexUpper, '/^[0-9A-F]{128}$/'];
        yield 'base64' => [HashEncoding::Base64, '#^[A-Za-z0-9+/]+=*$#'];
        yield 'base64url' => [HashEncoding::Base64Url, '/^[A-Za-z0-9_-]+$/'];
    }

    #[DataProvider('encodings')]
    public function testSupportsEveryDocumentedEncoding(HashEncoding $encoding, string $pattern): void
    {
        self::assertMatchesRegularExpression($pattern, Auth::signRequest('sec', 'non', $encoding));
    }

    public function testEveryEncodingCarriesTheSameDigest(): void
    {
        $raw = hash('sha512', 'secnon', true);

        self::assertSame(bin2hex($raw), Auth::signRequest('sec', 'non', HashEncoding::Hex));
        self::assertSame(strtoupper(bin2hex($raw)), Auth::signRequest('sec', 'non', HashEncoding::HexUpper));
        self::assertSame(base64_encode($raw), Auth::signRequest('sec', 'non', HashEncoding::Base64));
        self::assertSame(
            rtrim(strtr(base64_encode($raw), '+/', '-_'), '='),
            Auth::signRequest('sec', 'non', HashEncoding::Base64Url),
        );
    }

    public function testUsesTheEncodingConfirmedAgainstTheLiveApi(): void
    {
        // The v4 specification states the construction, SHA512(API_SECRET + NONCE),
        // but not the digest encoding. Confirmed live on 2026-08-15: lowercase hex
        // authenticated against GET /v4/Balance on api.huuray.com (the other three
        // candidate encodings returned 401). If this test fails, someone changed
        // the default — that breaks every consumer unless the API changed first.
        self::assertSame('hex', Auth::DEFAULT_HASH_ENCODING->value);
    }

    // ---------------------------------------------------------------- headers

    public function testSendsExactlyTheThreeDocumentedHeaders(): void
    {
        $headers = Auth::buildAuthHeaders('tok', 'sec', 'abc');
        $names = array_keys($headers);
        sort($names);

        self::assertSame(['X-API-HASH', 'X-API-NONCE', 'X-API-TOKEN'], $names);
        self::assertSame('tok', $headers['X-API-TOKEN']);
        self::assertSame('abc', $headers['X-API-NONCE']);
    }

    public function testNeverPutsTheSecretInAHeader(): void
    {
        $headers = Auth::buildAuthHeaders('tok', 'super-secret', 'abc');

        self::assertStringNotContainsString('super-secret', implode("\n", $headers));
    }
}
