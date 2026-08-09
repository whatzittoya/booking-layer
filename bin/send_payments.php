<?php

declare(strict_types=1);

use App\Models\AccessToken;
use App\Models\Customer;
use App\Models\Payment;
use App\Services\BookingLayerBills;
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
/** @var Customer $customers */
$customers = $container->get(Customer::class);
/** @var BookingLayerBills $bills */
$bills = $container->get(BookingLayerBills::class);
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
        $customerId = (int) ($payment['customer_table_id'] ?? 0);
        $bookingId  = (string) ($payment['reservation_id'] ?? '');

        if ($customerId === 0 || $bookingId === '') {
            throw new RuntimeException('Payment is not linked to a customer with a reservation.');
        }

        // Amendments carry no currency, so POS charges go to a bill created in
        // the POS currency. One bill per customer, reused across checks.
        $billId = $customers->billId($customerId);

        if ($billId === null) {
            $billId = $bills->create($bookingId, (string) $accessToken['api_key']);
            $customers->saveBillId($customerId, $billId);

            fwrite(STDOUT, sprintf(
                "[%s] Created %s bill %s for booking %s.\n",
                date('Y-m-d H:i:s'),
                $bills->currency(),
                $billId,
                $bookingId
            ));
        }

        // The POS total already includes service charge and tax, so it is posted
        // as a flat amount with no tax broken out. See PaymentController::send().
        $amount = (float) ($payment['amount'] ?? 0);

        $apiResponse = $client->request(
            'POST',
            $baseUrl . '/bookings/' . $billId . '/amendments',
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
                    'total_price_incl_tax' => $amount,
                    'tax_rate'             => 0,
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
