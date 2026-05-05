<?php

declare(strict_types=1);

use App\Models\AccessToken;
use App\Models\Reservation;
use Dotenv\Dotenv;
use GuzzleHttp\Client;

require dirname(__DIR__) . '/vendor/autoload.php';

$rootPath = dirname(__DIR__);

if (file_exists($rootPath . '/.env')) {
    Dotenv::createImmutable($rootPath)->safeLoad();
}

$container = require $rootPath . '/config/container.php';
$settings = $container->get('settings');

/** @var AccessToken $accessTokens */
$accessTokens = $container->get(AccessToken::class);
/** @var Reservation $reservations */
$reservations = $container->get(Reservation::class);
/** @var Client $client */
$client = $container->get(Client::class);

$accessToken = $accessTokens->latest();

if ($accessToken === null) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] Booking Layer API token was not found in access_token.\n");
    exit(1);
}

try {
    $bookinglayer = $settings['bookinglayer'];
    $baseUrl      = rtrim($bookinglayer['base_url'], '/');
    $authHeader   = ['Authorization' => 'Bearer ' . $accessToken['api_key']];

    // Step 1: fetch confirmed bookings with guests expanded
    $bookingsResponse = $client->request('GET', $baseUrl . '/bookings', [
        'headers' => array_merge($authHeader, ['Content-Type' => 'application/json']),
        'query'   => ['expand[]' => ['guests', 'booker']],
        'json'    => ['status' => $bookinglayer['reservation_status']],
        'timeout' => 30,
    ]);

    $bookingsPayload = json_decode((string) $bookingsResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
    $bookings        = is_array($bookingsPayload['data'] ?? null) ? $bookingsPayload['data'] : [];

    // Step 2: for each booking fetch booking_lines to get product names
    foreach ($bookings as &$booking) {
        $bookingId = (string) ($booking['id'] ?? '');

        if ($bookingId === '') {
            $booking['_products'] = [];
            continue;
        }

        $linesResponse = $client->request('GET', $baseUrl . '/booking_lines', [
            'headers' => array_merge($authHeader, ['Content-Type' => 'application/json']),
            'query'   => ['expand[]' => 'product'],
            'json'    => ['booking_id' => $bookingId],
            'timeout' => 30,
        ]);

        $linesPayload         = json_decode((string) $linesResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $lines                = is_array($linesPayload['data'] ?? null) ? $linesPayload['data'] : [];
        $booking['_products'] = array_values(array_filter(array_map(
            fn($line) => (string) ($line['product']['backoffice_title'] ?? ''),
            $lines,
        )));
    }
    unset($booking);

    $synced         = $reservations->upsertMany($bookings);
    $latestPulledAt = $reservations->latestPulledAt() ?? date('Y-m-d H:i:s');

    fwrite(STDOUT, sprintf(
        "[%s] Synced %d reservation(s). Latest row update: %s\n",
        date('Y-m-d H:i:s'),
        $synced,
        $latestPulledAt
    ));
} catch (\Throwable $exception) {
    fwrite(STDERR, sprintf(
        "[%s] Failed to pull reservations: %s\n",
        date('Y-m-d H:i:s'),
        $exception->getMessage()
    ));
    exit(1);
}
