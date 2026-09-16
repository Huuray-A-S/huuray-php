<?php

declare(strict_types=1);

namespace Huuray\Resources;

use Huuray\Exception\HuurayException;
use Huuray\Internal\Wire;
use Huuray\Result\Balance;
use Huuray\Result\ListBalancesResult;

class BalancesResource extends AbstractResource
{
    /**
     * Available balances on your B2B account, per currency.
     *
     * `GET /v4/Balance`
     *
     * Amounts are in **minor units**: `50000` means 500.00, not 50000.00.
     *
     * @throws \InvalidArgumentException before any request, for a custom nonce that is empty, over 50 characters or outside visible ASCII
     * @throws HuurayException
     */
    public function list(): ListBalancesResult
    {
        $data = $this->client->send('GET', '/v4/Balance', retryable: true)->data;

        $balances = [];
        foreach (Wire::rows($data, 'Balances') as $row) {
            $balances[] = new Balance(
                currency: Wire::string($row, 'Currency'),
                balance: Wire::int($row, 'Balance') ?? 0,
                master: Wire::bool($row, 'Master') ?? false,
            );
        }

        return new ListBalancesResult($balances);
    }
}
