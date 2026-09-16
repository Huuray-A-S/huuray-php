<?php

declare(strict_types=1);

namespace Huuray;

/**
 * Request signing.
 *
 * Every v4 request carries three headers:
 *
 *   X-API-TOKEN   your API token
 *   X-API-NONCE   a random value, single-use within 60 days, max 50 characters
 *   X-API-HASH    SHA-512 of ( API-SECRET . NONCE )
 *
 * The client builds all three for you. This class is public so the construction
 * can be inspected and tested, not because you need to call it.
 */
final class Auth
{
    /**
     * Default digest encoding: lowercase hex.
     *
     * Confirmed against the live API on 2026-08-15: lowercase hex authenticated
     * against GET /v4/Balance, and the other three candidate encodings returned
     * 401. Changing this default breaks every consumer unless the API changed first.
     */
    public const DEFAULT_HASH_ENCODING = HashEncoding::Hex;

    /**
     * The specification's stated maximum length of `X-API-NONCE`. A too-long
     * nonce is an easy mistake: 32 random bytes encoded as hex is 64 characters,
     * silently over the limit.
     */
    public const NONCE_MAX_LENGTH = 50;

    /** Bytes of entropy per generated nonce. 24 bytes -> 32 base64url characters. */
    private const NONCE_BYTES = 24;

    private function __construct() {}

    /**
     * Generates a nonce: 24 cryptographically random bytes as base64url, 32 characters.
     *
     * The API stores nonces for 60 days and rejects a repeat, so the only thing
     * that matters is that values never collide. 192 bits of entropy makes that
     * negligible at any realistic volume, and 32 characters leaves headroom under
     * the 50-character cap.
     *
     * Avoid timestamps: at second resolution they collide under concurrency, and
     * the resulting 401s are intermittent and hard to trace.
     */
    public static function generateNonce(): string
    {
        return self::base64Url(random_bytes(self::NONCE_BYTES));
    }

    /**
     * Computes the `X-API-HASH` value for a given secret and nonce.
     *
     * @param string $apiSecret Your API secret. Never logged by this library.
     * @param string $nonce     The same nonce sent in `X-API-NONCE`.
     */
    public static function signRequest(
        #[\SensitiveParameter]
        string $apiSecret,
        string $nonce,
        HashEncoding $encoding = self::DEFAULT_HASH_ENCODING,
    ): string {
        $digest = hash('sha512', $apiSecret . $nonce, true);

        return match ($encoding) {
            HashEncoding::Hex => bin2hex($digest),
            HashEncoding::HexUpper => strtoupper(bin2hex($digest)),
            HashEncoding::Base64 => base64_encode($digest),
            HashEncoding::Base64Url => self::base64Url($digest),
        };
    }

    /**
     * The three auth headers for one request.
     *
     * @throws \InvalidArgumentException when the nonce is empty, exceeds the API's 50-character limit, or holds
     *                                   anything but visible ASCII (0x21-0x7E)
     *
     * @return array{'X-API-TOKEN': string, 'X-API-NONCE': string, 'X-API-HASH': string}
     */
    public static function buildAuthHeaders(
        #[\SensitiveParameter]
        string $apiToken,
        #[\SensitiveParameter]
        string $apiSecret,
        string $nonce,
        HashEncoding $hashEncoding = self::DEFAULT_HASH_ENCODING,
    ): array {
        if (strlen($nonce) > self::NONCE_MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'Nonce is %d characters; the Huuray API accepts at most %d. '
                . 'If you supplied a custom nonceFactory, shorten its output.',
                strlen($nonce),
                self::NONCE_MAX_LENGTH,
            ));
        }
        // A line break would end the header or inject another one, and HTTP stacks
        // do not reliably preserve spaces or non-ASCII bytes in a header value —
        // while the hash covers these exact bytes. An empty value makes cURL drop the
        // header, which the specification requires. The value is never quoted.
        if ($nonce === '' || preg_match('/[^\x21-\x7E]/', $nonce) === 1) {
            throw new \InvalidArgumentException(
                'Nonce is empty or contains a character outside visible ASCII (0x21-0x7E), such as a space, a line '
                . 'break or a non-ASCII byte. If you supplied a custom nonceFactory, make it return visible ASCII only.',
            );
        }

        return [
            'X-API-TOKEN' => $apiToken,
            'X-API-NONCE' => $nonce,
            'X-API-HASH' => self::signRequest($apiSecret, $nonce, $hashEncoding),
        ];
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
