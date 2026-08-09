<?php

declare(strict_types=1);

use App\Models\AccessToken;
use App\Models\Reservation;
use App\Services\BookingLayerReservations;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$rootPath = dirname(__DIR__);

if (file_exists($rootPath . '/.env')) {
    Dotenv::createImmutable($rootPath)->safeLoad();
}

$container = require $rootPath . '/config/container.php';

/** @var AccessToken $accessTokens */
$accessTokens = $container->get(AccessToken::class);
/** @var Reservation $reservations */
$reservations = $container->get(Reservation::class);
/** @var BookingLayerReservations $pull */
$pull = $container->get(BookingLayerReservations::class);

$accessToken = $accessTokens->latest();

if ($accessToken === null) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] Booking Layer API token was not found in access_token.\n");
    exit(1);
}

try {
    $result         = $pull->pull((string) $accessToken['api_key']);
    $latestPulledAt = $reservations->latestPulledAt() ?? date('Y-m-d H:i:s');

    fwrite(STDOUT, sprintf(
        "[%s] Fetched %d booking(s) across %d page(s); %d active. Synced %d. Deactivated %d checked-out customer(s). Latest row update: %s\n",
        date('Y-m-d H:i:s'),
        $result['fetched'],
        $result['pages'],
        $result['active'],
        $result['synced'],
        $result['deactivated'],
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
