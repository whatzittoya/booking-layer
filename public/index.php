<?php

declare(strict_types=1);

// Redirect to setup wizard if setup has not been completed yet.
if (!file_exists(dirname(__DIR__) . '/storage/setup.lock')) {
    $setupUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/setup.php';
    header('Location: ' . $setupUrl);
    exit;
}

use App\Middleware\SessionMiddleware;
use DI\Bridge\Slim\Bridge;
use Dotenv\Dotenv;
use Slim\Views\Twig;

require dirname(__DIR__) . '/vendor/autoload.php';

$rootPath = dirname(__DIR__);

if (file_exists($rootPath . '/.env')) {
    Dotenv::createImmutable($rootPath)->safeLoad();
}

if (($_ENV['APP_BASE_PATH'] ?? '') === '') {
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $scriptDir = rtrim(str_replace('/index.php', '', $scriptName), '/');

    if ($scriptDir !== '' && str_ends_with($scriptDir, '/public')) {
        $_ENV['APP_BASE_PATH'] = substr($scriptDir, 0, -7);
    } else {
        $_ENV['APP_BASE_PATH'] = $scriptDir;
    }
}

$container = require $rootPath . '/config/container.php';
$settings = $container->get('settings');

$app = Bridge::create($container);

$basePath = $settings['app']['base_path'];

if ($basePath !== '') {
    $app->setBasePath($basePath);
    $container->get(Twig::class)->getEnvironment()->addGlobal('base_path', $basePath);
}

$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->add(new SessionMiddleware());
$app->addErrorMiddleware(
    $settings['app']['debug'],
    true,
    true
);

(require $rootPath . '/config/routes.php')($app);

$app->run();
