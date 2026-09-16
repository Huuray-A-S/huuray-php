<?php

declare(strict_types=1);

namespace Huuray\Exception;

/**
 * 401 or 403.
 *
 * With credentials you believe are correct, the usual causes are, in order: a
 * wrong `X-API-HASH` encoding (see the client's `hashEncoding`), a reused nonce
 * (the API remembers them for 60 days), or a nonce over 50 characters.
 */
class AuthException extends ApiException {}
