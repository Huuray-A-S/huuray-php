<?php

declare(strict_types=1);

namespace Huuray\Resources;

use Huuray\Exception\HuurayException;
use Huuray\Internal\Wire;
use Huuray\Result\ExchangeRateResult;

class ExchangeRatesResource extends AbstractResource
{
    /**
     * Current exchange rate and spread between two currencies.
     *
     * `GET /v4/ExchangeRates`
     *
     * @param string $from Source currency, ISO alpha-3.
     * @param string $to   Target currency, ISO alpha-3.
     *
     * @throws \InvalidArgumentException before any request, for a custom nonce that is empty, over 50 characters or outside visible ASCII
     * @throws HuurayException
     */
    public function get(string $from, string $to): ExchangeRateResult
    {
        $data = $this->client->send(
            'GET',
            '/v4/ExchangeRates',
            query: ['FromCurrency' => $from, 'ToCurrency' => $to],
            retryable: true,
        )->data;

        return new ExchangeRateResult(
            exchangeRate: Wire::number($data, 'ExchangeRate'),
            spread: Wire::number($data, 'Spread'),
        );
    }
}
