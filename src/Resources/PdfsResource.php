<?php

declare(strict_types=1);

namespace Huuray\Resources;

use Huuray\Exception\ConnectionException;
use Huuray\Exception\HuurayException;
use Huuray\Exception\NotFoundException;
use Huuray\Exception\TimeoutException;
use Huuray\Exception\ValidationException;
use Huuray\HuurayClient;
use Huuray\Internal\Sleep;
use Huuray\Internal\Wire;
use Huuray\Result\PdfDocument;
use Huuray\Result\PdfResult;

/**
 * Fetching the gift card PDFs of an order.
 *
 * A PDF holds the redeemable code, so it is a **bearer instrument**: never log it,
 * and keep it only as long as you need it. See {@see PdfDocument}.
 */
class PdfsResource extends AbstractResource
{
    /** How long getWhenReady() keeps asking by default: 10 minutes, as in Huuray's reference implementation. */
    public const DEFAULT_MAX_WAIT_MS = 600_000;

    /** The wait after a 202 without a usable `Retry-After`, in seconds: the interval the API currently names. */
    public const DEFAULT_RETRY_AFTER_SECONDS = 30;

    /** The shortest wait between two asks, in seconds, whatever `Retry-After` says. */
    public const MIN_WAIT_SECONDS = 1;

    /** The standard base64 alphabet, RFC 4648 section 4. */
    private const BASE64_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

