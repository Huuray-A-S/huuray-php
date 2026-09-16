<?php

declare(strict_types=1);

namespace Huuray\Http;

/**
 * Thrown by a {@see Transport} when the exchange could not be completed.
 *
 * The client never lets this escape: it is mapped to
 * {@see \Huuray\Exception\ConnectionException}, or, for an order, to
 * {@see \Huuray\Exception\IndeterminateOrderException}.
 */
class TransportException extends \RuntimeException {}
