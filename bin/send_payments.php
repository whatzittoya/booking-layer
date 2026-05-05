<?php

declare(strict_types=1);

use App\Models\AccessToken;
use App\Models\Payment;
use Dotenv\Dotenv;
use GuzzleHttp\Client;

require dirname(__DIR__) . '/vendor/autoload.php';

$rootPath = dirname(__DIR__);

if (file_exists($rootPath . '/.env')) {
    Dotenv::createImmutable($rootPath)->safeLoad();
}

$container = require $rootPath . '/config/container.php';
$settings  = $container->get('settings');

/** @var AccessToken $accessTokens */
$accessTokens = $container->get(AccessToken::class);
/** @var Payment $payments */
$payments = $container->get(Payment::class);
/** @var Client $client */
$client = $container->get(Client::class);

$accessToken = $accessTokens->latest();

if ($accessToken === null) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] Booking Layer API token was not found in access_token.\n");
    exit(1);
}

if (($accessToken['item_id'] ?? '') === '') {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] Booking Layer item ID was not configured.\n");
    exit(1);
}

$unsentPayments = $payments->allUnsentClosed();

if ($unsentPayments === []) {
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] No closed unsent payments found.\n");
    exit(0);
}

// TODO: update endpoint path, content-type, and body params to match Booking Layer API
$baseUrl    = rtrim($settings['bookinglayer']['base_url'], '/');
$sent       = 0;
$failed     = 0;

foreach ($unsentPayments as $payment) {
    try {
        $apiResponse = $client->request('POST', $baseUrl . '/postItem', [
            'headers' => [
                'accept'        => 'application/json',
                'content-type'  => 'application/x-www-form-urlencoded',
                'Authorization' => 'Bearer ' . $accessToken['api_key'],
            ],
            'form_params' => [
                'reservationID' => $payment['reservation_id'],
                'propertyID'    => $payment['property_id'],
                'itemID'        => $accessToken['item_id'],
                'itemQuantity'  => 1,
                'itemPrice'     => $payment['amount'],
                'itemNote'      => (string) $payment['id'],
            ],
            'timeout' => 30,
        ]);

        $payload = json_decode((string) $apiResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($payload) || !($payload['success'] ?? false)) {
            fwrite(STDERR, sprintf(
                "[%s] Booking Layer rejected payment #%d: %s\n",
                date('Y-m-d H:i:s'),
                $payment['id'],
                $payload['message'] ?? 'unknown error',
            ));
            $failed++;
            continue;
        }

        $payments->markPostedToBookingLayer($payment['id'], (int) $payment['customer_table_id']);
        $sent++;

        fwrite(STDOUT, sprintf(
            "[%s] Sent payment #%d (reservation %s, amount %s).\n",
            date('Y-m-d H:i:s'),
            $payment['id'],
            $payment['reservation_id'],
            $payment['amount'],
        ));
    } catch (\Throwable $exception) {
        fwrite(STDERR, sprintf(
            "[%s] Failed to send payment #%d: %s\n",
            date('Y-m-d H:i:s'),
            $payment['id'],
            $exception->getMessage(),
        ));
        $failed++;
    }
}

fwrite(STDOUT, sprintf(
    "[%s] Done. Sent: %d, Failed: %d.\n",
    date('Y-m-d H:i:s'),
    $sent,
    $failed,
));

exit($failed > 0 ? 1 : 0);
