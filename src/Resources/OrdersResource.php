<?php

declare(strict_types=1);

namespace Huuray\Resources;

use Huuray\Exception\ConnectionException;
use Huuray\Exception\HuurayException;
use Huuray\Exception\IndeterminateOrderException;
use Huuray\Exception\NotFoundException;
use Huuray\Exception\ServerException;
use Huuray\Exception\ValidationException;
use Huuray\Internal\Wire;
use Huuray\Recipient;
use Huuray\Result\CancelledVoucher;
use Huuray\Result\CancelResult;
use Huuray\Result\CreateOrderResult;
use Huuray\Result\CreateSyncOrderResult;
use Huuray\Result\ResendResult;
use Huuray\Result\SearchOrdersResult;
use Huuray\Result\Voucher;

/**
 * Ordering, searching, resending and cancelling gift cards.
 *
 * Everything here moves real value, so this is where the safety rules live: no
 * automatic retries, integers only for money, and an explicit "outcome unknown"
 * exception rather than a silent second order.
 */
class OrdersResource extends AbstractResource
{
    /** The maximum `quantity` a synchronous order may request, per the API. */
    public const SYNC_QUANTITY_LIMIT = 25;

    /**
     * Places an order and returns immediately.
     *
     * `POST /v4/Order` with `Sync: false`
     *
     * Huuray delivers the gift cards using the template you name; no voucher data
     * comes back. Use `search()` with your `refId` to find the order later.
     *
     * **Not retried on failure.** The endpoint has no idempotency key, so a retry
     * can order twice. A timeout, a dropped connection, a 5xx, or an unreadable
     * 2xx body throws IndeterminateOrderException instead.
     *
     * @param string                         $productToken     Product identifier from `catalogue->list()`.
     * @param int                            $value            Denomination **in minor units** — 50.00 is `5000`.
     *                                                         Any float or bool is rejected, including 50.00.
     * @param string                         $currency         ISO alpha-3 currency code.
     * @param int                            $quantity         How many codes to order, at least 1. Any float or
     *                                                         bool is rejected, including 2.0.
     * @param \DateTimeInterface|string|null $expires          Optional expiry for the gift cards. Cannot exceed
     *                                                         the product default.
     * @param string|null                    $refId            Your own identifier for this order. Strongly
     *                                                         recommended: it is what makes an order recoverable
     *                                                         after a timeout.
     * @param int|null                       $templateId       Delivery template id from `templates->list()`.
     *                                                         Omit for no delivery.
     * @param string|null                    $pdfTemplateUid   PDF template uid from `templates->list()->pdfTemplates`,
     *                                                         attached as a document to the emails sent by
     *                                                         `templateId`. Requires `templateId`, which the API
     *                                                         requires to be an email template. The PDF template
     *                                                         must also be available for the ordered product's
     *                                                         brand and country (`brandName` / `country` on the
     *                                                         PDF template, where null means any); otherwise the
     *                                                         API rejects the order with a 422, thrown as
     *                                                         ValidationException. This client does not pre-check that.
     * @param \DateTimeInterface|string|null $deliveryDatetime Schedule delivery for a future time. Omit to deliver
     *                                                         as soon as possible.
     * @param string|null                    $personalMessage  A message included in every email or SMS for this order.
     * @param list<Recipient>|null           $recipients       Required when `templateId` is set. The count must be
     *                                                         either 1 or exactly `quantity`.
     *
     * @throws \InvalidArgumentException   before any request, for an invalid argument
     * @throws IndeterminateOrderException when the outcome is unknown — do not retry
     * @throws ValidationException         when the API rejects the order
     * @throws HuurayException
     */
    public function create(
        string $productToken,
        // Natively `mixed`, PHPDoc `int`: a caller without strict_types must not have
        // 1.5 or `true` coerced to an int before the guard sees it. See Wire::requireMinorUnits().
        mixed $value,
        string $currency,
        mixed $quantity,
        \DateTimeInterface|string|null $expires = null,
        ?string $refId = null,
        ?int $templateId = null,
        ?string $pdfTemplateUid = null,
        \DateTimeInterface|string|null $deliveryDatetime = null,
        #[\SensitiveParameter]
        ?string $personalMessage = null,
        #[\SensitiveParameter]
        ?array $recipients = null,
    ): CreateOrderResult {
        $body = self::buildOrderBody(
            $productToken,
            $value,
            $currency,
            $quantity,
            false,
            $expires,
            $refId,
            $templateId,
            $pdfTemplateUid,
            $deliveryDatetime,
            $personalMessage,
            $recipients,
        );
        $data = $this->postOrder($body, $refId);

        return new CreateOrderResult(Wire::string($data, 'OrderUID'), Wire::string($data, 'RefID'));
    }

