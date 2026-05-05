<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AccessToken;
use App\Models\Payment;
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

        $accessToken = $this->accessTokens->latest();

        if ($accessToken === null) {
            return $this->json($response, [
                'success' => false,
                'message' => 'Booking Layer API token was not configured.',
            ], 422);
        }

        if (($accessToken['item_id'] ?? '') === '') {
            return $this->json($response, [
                'success' => false,
                'message' => 'Booking Layer item ID was not configured.',
            ], 422);
        }

        if (($payment['reservation_id'] ?? '') === '' || ($payment['property_id'] ?? '') === '') {
            return $this->json($response, [
                'success' => false,
                'message' => 'Payment is missing reservation ID or property ID.',
            ], 422);
        }

        try {
            // TODO: update endpoint path, content-type, and body params to match Booking Layer API
            $apiResponse = $this->client->request('POST', rtrim($this->settings['bookinglayer']['base_url'], '/') . '/postItem', [
                'headers' => [
                    'accept' => 'application/json',
                    'content-type' => 'application/x-www-form-urlencoded',
                    'Authorization' => 'Bearer ' . $accessToken['api_key'],
                ],
                'form_params' => [
                    'reservationID' => $payment['reservation_id'],
                    'propertyID' => $payment['property_id'],
                    'itemID' => $accessToken['item_id'],
                    'itemQuantity' => 1,
                    'itemPrice' => $payment['amount'],
                    'itemNote' => (string) $payment['id'],
                ],
                'timeout' => 30,
            ]);

            $payload = json_decode((string) $apiResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($payload) || !($payload['success'] ?? false)) {
                return $this->json($response, [
                    'success' => false,
                    'message' => 'Booking Layer rejected the payment item.',
                ], 502);
            }

            $this->payments->markPostedToBookingLayer($paymentId, isset($payment['customer_table_id']) ? (int) $payment['customer_table_id'] : null);

            return $this->json($response, [
                'success' => true,
                'message' => 'Payment sent to Booking Layer.',
                'data' => $payload['data'] ?? null,
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
