<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;

/**
 * A bill is a child of a booking with its own currency. Amendments carry no
 * currency of their own, so POS charges in a currency other than the booking's
 * are posted to a bill created in the POS currency instead.
 */
class BookingLayerBills
{
    public function __construct(
        private readonly Client $client,
        private readonly string $baseUrl,
        private readonly string $currency
    ) {
    }

    public function currency(): string
    {
        return $this->currency;
    }

    /**
     * Creates a bill on the booking and returns its id. The returned id is what
     * amendments are posted against, in place of the booking id.
     */
    public function create(string $bookingId, string $apiKey): string
    {
        if ($bookingId === '') {
            throw new \RuntimeException('Cannot create a bill without a booking id.');
        }

        $response = $this->client->request('POST', rtrim($this->baseUrl, '/') . '/bills', [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $apiKey,
            ],
            'json' => [
                'booking_id' => $bookingId,
                'currency'   => $this->currency,
            ],
            'timeout' => 30,
        ]);

        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $billId  = is_array($payload) ? (string) ($payload['data']['id'] ?? '') : '';

        if ($billId === '') {
            throw new \RuntimeException(sprintf(
                'Booking Layer created a %s bill for booking %s but returned no id.',
                $this->currency,
                $bookingId
            ));
        }

        return $billId;
    }
}
