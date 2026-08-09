<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Reservation;
use GuzzleHttp\Client;

/**
 * Pulls active reservations from Booking Layer into the local mirror.
 *
 * Shared by ReservationController::pull() and bin/pull_reservations.php so the
 * two cannot drift apart.
 */
class BookingLayerReservations
{
    /** Booking Layer defaults to 10 rows per page when no limit is given. */
    private const PAGE_LIMIT = 100;

    /** Backstop so a malformed meta block cannot spin forever. */
    private const MAX_PAGES = 200;

    public function __construct(
        private readonly Client $client,
        private readonly Reservation $reservations,
        private readonly string $baseUrl,
        private readonly string $status
    ) {
    }

    /**
     * @return array{fetched:int, active:int, synced:int, deactivated:int, pages:int}
     */
    public function pull(string $apiKey): array
    {
        [$bookings, $pages] = $this->fetchPaged(
            '/bookings',
            ['status' => $this->status],
            ['guests', 'booker'],
            $apiKey,
            'bookings'
        );

        $deactivated = $this->reservations->deactivateCheckedOutCustomers($bookings);
        $active      = $this->reservations->filterActiveGuests($bookings);

        foreach ($active as &$booking) {
            $bookingId            = (string) ($booking['id'] ?? '');
            $booking['_products'] = $bookingId === '' ? [] : $this->fetchProducts($bookingId, $apiKey);
        }
        unset($booking);

        return [
            'fetched'     => count($bookings),
            'active'      => count($active),
            'synced'      => $this->reservations->upsertMany($active),
            'deactivated' => $deactivated,
            'pages'       => $pages,
        ];
    }

    /**
     * Product titles for one booking. The booking_id filter is a query string
     * parameter — sending it as a JSON body leaves the list unfiltered, which
     * returns an arbitrary slice of the whole account.
     */
    private function fetchProducts(string $bookingId, string $apiKey): array
    {
        [$lines] = $this->fetchPaged(
            '/booking_lines',
            ['booking_id' => $bookingId],
            ['product'],
            $apiKey,
            'booking_lines'
        );

        return array_values(array_filter(array_map(
            static fn($line) => (string) ($line['product']['backoffice_title'] ?? ''),
            $lines
        )));
    }

    /**
     * Walks every page of a list endpoint. Reading only the first page would
     * hand an incomplete set to upsertMany(), which deletes local rows missing
     * from it.
     *
     * @return array{0: array, 1: int} rows and the number of pages read
     */
    private function fetchPaged(
        string $path,
        array $filters,
        array $expand,
        string $apiKey,
        string $context
    ): array {
        $rows = [];
        $page = 1;

        while ($page <= self::MAX_PAGES) {
            $url = $this->baseUrl . $path . '?' . $this->buildQuery(
                $filters + ['page' => $page, 'limit' => self::PAGE_LIMIT],
                $expand
            );

            $response = $this->client->request('GET', $url, [
                'headers' => [
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $apiKey,
                ],
                'timeout' => 30,
            ]);

            $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $batch   = BookingLayerPayload::list($payload, $context);
            $rows    = array_merge($rows, $batch);

            $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];

            // No meta means the endpoint is not paginated — one page is all there is.
            if ($meta === [] || $batch === []) {
                break;
            }

            $lastPage = (int) ($meta['last_page'] ?? 1);

            if ($page >= $lastPage) {
                break;
            }

            $page++;
        }

        return [$rows, $page];
    }

    /**
     * expand[] keeps its literal brackets — http_build_query would index them
     * as expand[0], which the API does not accept.
     */
    private function buildQuery(array $params, array $expand): string
    {
        $parts = [];

        foreach ($expand as $relation) {
            $parts[] = 'expand[]=' . rawurlencode($relation);
        }

        foreach ($params as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }

        return implode('&', $parts);
    }
}
