<?php

declare(strict_types=1);

namespace App\Services;

class BookingLayerPayload
{
    /**
     * Booking Layer wraps list results in a top-level "data" array. A response
     * without one is an error body or a contract change — never an empty result
     * set — so it must not be coerced into [] and read as "no reservations".
     * Guzzle already throws on 4xx/5xx; this closes the 2xx-with-junk-body gap
     * that would otherwise reach upsertMany() and clear the mirror table.
     */
    public static function list(mixed $payload, string $context): array
    {
        if (!is_array($payload) || !array_key_exists('data', $payload)) {
            throw new \RuntimeException(sprintf(
                'Booking Layer %s response has no "data" key; refusing to treat it as an empty result.',
                $context
            ));
        }

        if (!is_array($payload['data'])) {
            throw new \RuntimeException(sprintf(
                'Booking Layer %s response has a non-array "data" value.',
                $context
            ));
        }

        return $payload['data'];
    }
}
