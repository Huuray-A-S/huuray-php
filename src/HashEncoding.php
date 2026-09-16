<?php

declare(strict_types=1);

namespace Huuray;

/**
 * How the SHA-512 digest is encoded into the `X-API-HASH` header.
 *
 * The v4 specification states the construction, SHA512(API_SECRET . NONCE), but
 * not the encoding of the digest. Lowercase hex is the default, and is the
 * encoding confirmed against the live API. If you get a 401 with credentials you
 * know are correct, this is the first thing to try.
 */
enum HashEncoding: string
{
    case Hex = 'hex';
    case HexUpper = 'hex-upper';
    case Base64 = 'base64';
    case Base64Url = 'base64url';
}
