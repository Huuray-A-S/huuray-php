<?php

declare(strict_types=1);

namespace Huuray\Resources;

use Huuray\Exception\HuurayException;
use Huuray\Internal\Wire;
use Huuray\Result\CheckStockResult;

class StockResource extends AbstractResource
{
    /**
     * Current stock for a product.
     *
     * `POST /v4/Stock`
     *
     * A read, despite being a POST.
     *
     * @param string   $productToken The product to check. Get this from `catalogue->list()`.
     * @param int|null $value        The denomination to check, **in minor units** — 50.00 is `5000`.
     *                               Any float or bool is rejected, including 50.00. Omit to use the
     *                               product's default price.
     *
     * @throws \InvalidArgumentException before any request, for a value that is not an int, a custom nonce that is
     *                                   empty, over 50 characters or outside visible ASCII, or a string that is not valid UTF-8
     * @throws HuurayException
     */
    public function check(
        string $productToken,
        // Natively `mixed`, PHPDoc `int|null`: a caller without strict_types must not have
        // 50.00 or `true` coerced to an int before the guard sees it. See Wire::requireMinorUnits().
        mixed $value = null,
    ): CheckStockResult {
        if ($value !== null) {
            $value = Wire::requireMinorUnits($value);
        }

        $body = Wire::object(['ProductToken' => $productToken, 'Value' => $value]);
        $data = $this->client->send('POST', '/v4/Stock', $body, retryable: true)->data;

        return new CheckStockResult(Wire::int($data, 'Stock'));
    }
}