    /**
     * Places an order and waits for the vouchers.
     *
     * `POST /v4/Order` with `Sync: true`
     *
     * `quantity` is limited to {@see self::SYNC_QUANTITY_LIMIT} for synchronous
     * orders. Voucher codes are blank unless `ReturnCode` is enabled on your account.
     *
     * **Not retried on failure**, the same as {@see self::create()}. Parameters are
     * the same as `create()`.
     *
     * @param int                  $value          Denomination **in minor units** — 50.00 is `5000`. Any float
     *                                             or bool is rejected, including 50.00.
     * @param int                  $quantity       How many codes to order; at least 1 and at most 25. Any float
     *                                             or bool is rejected before the limit is checked.
     * @param string|null          $pdfTemplateUid PDF template uid from `templates->list()->pdfTemplates`, attached
     *                                             as a document to the emails sent by `templateId`, which it
     *                                             requires. The PDF template must be available for the ordered
     *                                             product's brand and country (`brandName` / `country` on the PDF
     *                                             template, where null means any); otherwise the API rejects the
     *                                             order with a 422, thrown as ValidationException. This client
     *                                             does not pre-check that.
     * @param list<Recipient>|null $recipients     Required when `templateId` is set. The count must be either 1
     *                                             or exactly `quantity`.
     *
     * @throws \InvalidArgumentException   before any request, for an invalid argument
     * @throws IndeterminateOrderException when the outcome is unknown — do not retry
     * @throws ValidationException         when the API rejects the order
     * @throws HuurayException
     */
    public function createSync(
        string $productToken,
        mixed $value,
        string $currency,
        mixed $quantity,
        \DateTimeInterface|string|null $expires = null,
        ?string $refId = null,
        ?int $templateId = null,
        ?string $pdfTemplateUid = null,
        \DateTimeInterface|string|null $deliveryDatetime = null,
        #[\SensitiveParameter]
        ?string $personalMessage = null,
        #[\SensitiveParameter]
        ?array $recipients = null,
    ): CreateSyncOrderResult {
        // Type first: the limit must never be checked against a coerced or fractional count.
        $quantity = Wire::requirePositiveInt($quantity, 'quantity');
        if ($quantity > self::SYNC_QUANTITY_LIMIT) {
            throw new \InvalidArgumentException(sprintf(
                'Synchronous orders are limited to %d codes; received %d. Use $client->orders->create() for larger orders.',
                self::SYNC_QUANTITY_LIMIT,
                $quantity,
            ));
        }

        $body = self::buildOrderBody(
            $productToken,
            $value,
            $currency,
            $quantity,
            true,
            $expires,
            $refId,
            $templateId,
            $pdfTemplateUid,
            $deliveryDatetime,
            $personalMessage,
            $recipients,
        );
        $data = $this->postOrder($body, $refId);

        return new CreateSyncOrderResult(
            Wire::string($data, 'OrderUID'),
            Wire::string($data, 'RefID'),
            self::mapVouchers($data),
        );
    }

