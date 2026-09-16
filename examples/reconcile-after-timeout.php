<?php

declare(strict_types=1);

/*
 * The pattern that matters most: recovering from an order whose outcome is unknown.
 *
 * POST /v4/Order has no idempotency key. If the request times out or the server
 * returns a 5xx, the order may or may not have been created — and retrying can
 * order a second time, for real money.
 *
 * So this SDK never retries an order. It throws IndeterminateOrderException and
 * expects you to reconcile, which is only possible if you sent a refId you can
 * look up. That is why sendReward() requires one.
 *
 * Running this sends a real order. Replace the placeholders first.
 */

use Huuray\Exception\IndeterminateOrderException;
use Huuray\Exception\NotFoundException;
use Huuray\HuurayClient;
use Huuray\Recipient;
use Huuray\Result\CreateOrderResult;

require __DIR__ . '/../vendor/autoload.php';

$huuray = new HuurayClient(
    apiToken: (string) getenv('HUURAY_API_TOKEN'),
    apiSecret: (string) getenv('HUURAY_API_SECRET'),
);

/** A key from your own system — stable, unique, and meaningful to you. */
$refId = 'payroll-2026-08-jane';

function sendOnce(HuurayClient $huuray, string $refId): ?CreateOrderResult
{
    try {
        $reward = $huuray->sendReward(
            productToken: 'REPLACE_WITH_A_REAL_TOKEN',
            value: 50_00, // minor units — 50.00
            currency: 'DKK',
            recipient: new Recipient(name: 'Jane Doe', email: 'jane@example.com'),
            templateId: 1,
            refId: $refId,
        );

        echo "Ordered. orderUid={$reward->orderUid}\n";

        return $reward;
    } catch (IndeterminateOrderException) {
        // Do NOT retry the order here. Find out what actually happened.
        echo "Order outcome unknown. Reconciling by refId instead of retrying.\n";
    }

    try {
        $found = $huuray->orders->search(refId: $refId);
    } catch (NotFoundException) {
        // The API answers 404 when no order matched. That IS the answer: the
        // order did not land.
        echo "No order exists for this refId (404). Safe to send again with the same refId.\n";

        return null;
    }
    // Anything other than NotFoundException propagates: it means the lookup itself
    // failed and the outcome is STILL unknown. Never treat a failed lookup as
    // "not landed".

    if ($found->orderUid !== null) {
        echo "It landed after all: orderUid={$found->orderUid}. Nothing more to do.\n";

        return new CreateOrderResult($found->orderUid, $found->refId);
    }

    echo "No order exists for this refId. Safe to send again with the same refId.\n";

    return null;
}

sendOnce($huuray, $refId);
