<?php

declare(strict_types=1);

namespace Huuray\Exception;

/**
 * The request never completed — a network failure, DNS, TLS, or a timeout — or
 * a 2xx response arrived whose body was empty or not valid JSON.
 *
 * An unreadable success body is treated as a transport fault rather than an
 * empty result: a garbled `/v4/Search` response must never read as "the order
 * did not land". The body is never quoted in the message; it could hold a
 * voucher code.
 */
class ConnectionException extends HuurayException
{
    public function __construct(
        string $message,
        public readonly string $method,
        public readonly string $path,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