    /**
     * Sends one gift card to one recipient — the common case in a single call.
     *
     * Performs exactly one `POST /v4/Order` with `Sync: false` and `Quantity: 1`.
     *
     * `refId` is required here even though the API treats it as optional, and is
     * never generated for you: a generated key is not in your system, so it could
     * not be used to reconcile an order whose outcome is unknown.
     *
     * @param int         $value          Denomination **in minor units** — 50.00 is `5000`. Any float or bool is
     *                                    rejected.
     * @param int         $templateId     Delivery template id from `templates->list()`.
     * @param string      $refId          Your reconciliation key. **Required by this SDK**: without it, an order
     *                                    that times out cannot be looked up.
     * @param string|null $pdfTemplateUid PDF template uid from `templates->list()->pdfTemplates`, attached as a
     *                                    document to the email. The API requires `templateId` to be an email
     *                                    template when this is set. The PDF template must also be available for
     *                                    the ordered product's brand and country (`brandName` / `country` on the
     *                                    PDF template, where null means any); otherwise the API rejects the order
     *                                    with a 422, thrown as ValidationException. This client does not pre-check that.
     *
     * @throws \InvalidArgumentException   before any request, for an invalid argument
     * @throws IndeterminateOrderException when the outcome is unknown — do not retry
     * @throws ValidationException         when the API rejects the order
     * @throws HuurayException
     */
    public function sendReward(
        string $productToken,
        mixed $value,
        string $currency,
        #[\SensitiveParameter]
        Recipient $recipient,
        int $templateId,
        string $refId,
        ?string $pdfTemplateUid = null,
        \DateTimeInterface|string|null $expires = null,
        \DateTimeInterface|string|null $deliveryDatetime = null,
        #[\SensitiveParameter]
        ?string $personalMessage = null,
    ): CreateOrderResult {
        if ($refId === '') {
            throw new \InvalidArgumentException(
                'refId is required by sendReward(). It is the only way to determine whether an order landed '
                . 'if the request times out, because /v4/Order has no idempotency key. Use a stable key from '
                . 'your own system, e.g. "payroll-2026-08-jane".',
            );
        }

        return $this->create(
            productToken: $productToken,
            value: $value,
            currency: $currency,
            quantity: 1,
            expires: $expires,
            refId: $refId,
            templateId: $templateId,
            pdfTemplateUid: $pdfTemplateUid,
            deliveryDatetime: $deliveryDatetime,
            personalMessage: $personalMessage,
            recipients: [$recipient],
        );
    }

    /**
     * Searches gift cards from previous orders.
     *
     * `POST /v4/Search`
     *
     * Also the way to resolve an order whose outcome is unknown: search by the
     * `refId` you sent. A read, despite being a POST.
     *
     * "No match" comes back as HTTP 404, thrown as NotFoundException — from a
     * reconciliation flow, read it as "the order did not land".
     *
     * @param int|null $voucherId Required for the response to include the voucher code.
     *
     * @throws NotFoundException when nothing matches
     * @throws \InvalidArgumentException before any request, for a custom nonce that is empty, over 50 characters or outside visible ASCII, or a
     *                                   string that is not valid UTF-8
     * @throws HuurayException
     */
    public function search(
        ?string $orderUid = null,
        ?int $voucherId = null,
        ?string $productToken = null,
        ?string $refId = null,
        ?int $smsTemplateId = null,
        ?int $emailTemplateId = null,
        \DateTimeInterface|string|null $deliveryDatetime = null,
        #[\SensitiveParameter]
        ?string $recipientName = null,
        #[\SensitiveParameter]
        ?string $recipientEmail = null,
        #[\SensitiveParameter]
        ?string $recipientPhone = null,
        ?string $recipientRefId = null,
    ): SearchOrdersResult {
        $body = Wire::object([
            'OrderUID' => $orderUid,
            'VoucherID' => $voucherId,
            'ProductToken' => $productToken,
            'RefID' => $refId,
            'SMSTemplateID' => $smsTemplateId,
            'EmailTemplateID' => $emailTemplateId,
            'DeliveryDatetime' => Wire::dateTime($deliveryDatetime),
            'RecipientName' => $recipientName,
            'RecipientEmail' => $recipientEmail,
            'RecipientPhone' => $recipientPhone,
            'RecipientRefID' => $recipientRefId,
        ]);
        $data = $this->client->send('POST', '/v4/Search', $body, retryable: true)->data;

        return new SearchOrdersResult(
            Wire::string($data, 'OrderUID'),
            Wire::string($data, 'RefID'),
            self::mapVouchers($data),
        );
    }

