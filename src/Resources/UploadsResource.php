<?php

declare(strict_types=1);

namespace Huuray\Resources;

use Huuray\Exception\ConnectionException;
use Huuray\Exception\HuurayException;
use Huuray\Exception\TimeoutException;
use Huuray\Exception\ValidationException;
use Huuray\Internal\MultipartBody;
use Huuray\Internal\Wire;
use Huuray\Result\UploadResult;

/** Uploading a purchase order file, to attach to the invoice of an order. */
class UploadsResource extends AbstractResource
{
    /** Appended to a timeout or connection failure: the caller never learns the token of an upload that landed. */
    private const MAY_HAVE_BEEN_STORED = 'The upload may still have been stored, and may hold one of the account\'s '
        . 'pending upload slots until it is used or cleaned up. It was not retried.';

    /**
     * Uploads one file and returns the token that attaches it to an order.
     *
     * `POST /v4/Upload`, as multipart/form-data with one part named `File`.
     *
     * Pass `token` from the result as `purchaseOrderFileToken` to `orders->create()`,
     * `orders->createSync()` or `sendReward()`. The token is consumed by the order it
     * is used with.
     *
     * **Never retried.** Every upload is stored as a new pending upload, and the API
     * reference allows at most five per account. A timeout or a dropped connection
     * throws the ordinary TimeoutException or ConnectionException, saying the upload
     * may still have been stored — never IndeterminateOrderException, as there is no
     * way to look an upload up.
     *
     * The API reference says a file must be a PDF or an image of at most 10 MB, and
     * that upload must be enabled for your account; the API rejects anything else.
     * This client checks neither the size nor the type.
     *
     * @param string|resource $file        The file's bytes, or a readable stream, read from its current position to
     *                                     the end. The stream is neither rewound nor closed.
     * @param string          $fileName    The file's name, e.g. `purchase-order-4711.pdf`.
     * @param string|null     $contentType The file's media type, e.g. `application/pdf`. Sent as
     *                                     `application/octet-stream` when null.
     *
     * @throws \InvalidArgumentException before any request, for a `file` that is neither a string nor a readable
     *                                   stream, a `contentType` holding a line break or NUL, or a custom nonce that
     *                                   is empty, over 50 characters or outside visible ASCII
     * @throws TimeoutException          when the upload timed out; it may still have been stored
     * @throws ConnectionException       when the upload did not complete; it may still have been stored
     * @throws ValidationException       when the API rejects the upload
     * @throws HuurayException
     */
    public function create(
        #[\SensitiveParameter]
        mixed $file,
        #[\SensitiveParameter]
        string $fileName,
        ?string $contentType = null,
    ): UploadResult {
        // Written into the part's headers, so a line break would inject one. Not quoted.
        if ($contentType !== null && strpbrk($contentType, "\r\n\0") !== false) {
            throw new \InvalidArgumentException('POST /v4/Upload was not sent: contentType contains a line break or NUL byte.');
        }
        $body = MultipartBody::withFile('File', $fileName, $contentType ?? 'application/octet-stream', self::read($file));

        try {
            $data = $this->client->send('POST', '/v4/Upload', $body, retryable: false)->data;
        } catch (TimeoutException $e) {
            throw new TimeoutException($e->method, $e->path, $e->timeoutMs, $e->getPrevious(), self::MAY_HAVE_BEEN_STORED);
        } catch (ConnectionException $e) {
            throw new ConnectionException($e->getMessage() . ' ' . self::MAY_HAVE_BEEN_STORED, $e->method, $e->path, $e->getPrevious());
        }

        return new UploadResult(
            token: Wire::string($data, 'Token'),
            fileName: Wire::string($data, 'FileName'),
            contentType: Wire::string($data, 'ContentType'),
            size: Wire::int($data, 'Size'),
        );
    }

    /**
     * The bytes to upload: a string as given, or a readable stream from its current position to the end.
     *
     * @throws \InvalidArgumentException
     */
    private static function read(
        #[\SensitiveParameter]
        mixed $file,
    ): string {
        if (is_string($file)) {
            return $file;
        }

        $stream = is_resource($file) && get_resource_type($file) === 'stream';
        if ($stream && strpbrk(stream_get_meta_data($file)['mode'], 'r+') !== false) {
            $bytes = stream_get_contents($file);
            if ($bytes !== false) {
                return $bytes;
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'POST /v4/Upload was not sent: file must be the file\'s bytes as a string, or a readable stream; received %s.',
            $stream ? 'a stream that cannot be read' : get_debug_type($file),
        ));
    }
}
