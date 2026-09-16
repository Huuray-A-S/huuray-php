<?php

declare(strict_types=1);

namespace Huuray\Http;

/**
 * Thrown by a {@see Transport} when the request's timeout elapsed.
 *
 * Mapped by the client to {@see \Huuray\Exception\TimeoutException}.
 */
class TransportTimeoutException extends TransportException {}
