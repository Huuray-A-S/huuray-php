<?php

declare(strict_types=1);

namespace Huuray\Exception;

use Huuray\Internal\Wire;
use Huuray\Redact;

/**
 * The API returned a non-2xx response.
 *
 * The API returns `Status` and `StatusMessage` in the body alongside the HTTP
 * status. `Message` carries the same text but is marked deprecated in the
 * specification, so this client reads `StatusMessage` first and falls back to
 * `Message`.
 */
class ApiException extends HuurayException
{
    public function __construct(
        string $message,
        /** HTTP status of the response. Also available as getCode(). */
        public readonly int $httpStatus,
        /** The `Status` field from the response body, when present. */
        public readonly ?int $status,
        /** The `StatusMessage` field, or the deprecated `Message` as fallback. */
        public readonly ?string $statusMessage,
        /**
         * The decoded response body, if it was JSON — **redacted**: any field that
         * could carry a voucher code or contact detail is masked, so logging an
         * exception never leaks a bearer instrument.
         */
        public readonly mixed $body,
        public readonly string $method,
        public readonly string $path,
    ) {
        parent::__construct($message, $httpStatus);
    }

    /**
     * Builds the most specific exception for an HTTP status.
     *
     * @internal
     */
    public static function fromResponse(
        int $httpStatus,
        #[\SensitiveParameter]
        mixed $body,
        string $method,
        string $path,
    ): self {
        $statusMessage = Wire::string($body, 'StatusMessage') ?? Wire::string($body, 'Message');
        $status = Wire::int($body, 'Status');
        $detail = $statusMessage !== null && $statusMessage !== '' ? ' — ' . $statusMessage : '';
        $message = sprintf('%s %s failed with HTTP %d%s', $method, $path, $httpStatus, $detail);

        // The raw body is dropped here: only the redacted copy is retained, so an
        // undocumented error payload carrying voucher or recipient fields cannot
        // ride into a consumer's logs via the exception.
        $redacted = Redact::redact($body);

        if ($httpStatus === 401 || $httpStatus === 403) {
            return new AuthException($message, $httpStatus, $status, $statusMessage, $redacted, $method, $path);
        }
        if ($httpStatus === 404) {
            return new NotFoundException($message, $httpStatus, $status, $statusMessage, $redacted, $method, $path);
        }
        if ($httpStatus === 422) {
            return new ValidationException($message, $httpStatus, $status, $statusMessage, $redacted, $method, $path);
        }
        if ($httpStatus >= 500) {
            return new ServerException($message, $httpStatus, $status, $statusMessage, $redacted, $method, $path);
        }

        return new ApiException($message, $httpStatus, $status, $statusMessage, $redacted, $method, $path);
    }
}
