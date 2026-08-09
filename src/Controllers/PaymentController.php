<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AccessToken;
use App\Models\Customer;
use App\Models\Payment;
use App\Services\BookingLayerBills;
use App\Services\SchedulerService;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

class PaymentController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly AccessToken $accessTokens,
        private readonly Client $client,
        private readonly Payment $payments,
        private readonly Customer $customers,
        private readonly BookingLayerBills $bills,
        private readonly SchedulerService $scheduler,
        private readonly array $settings
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->user();

        if ($user === null) {
            if ($this->expectsJson($request)) {
                return $this->json($response, [
                    'success' => false,
                    'message' => 'Authentication required.',
                ], 401);
            }

            return $this->redirect($response, '/login');
        }

        $payments = $this->payments->allWithCustomerDetails();

        if ($this->expectsJson($request)) {
            return $this->json($response, [
                'success' => true,
                'data' => [
                    'payments' => $payments,
                ],
            ]);
        }

        return $this->render($response, 'payments/index.html.twig', [
            'page_title' => 'Payments',
            'auth' => $user,
            'payments' => $payments,
            'scheduler' => $this->scheduler->getStatus(),
            'nav_section' => 'payments',
        ]);
    }

    public function getScheduler(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->user() === null) {
            return $this->json($response, ['success' => false, 'message' => 'Authentication required.'], 401);
        }

        return $this->json($response, [
            'success' => true,
            'data' => $this->scheduler->getStatus(),
        ]);
    }

    public function updateScheduler(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->user() === null) {
            return $this->json($response, ['success' => false, 'message' => 'Authentication required.'], 401);
        }

        $body    = (array) ($request->getParsedBody() ?? []);
        $enabled = filter_var($body['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $schedule = trim((string) ($body['schedule'] ?? ''));

        try {
            if (!$enabled) {
                $this->scheduler->disable();

                return $this->json($response, [
                    'success' => true,
                    'message' => 'Scheduler disabled.',
                    'data' => $this->scheduler->getStatus(),
                ]);
            }

            if ($schedule === '') {
                return $this->json($response, [
                    'success' => false,
                    'message' => 'Schedule is required to enable the scheduler.',
                ], 422);
            }

            if (SchedulerService::scheduleToExpression($schedule) === null) {
                return $this->json($response, [
                    'success' => false,
                    'message' => 'Invalid schedule value.',
                ], 422);
            }

            $this->scheduler->enable($schedule);

            return $this->json($response, [
                'success' => true,
                'message' => 'Scheduler enabled.',
                'data' => $this->scheduler->getStatus(),
            ]);
        } catch (\Throwable $e) {
            return $this->json($response, [
                'success' => false,
                'message' => 'Failed to update scheduler: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function send(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->user() === null) {
            return $this->json($response, [
                'success' => false,
                'message' => 'Authentication required.',
            ], 401);
        }

        $paymentId = (int) $request->getAttribute('paymentId', 0);
        $payment = $this->payments->findById($paymentId);

        if ($payment === null) {
            return $this->json($response, [
                'success' => false,
                'message' => 'Payment was not found.',
            ], 404);
        }

        // The list query hides sent payments, but a stale page could still post
        // one twice — and this endpoint writes to a guest's bill.
        if ((int) ($payment['trobex'] ?? 0) === 1) {
            return $this->json($response, [
                'success' => false,
                'message' => 'Payment was already sent to Booking Layer.',
            ], 409);
        }

        $accessToken = $this->accessTokens->latest();

        if ($accessToken === null) {
            return $this->json($response, [
                'success' => false,
                'message' => 'Booking Layer API token was not configured.',
            ], 422);
        }

        $customerId = (int) ($payment['customer_table_id'] ?? 0);
        $bookingId  = (string) ($payment['reservation_id'] ?? '');

        if ($customerId === 0 || $bookingId === '') {
            return $this->json($response, [
                'success' => false,
                'message' => 'Payment is not linked to a customer with a reservation.',
            ], 422);
        }

        try {
            $baseUrl  = rtrim($this->settings['bookinglayer']['base_url'], '/');

            // Amendments carry no currency, so POS charges go to a bill created
            // in the POS currency. One bill per customer, reused across checks.
            $billId = $this->customers->billId($customerId);

            if ($billId === null) {
                $billId = $this->bills->create($bookingId, (string) $accessToken['api_key']);
                $this->customers->saveBillId($customerId, $billId);
            }

            // The POS total already includes service charge and tax, so it is
            // posted as a flat amount with no tax broken out. Sending only
            // total_price_incl_tax is deliberate: the spec accepts one of the
            // three price fields, and at qty 1 this one fully determines the
            // charge. subtotal + servicechargeamount does NOT equal amount here
            // (the POS stacks service charge and tax), so deriving a tax_rate
            // from them understates the charge by roughly 9%.
            $amount = (float) ($payment['amount'] ?? 0);

            $apiResponse = $this->client->request(
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

            $payload = json_decode((string) $apiResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);

            $this->payments->markPostedToBookingLayer($paymentId);

            return $this->json($response, [
                'success' => true,
                'message' => 'Payment sent to Booking Layer.',
                'data'    => $payload['data'] ?? $payload,
            ]);
        } catch (\Throwable $exception) {
            return $this->json($response, [
                'success' => false,
                'message' => 'Failed to send payment to Booking Layer: ' . $exception->getMessage(),
            ], 500);
        }
    }

    private function render(ResponseInterface $response, string $template, array $data = []): ResponseInterface
    {
        $defaults = [
            'auth' => $this->user(),
            'show_nav' => true,
        ];

        return $this->twig->render($response, $template, array_merge($defaults, $data));
    }

    private function redirect(ResponseInterface $response, string $path): ResponseInterface
    {
        return $response
            ->withHeader('Location', $this->url($path))
            ->withStatus(302);
    }

    private function url(string $path): string
    {
        $basePath = $this->settings['app']['base_path'];
        $normalizedPath = '/' . ltrim($path, '/');

        return ($basePath === '' ? '' : $basePath) . $normalizedPath;
    }

    private function user(): ?array
    {
        return $_SESSION['employee'] ?? null;
    }

    private function expectsJson(ServerRequestInterface $request): bool
    {
        $requestedWith = strtolower($request->getHeaderLine('X-Requested-With'));
        $accept = strtolower($request->getHeaderLine('Accept'));

        return $requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json');
    }

    private function json(ResponseInterface $response, array $payload, int $status = 200): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload, JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
