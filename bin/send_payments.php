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

$unsentPayments = $payments->allUnsentClosed();

if ($unsentPayments === []) {
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] No closed unsent payments found.\n");
    exit(0);
}

$baseUrl = rtrim($settings['bookinglayer']['base_url'], '/');
$sent    = 0;
$failed  = 0;

foreach ($unsentPayments as $payment) {
    try {
        $bookingId = $payment['reservation_id'];
        $subtotal  = (float) ($payment['subtotal'] ?? 0);
        $amount    = (float) ($payment['amount'] ?? 0);
        $svcCharge = (float) ($payment['servicechargeamount'] ?? 0);
        $taxRate   = $subtotal > 0 ? round(($svcCharge / $subtotal) * 100, 2) : 0;

        $apiResponse = $client->request(
            'POST',
            $baseUrl . '/bookings/' . $bookingId . '/amendments',
            [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $accessToken['api_key'],
                ],
                'json' => [
                    'backoffice_title'     => 'Quinos - ' . $payment['id'],
                    'category'             => 'fee',
                    'qty'                  => 1,
                    'unit_price_excl_tax'  => $subtotal,
                    'unit_price_incl_tax'  => $amount,
                    'tax_rate'             => $taxRate,
                    'total_price_incl_tax' => $amount,
                ],
                'timeout' => 30,
            ]
        );

        json_decode((string) $apiResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $payments->markPostedToBookingLayer((int) $payment['id']);
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
