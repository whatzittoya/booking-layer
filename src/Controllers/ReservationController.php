<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AccessToken;
use App\Models\Customer;
use App\Models\Reservation;
use App\Services\BookingLayerReservations;
use App\Services\SchedulerService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Flash\Messages;
use Slim\Views\Twig;

class ReservationController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly Messages $flash,
        private readonly AccessToken $accessTokens,
        private readonly Customer $customers,
        private readonly Reservation $reservations,
        private readonly BookingLayerReservations $pullService,
        private readonly SchedulerService $scheduler,
        private readonly array $settings
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->user();

        if ($user === null) {
            return $this->redirect($response, '/login');
        }

        return $this->render($response, 'reservations/index.html.twig', [
            'page_title' => 'Reservations',
            'auth' => $user,
            'reservations' => $this->reservations->allCritical(),
            'last_pulled_at' => $this->reservations->latestPulledAt(),
            'scheduler' => $this->scheduler->getStatus(),
            'nav_section' => 'reservations',
        ]);
    }

    public function addCustomer(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
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

        if ($this->expectsJson($request)) {
            $queryParams = $request->getQueryParams();
            $search = trim((string) ($queryParams['guest_name'] ?? ''));

            return $this->json($response, [
                'success' => true,
                'data' => [
                    'customers' => $this->reservations->customerCandidates($search),
                ],
            ]);
        }

        return $this->render($response, 'customers/add.html.twig', [
            'page_title' => 'Add Customer',
            'auth' => $user,
            'customers' => [],
            'filters' => [
                'guest_name' => '',
            ],
            'nav_section' => 'customers',
        ]);
    }

    public function storeCustomer(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->user() === null) {
            return $this->json($response, [
                'success' => false,
                'message' => 'Authentication required.',
            ], 401);
        }

        $reservationId = (string) $request->getAttribute('reservationId', '');
        $reservation = $this->reservations->findByReservationId($reservationId);

        if ($reservation === null) {
            return $this->json($response, [
                'success' => false,
                'message' => 'Reservation was not found.',
            ], 404);
        }

        try {
            $this->customers->upsertFromReservation($reservation);

            return $this->json($response, [
                'success' => true,
                'message' => 'Customer saved successfully.',
            ]);
        } catch (\Throwable $exception) {
            return $this->json($response, [
                'success' => false,
                'message' => 'Failed to save customer: ' . $exception->getMessage(),
            ], 500);
        }
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

        $body = (array) ($request->getParsedBody() ?? []);
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

    public function pull(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->user() === null) {
            if ($this->expectsJson($request)) {
                return $this->json($response, [
                    'success' => false,
                    'message' => 'Authentication required.',
                ], 401);
            }

            return $this->redirect($response, '/login');
        }

        $accessToken = $this->accessTokens->latest();

        if ($accessToken === null) {
            $message = 'Booking Layer API token was not configured.';

            if ($this->expectsJson($request)) {
                return $this->json($response, [
                    'success' => false,
                    'message' => $message,
                ], 422);
            }

            $this->flash->addMessage('error', $message);

            return $this->redirect($response, '/reservations');
        }

        try {
            $result  = $this->pullService->pull((string) $accessToken['api_key']);
            $message = sprintf(
                'Synced %d active reservation(s) from %d booking(s) across %d page(s). Deactivated %d checked-out customer(s).',
                $result['synced'],
                $result['fetched'],
                $result['pages'],
                $result['deactivated']
            );

            if ($this->expectsJson($request)) {
                return $this->json($response, [
                    'success'     => true,
                    'message'     => $message,
                    'synced'      => $result['synced'],
                    'fetched'     => $result['fetched'],
                    'deactivated' => $result['deactivated'],
                ]);
            }

            $this->flash->addMessage('success', $message);
        } catch (\Throwable $exception) {
            $message = 'Failed to pull reservations from Booking Layer: ' . $exception->getMessage();

            if ($this->expectsJson($request)) {
                return $this->json($response, [
                    'success' => false,
                    'message' => $message,
                ], 500);
            }

            $this->flash->addMessage('error', $message);
        }

        return $this->redirect($response, '/reservations');
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
