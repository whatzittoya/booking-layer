<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\PaymentController;
use App\Controllers\ReservationController;
use App\Models\AccessToken;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Payment;
use App\Models\Reservation;
use App\Services\BookingLayerBills;
use App\Services\BookingLayerReservations;
use App\Services\SchedulerService;
use DI\ContainerBuilder;
use GuzzleHttp\Client;
use Psr\Container\ContainerInterface;
use Slim\Flash\Messages;
use Slim\Views\Twig;

$rootPath = dirname(__DIR__);

$builder = new ContainerBuilder();

$builder->addDefinitions([
    'settings' => [
        'app' => [
            'header_label' => 'Restaurant Portal',
            'name' => $_ENV['APP_NAME'] ?? 'Quinos - Booking Layer',
            'header_title' => $_ENV['APP_NAME'] ?? 'Restaurant Portal',
            'base_path' => rtrim($_ENV['APP_BASE_PATH'] ?? '', '/'),
            'debug' => filter_var($_ENV['APP_DEBUG'] ?? true, FILTER_VALIDATE_BOOL),
        ],
        'db' => [
            'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
            'port' => $_ENV['DB_PORT'] ?? '3306',
            'database' => $_ENV['DB_DATABASE'] ?? 'db_arna',
            'username' => $_ENV['DB_USERNAME'] ?? 'root',
            'password' => $_ENV['DB_PASSWORD'] ?? 'Mysql123.',
            'charset' => $_ENV['DB_CHARSET'] ?? 'utf8',
        ],
        'bookinglayer' => [
            'base_url' => rtrim($_ENV['BOOKINGLAYER_BASE_URL'] ?? 'http://api.bookinglayer.io/private', '/'),
            'reservation_status' => $_ENV['BOOKINGLAYER_RESERVATION_STATUS'] ?? 'confirmed',
            'bill_currency' => $_ENV['BOOKINGLAYER_BILL_CURRENCY'] ?? 'IDR',
        ],
        'paths' => [
            'root' => $rootPath,
            'templates' => $rootPath . '/templates',
            'cache' => $rootPath . '/var/cache/twig',
        ],
    ],
    PDO::class => static function (ContainerInterface $container): PDO {
        $settings = $container->get('settings')['db'];
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $settings['host'],
            $settings['port'],
            $settings['database'],
            $settings['charset']
        );

        return new PDO(
            $dsn,
            $settings['username'],
            $settings['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    },
    Messages::class => static function (): Messages {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        return new Messages();
    },
    Client::class => static fn(): Client => new Client(['timeout' => 15]),
    Twig::class => static function (ContainerInterface $container): Twig {
        $settings = $container->get('settings');
        $twig = Twig::create($settings['paths']['templates'], [
            'cache' => false,
            'auto_reload' => true,
        ]);

        $twig->getEnvironment()->addGlobal('app_name', $settings['app']['name']);
        $twig->getEnvironment()->addGlobal('app_header_label', $settings['app']['header_label']);
        $twig->getEnvironment()->addGlobal('app_header_title', $settings['app']['header_title']);
        $twig->getEnvironment()->addGlobal('base_path', $settings['app']['base_path']);
        $twig->getEnvironment()->addGlobal('flash', $container->get(Messages::class));

        return $twig;
    },
    SchedulerService::class => static function (ContainerInterface $container): SchedulerService {
        $root = $container->get('settings')['paths']['root'];

        return new SchedulerService($root . '/bin/pull_reservations.php', $root, 'bookinglayer:pull_reservations');
    },
    'scheduler.payments' => static function (ContainerInterface $container): SchedulerService {
        $root = $container->get('settings')['paths']['root'];

        return new SchedulerService($root . '/bin/send_payments.php', $root, 'bookinglayer:send_payments');
    },
    BookingLayerBills::class => static function (ContainerInterface $container): BookingLayerBills {
        $bookinglayer = $container->get('settings')['bookinglayer'];

        return new BookingLayerBills(
            $container->get(Client::class),
            $bookinglayer['base_url'],
            $bookinglayer['bill_currency']
        );
    },
    BookingLayerReservations::class => static function (ContainerInterface $container): BookingLayerReservations {
        $bookinglayer = $container->get('settings')['bookinglayer'];

        return new BookingLayerReservations(
            $container->get(Client::class),
            $container->get(Reservation::class),
            $bookinglayer['base_url'],
            $bookinglayer['reservation_status']
        );
    },
    AccessToken::class => DI\autowire(AccessToken::class),
    Customer::class => DI\autowire(Customer::class),
    Employee::class => DI\autowire(Employee::class),
    Payment::class => DI\autowire(Payment::class),
    Reservation::class => DI\autowire(Reservation::class),
    AuthController::class => DI\autowire(AuthController::class)
        ->constructorParameter('settings', DI\get('settings')),
    PaymentController::class => DI\autowire(PaymentController::class)
        ->constructorParameter('accessTokens', DI\get(AccessToken::class))
        ->constructorParameter('client', DI\get(Client::class))
        ->constructorParameter('scheduler', DI\get('scheduler.payments'))
        ->constructorParameter('settings', DI\get('settings')),
    ReservationController::class => DI\autowire(ReservationController::class)
        ->constructorParameter('pullService', DI\get(BookingLayerReservations::class))
        ->constructorParameter('scheduler', DI\get(SchedulerService::class))
        ->constructorParameter('settings', DI\get('settings')),
]);

return $builder->build();