    /**
     * Resends an order, or one voucher from it, to its original recipients.
     *
     * `POST /v4/Resend`
     *
     * **Never retried.** A resend delivers a live gift card, so repeating it on a
     * timeout would re-send real value.
     *
     * Check `partial`: the API answers `206` when only some resends succeeded.
     *
     * @param int|null $voucherId A single voucher. Omit to resend the whole order to all its recipients.
     *
     * @throws \InvalidArgumentException before any request, for a custom nonce that is empty, over 50 characters or outside visible ASCII, or a
     *                                   string that is not valid UTF-8
     * @throws HuurayException
     */
    public function resend(string $orderUid, ?int $voucherId = null): ResendResult
    {
        $response = $this->client->send(
            'POST',
            '/v4/Resend',
            Wire::object(['OrderUID' => $orderUid, 'VoucherID' => $voucherId]),
            retryable: false,
        );

        return new ResendResult(
            numberOfResends: Wire::int($response->data, 'NumberOfResends'),
            partial: $response->httpStatus === 206,
        );
    }

    /**
     * Cancels an order, or one voucher from it.
     *
     * `DELETE /v4/Cancel` — a DELETE carrying a JSON body, as the specification defines it.
     *
     * Check `partial`: the API answers `206` when only some vouchers could be
     * cancelled, and the per-voucher outcome is in `vouchers`. **Never retried.**
     *
     * @param int|null $voucherId A single voucher. Omit to attempt cancelling the whole order.
     *
     * @throws \InvalidArgumentException before any request, for a custom nonce that is empty, over 50 characters or outside visible ASCII, or a
     *                                   string that is not valid UTF-8
     * @throws HuurayException
     */
    public function cancel(string $orderUid, ?int $voucherId = null): CancelResult
    {
        $response = $this->client->send(
            'DELETE',
            '/v4/Cancel',
            Wire::object(['OrderUID' => $orderUid, 'VoucherID' => $voucherId]),
            retryable: false,
        );

        $vouchers = [];
        foreach (Wire::rows($response->data, 'Vouchers') as $row) {
            $vouchers[] = new CancelledVoucher(
                id: Wire::int($row, 'ID') ?? 0,
                cancelled: Wire::bool($row, 'Cancelled') ?? false,
            );
        }

        return new CancelResult(
            orderUid: Wire::string($response->data, 'OrderUID'),
            orderCancelled: Wire::bool($response->data, 'OrderCancelled') ?? false,
            vouchers: $vouchers,
            partial: $response->httpStatus === 206,
        );
    }

