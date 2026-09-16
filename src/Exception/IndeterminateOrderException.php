<?php

declare(strict_types=1);

namespace Huuray\Exception;

/**
 * An order request failed in a way that leaves its outcome unknown — a timeout,
 * a dropped connection, a 5xx, or a 2xx whose body could not be read.
 *
 * **Do not retry.** `POST /v4/Order` has no idempotency key, so a retry can
 * order a second set of gift cards. The order may or may not have been created.
 *
 * Resolve it by looking the order up instead. The API answers "no match" on
 * `/v4/Search` with a 404, which this client throws as {@see NotFoundException}
 * — catch it and read it as "the order did not land":
 *
 *     try {
 *         $client->sendReward(refId: 'payroll-2026-08-jane'); // plus your other arguments
 *     } catch (IndeterminateOrderException $e) {
 *         try {
 *             $found = $client->orders->search(refId: $e->refId);
 *             if ($found->orderUid !== null) {
 *                 // The order landed. Nothing more to do.
 *             } else {
 *                 // No match -> it did not land. Safe to send again, same refId.
 *             }
 *         } catch (NotFoundException) {
 *             // 404 -> no order exists for this refId. Safe to send again.
 *         }
 *         // Anything else means the lookup itself failed; the outcome is still unknown.
 *     }
 */
class IndeterminateOrderException extends HuurayException
{
    public function __construct(
        /** The `RefID` sent with the order, if any — the key to look it up with. */
        public readonly ?string $refId,
        ?\Throwable $previous = null,
    ) {
        $tail = $refId !== null && $refId !== ''
            ? sprintf('Call $client->orders->search(refId: %s) to check whether it landed.', var_export($refId, true))
            : 'No RefID was sent, so the order cannot be looked up by reference. '
                . 'Always set refId on orders so this case is recoverable.';

        parent::__construct(
            'The order request did not complete, so it is unknown whether the order was created. '
            . 'Do NOT retry: /v4/Order has no idempotency key and a retry may order a second time. '
            . $tail,
            0,
            $previous,
        );
    }
}