    /** @var \Closure(int): void */
    private readonly \Closure $sleep;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(int): void)|null $sleep Waits the given whole seconds. For the test suite; defaults to sleep(),
     *                                          a day at a time.
     * @param (\Closure(): float)|null   $clock Monotonic time in seconds, for the maxWaitMs deadline. For the test
     *                                          suite; defaults to hrtime().
     */
    public function __construct(HuurayClient $client, ?\Closure $sleep = null, ?\Closure $clock = null)
    {
        parent::__construct($client);
        // A day at a time: one sleep() call wraps a long enough wait round to a short one.
        $this->sleep = $sleep ?? Sleep::seconds(...);
        $this->clock = $clock ?? static fn(): float => hrtime(true) / 1e9;
    }

    /**
     * Fetches the gift card PDFs of an order, or learns that they are not ready yet.
     *
     * `POST /v4/Pdf`. A read: it never changes the order or Huuray's own delivery of
     * it, so it is safe to repeat, and it is retried like the other reads.
     *
     * Check `ready`. On HTTP 200 it is true, and `documents` holds one PDF per
     * voucher, or one combined PDF. On HTTP 202 — the order is still in Huuray's
     * queue, or a supplier has not delivered a code yet — it is false, `documents` is
     * empty, and `retryAfter` says how many seconds to wait before asking again.
     * `getWhenReady()` does that waiting for you.
     *
     * The API token needs the Search permission. The API reference says only orders
     * with at most three receivers are supported, and rejects larger ones with a
     * 422. This client checks neither that nor any argument: the API decides.
     *
     * A PDF can take a while to render and run to several megabytes. Huuray suggests
     * allowing 100 seconds, so give the client a `timeoutMs` of `100_000` for these calls.
     *
     * @param string      $orderUid       The order: `orderUid` from `orders->create()` or `orders->search()`.
     * @param int|null    $voucherId      One voucher of the order. Omit it for all of them.
     * @param string|null $pdfTemplateUid A uid from `templates->list()->pdfTemplates` to render the PDF with. Omit it
     *                                    for the document attached to the order's delivery email.
     * @param bool|null   $combine        True for one PDF holding every selected voucher. Omitted, the API makes
     *                                    one PDF per voucher.
     *
     * @throws NotFoundException         for an order or voucher that does not exist or is cancelled, or a PDF
     *                                   template that does not exist
     * @throws ValidationException       for an order with more than three receivers, or a PDF template that cannot
     *                                   be used for it or is needed and missing
     * @throws ConnectionException       when the request did not complete, or a document's content is not valid
     *                                   base64; the message never quotes the content
     * @throws \InvalidArgumentException before any request, for a custom nonce that is empty, over 50 characters or
     *                                   outside visible ASCII, or a string that is not valid UTF-8
     * @throws HuurayException
     */
    public function get(
        string $orderUid,
        ?int $voucherId = null,
        ?string $pdfTemplateUid = null,
        ?bool $combine = null,
    ): PdfResult {
        return $this->fetch($orderUid, $voucherId, $pdfTemplateUid, $combine)[0];
    }

    /**
     * Fetches the gift card PDFs of an order, waiting while the API says they are not ready.
     *
     * Calls `get()` and returns its result as soon as `ready` is true. Until then it
     * waits the `retryAfter` seconds the API asked for — 30 when it named none, and
     * never less than 1 — and asks again, each time as a newly signed request. It
     * gives up rather than let the next wait pass `maxWaitMs`, and throws
     * TimeoutException quoting the API's last status message; the call is read-only,
     * so asking again later is safe.
     *
     * Only "not ready" is waited for. Any error is thrown at once, as from `get()`.
     *
     * @param int $maxWaitMs How long to keep asking, in milliseconds; 10 minutes by default. It bounds the waits
     *                       between requests: each request still has the client's `timeoutMs`.
     *
     * @throws TimeoutException          when the PDFs are still not ready and the next wait would pass `maxWaitMs`;
     *                                   its `timeoutMs` is `maxWaitMs`
     * @throws NotFoundException         as from `get()`
     * @throws ValidationException       as from `get()`
     * @throws ConnectionException       as from `get()`
     * @throws \InvalidArgumentException as from `get()`
     * @throws HuurayException
     */
    public function getWhenReady(
        string $orderUid,
        ?int $voucherId = null,
        ?string $pdfTemplateUid = null,
        ?bool $combine = null,
        int $maxWaitMs = self::DEFAULT_MAX_WAIT_MS,
    ): PdfResult {
        $deadline = ($this->clock)() + $maxWaitMs / 1000;

        while (true) {
            [$result, $statusMessage] = $this->fetch($orderUid, $voucherId, $pdfTemplateUid, $combine);
            if ($result->ready) {
                return $result;
            }

            // At least a second, so a `Retry-After: 0` from a server or proxy never
            // sets off back-to-back signed requests.
            $wait = max(self::MIN_WAIT_SECONDS, $result->retryAfter ?? self::DEFAULT_RETRY_AFTER_SECONDS);
            if (($this->clock)() + $wait > $deadline) {
                throw new TimeoutException(
                    'POST',
                    '/v4/Pdf',
                    $maxWaitMs,
                    detail: sprintf(
                        'The gift card PDF was still not ready%s. Waiting another %d second%s would pass maxWaitMs, so '
                        . 'getWhenReady() stopped asking; the call is read-only, so asking again later is safe.',
                        $statusMessage !== null && $statusMessage !== '' ? ': "' . $statusMessage . '"' : '',
                        $wait,
                        $wait === 1 ? '' : 's',
                    ),
                    // Not "timed out after": the time that passed is less than maxWaitMs.
                    lead: sprintf('POST /v4/Pdf gave up waiting for the gift card PDF within maxWaitMs (%d ms).', $maxWaitMs),
                );
            }

            ($this->sleep)($wait);
        }
    }

    /**
     * One `POST /v4/Pdf`, and the API's status message, which getWhenReady() quotes when it gives up.
     *
     * @return array{PdfResult, ?string}
     */
    private function fetch(string $orderUid, ?int $voucherId, ?string $pdfTemplateUid, ?bool $combine): array
    {
        $body = Wire::object([
            'OrderUID' => $orderUid,
            'VoucherID' => $voucherId,
            'PDFTemplateUid' => $pdfTemplateUid,
            'Combine' => $combine,
        ]);
        $response = $this->client->send('POST', '/v4/Pdf', $body, retryable: true, read: self::decodeContent(...));

        $documents = [];
        foreach (Wire::rows($response->data, 'Documents') as $row) {
            $documents[] = new PdfDocument(
                voucherIds: Wire::ints($row, 'VoucherIDs'),
                pdfTemplateUid: Wire::string($row, 'PDFTemplateUid'),
                fileName: Wire::string($row, 'FileName'),
                contentType: Wire::string($row, 'ContentType'),
                // Bytes by now: decodeContent() decoded the base64 inside send().
                content: Wire::string($row, 'Content'),
            );
        }

        $result = new PdfResult(
            ready: $response->httpStatus === 200,
            orderUid: Wire::string($response->data, 'OrderUID'),
            documents: $documents,
            retryAfter: self::seconds($response->header('Retry-After')),
        );

        return [$result, Wire::string($response->data, 'StatusMessage') ?? Wire::string($response->data, 'Message')];
    }

    /**
     * Replaces each document's base64 `Content` with the bytes it encodes. The reader
     * send() runs on a 2xx body, so content that is not valid base64 is handled like
     * a body that is not JSON: a ConnectionException, retried as a read is.
     *
     * @throws \UnexpectedValueException for content that is not valid base64; the message names the document only
     */
    private static function decodeContent(
        #[\SensitiveParameter]
        mixed $data,
    ): mixed {
        $documents = Wire::field($data, 'Documents');
        if (!is_array($data) || !is_array($documents)) {
            return $data;
        }

        foreach ($documents as $index => $document) {
            if (!is_array($document) || !is_string($document['Content'] ?? null)) {
                continue;
            }

            $bytes = self::base64($document['Content']);
            if ($bytes === null) {
                throw new \UnexpectedValueException(sprintf('not usable: Documents[%s].Content is not valid base64', $index));
            }
            $document['Content'] = $bytes;
            $documents[$index] = $document;
        }
        $data['Documents'] = $documents;

        return $data;
    }

    /**
     * Decodes standard base64 the way the API writes it — the RFC 4648 alphabet,
     * padded to a multiple of four characters, with no whitespace — and returns
     * null for anything else, rather than decoding what is left of a garbled value.
     */
    private static function base64(
        #[\SensitiveParameter]
        string $text,
    ): ?string {
        $length = strlen($text);
        $padding = str_ends_with($text, '==') ? 2 : (str_ends_with($text, '=') ? 1 : 0);
        if ($length % 4 !== 0 || strspn($text, self::BASE64_ALPHABET, 0, $length - $padding) !== $length - $padding) {
            return null;
        }

        $bytes = base64_decode($text, true);

        return $bytes === false ? null : $bytes;
    }

    /**
     * Whole seconds from a `Retry-After` value; null when absent, or not a whole number
     * of seconds — an HTTP date included. A value with as many digits as PHP_INT_MAX,
     * leading zeros aside, or more, reads as PHP_INT_MAX.
     */
    private static function seconds(?string $value): ?int
    {
        $value = $value === null ? '' : trim($value, " \t");
        if (preg_match('/^[0-9]+$/D', $value) !== 1) {
            return null;
        }

        // Counted rather than cast: PHP 8.5 warns when an (int) cast cannot represent
        // the value. Any number with fewer digits than PHP_INT_MAX fits.
        $digits = ltrim($value, '0');

        return strlen($digits) >= strlen((string) PHP_INT_MAX) ? PHP_INT_MAX : (int) $digits;
    }
}