    /**
     * Validates an order and builds the single request body it becomes.
     *
     * @param array<mixed>|null $recipients
     *
     * @throws \InvalidArgumentException
     */
    private static function buildOrderBody(
        string $productToken,
        mixed $value,
        string $currency,
        mixed $quantity,
        bool $sync,
        \DateTimeInterface|string|null $expires,
        ?string $refId,
        ?int $templateId,
        ?string $pdfTemplateUid,
        \DateTimeInterface|string|null $deliveryDatetime,
        #[\SensitiveParameter]
        ?string $personalMessage,
        #[\SensitiveParameter]
        ?array $recipients,
    ): \stdClass {
        $value = Wire::requireMinorUnits($value);
        $quantity = Wire::requirePositiveInt($quantity, 'quantity');

        // Null means "not supplied" on both sides: a null pdfTemplateUid needs no
        // templateId, and a null templateId never counts as one.
        if ($pdfTemplateUid !== null && $templateId === null) {
            throw new \InvalidArgumentException(
                'templateId is required when pdfTemplateUid is set — the API attaches the PDF template to the '
                . 'emails sent by the delivery template, which must be an email template.',
            );
        }

        $wireRecipients = null;
        if ($recipients !== null) {
            $wireRecipients = [];
            foreach ($recipients as $recipient) {
                if (!$recipient instanceof Recipient) {
                    throw new \InvalidArgumentException(sprintf(
                        'recipients must be a list of %s objects, found %s.',
                        Recipient::class,
                        get_debug_type($recipient),
                    ));
                }
                $wireRecipients[] = Wire::object([
                    'Name' => $recipient->name,
                    'Email' => $recipient->email,
                    'Phone' => $recipient->phone,
                    'RefID' => $recipient->refId,
                ]);
            }
        }

        if ($templateId !== null) {
            $count = $wireRecipients === null ? 0 : count($wireRecipients);
            if ($count === 0) {
                throw new \InvalidArgumentException(
                    'recipients is required when templateId is set — the template needs somewhere to deliver to.',
                );
            }
            if ($count !== 1 && $count !== $quantity) {
                throw new \InvalidArgumentException(sprintf(
                    'recipients must contain either 1 entry or exactly quantity (%d); received %d.',
                    $quantity,
                    $count,
                ));
            }
        }

        return Wire::object([
            'Product' => Wire::object([
                'Token' => $productToken,
                'Value' => $value,
                'Currency' => $currency,
                'Quantity' => $quantity,
                'Expires' => Wire::dateTime($expires),
            ]),
            'Sync' => $sync,
            'RefID' => $refId,
            'DeliveryTemplateId' => $templateId,
            'DeliveryPDFTemplateUid' => $pdfTemplateUid,
            'DeliveryDatetime' => Wire::dateTime($deliveryDatetime),
            'PersonalMessage' => $personalMessage,
            'Recipients' => $wireRecipients,
        ]);
    }

    /**
     * Sends an order. Never retried; a failure that leaves the outcome unknown is
     * rethrown as IndeterminateOrderException so the caller reconciles instead.
     *
     * @throws IndeterminateOrderException
     * @throws HuurayException
     */
    private function postOrder(
        // The body carries recipient emails and phone numbers; keep them out of the
        // trace args of every exception thrown from here.
        #[\SensitiveParameter]
        \stdClass $body,
        ?string $refId,
    ): mixed {
        try {
            return $this->client->send('POST', '/v4/Order', $body, retryable: false)->data;
        } catch (ConnectionException|ServerException $cause) {
            // The request may well have been processed. A 4xx is different: the API
            // definitively rejected that order, so it is rethrown unchanged.
            throw new IndeterminateOrderException($refId, $cause);
        }
    }

    /** @return list<Voucher> */
    private static function mapVouchers(
        #[\SensitiveParameter]
        mixed $data,
    ): array {
        $vouchers = [];
        foreach (Wire::rows($data, 'Vouchers') as $row) {
            $recipient = Wire::field($row, 'Recipient');
            $vouchers[] = new Voucher(
                id: Wire::int($row, 'ID'),
                code: Wire::string($row, 'Code'),
                cvv: Wire::string($row, 'CVV'),
                redeemLink: Wire::string($row, 'RedeemLink'),
                expires: Wire::string($row, 'Expires'),
                recipient: is_array($recipient)
                    ? new Recipient(
                        name: Wire::string($recipient, 'Name'),
                        email: Wire::string($recipient, 'Email'),
                        phone: Wire::string($recipient, 'Phone'),
                        refId: Wire::string($recipient, 'RefID'),
                    )
                    : null,
            );
        }

        return $vouchers;
    }
}
