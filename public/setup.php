<?php

declare(strict_types=1);

$rootPath  = dirname(__DIR__);
$envPath   = $rootPath . '/.env';
$envExPath = $rootPath . '/.env.example';
$vendorDir = $rootPath . '/vendor';
$lockFile  = $rootPath . '/storage/setup.lock';
$tmpOut    = sys_get_temp_dir() . '/bl_composer_out.txt';
$tmpPid    = sys_get_temp_dir() . '/bl_composer_pid.txt';
$appUrl    = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';

// ── Helpers ──────────────────────────────────────────────────────────────────

function parseEnvFile(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }
    $result = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$k, $v]        = explode('=', $line, 2);
        $result[trim($k)] = trim($v, '"\'');
    }
    return $result;
}

function connectPdo(array $env): array
{
    try {
        $host    = $env['DB_HOST']     ?? '127.0.0.1';
        $port    = $env['DB_PORT']     ?? '3306';
        $db      = $env['DB_DATABASE'] ?? '';
        $user    = $env['DB_USERNAME'] ?? '';
        $pass    = $env['DB_PASSWORD'] ?? '';
        $charset = $env['DB_CHARSET']  ?? 'utf8';
        $dsn     = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
        $pdo     = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        return [$pdo, null];
    } catch (Throwable $e) {
        return [null, $e->getMessage()];
    }
}

function findComposer(string $rootPath): ?string
{
    if (file_exists($rootPath . '/composer.phar')) {
        return 'php ' . escapeshellarg($rootPath . '/composer.phar');
    }
    foreach (['composer', '/usr/local/bin/composer', '/usr/bin/composer'] as $p) {
        exec($p . ' --version 2>&1', $o, $c);
        if ($c === 0) {
            return $p;
        }
    }
    return null;
}

function runMigrations(PDO $pdo): array
{
    $results = [];

    // Tables owned by this app
    $creates = [
        'Create access_token' => "
            CREATE TABLE IF NOT EXISTS `access_token` (
                `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `client_id`  VARCHAR(255) NOT NULL,
                `api_key`    TEXT NOT NULL,
                `item_id`    VARCHAR(32) DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
        ",
        'Create tbl_reservation_b_layer' => "
            CREATE TABLE IF NOT EXISTS `tbl_reservation_b_layer` (
                `id`                    VARCHAR(36) NOT NULL,
                `reference`             VARCHAR(50)    NOT NULL DEFAULT '',
                `starts_at`             DATETIME       NOT NULL,
                `ends_at`               DATETIME       NOT NULL,
                `status`                VARCHAR(50)    NOT NULL DEFAULT '',
                `booker_id`             VARCHAR(36)    NOT NULL DEFAULT '',
                `guest`                 VARCHAR(255)   NOT NULL DEFAULT '',
                `guest_gender`          VARCHAR(20)    NOT NULL DEFAULT '',
                `guest_age`             INT UNSIGNED   NULL     DEFAULT NULL,
                `guest_email`           VARCHAR(255)   NOT NULL DEFAULT '',
                `final_price_excl_tax`  DECIMAL(15,2)  NOT NULL DEFAULT 0.00,
                `final_price_incl_tax`  DECIMAL(15,2)  NOT NULL DEFAULT 0.00,
                `duration_in_days`      INT UNSIGNED   NOT NULL DEFAULT 0,
                `duration_in_nights`    INT UNSIGNED   NOT NULL DEFAULT 0,
                `product`               TEXT           NULL     DEFAULT NULL,
                `created_at`            TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`            DATETIME       NULL     DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_b_layer_status`    (`status`),
                KEY `idx_b_layer_booker_id` (`booker_id`),
                KEY `idx_b_layer_starts_at` (`starts_at`),
                KEY `idx_b_layer_ends_at`   (`ends_at`),
                KEY `idx_b_layer_reference` (`reference`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
        ",
    ];

    foreach ($creates as $label => $sql) {
        try {
            $pdo->exec(trim($sql));
            $results[] = ['label' => $label, 'ok' => true, 'note' => 'OK'];
        } catch (Throwable $e) {
            $results[] = ['label' => $label, 'ok' => false, 'note' => $e->getMessage()];
        }
    }

    // Ensure access_token.api_key is TEXT
    try {
        $colType = $pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'access_token'
               AND COLUMN_NAME  = 'api_key'"
        )->fetchColumn();
        if ($colType !== false && strtolower($colType) !== 'text') {
            $pdo->exec("ALTER TABLE `access_token` MODIFY COLUMN `api_key` TEXT NOT NULL");
            $results[] = ['label' => 'Alter access_token.api_key → TEXT', 'ok' => true, 'note' => "was {$colType}"];
        } else {
            $results[] = ['label' => 'Alter access_token.api_key → TEXT', 'ok' => true, 'note' => 'Already TEXT'];
        }
    } catch (Throwable $e) {
        $results[] = ['label' => 'Alter access_token.api_key → TEXT', 'ok' => false, 'note' => $e->getMessage()];
    }

    // tbl_customers: add reservation_id column (external table — skip if absent)
    try {
        $tableExists = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_customers'"
        )->fetchColumn();

        if ($tableExists === 0) {
            $results[] = ['label' => 'tbl_customers alterations', 'ok' => true, 'note' => 'Table not found — skipped'];
        } else {
            $colExists = (int) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = 'tbl_customers'
                   AND COLUMN_NAME  = 'reservation_id'"
            )->fetchColumn();

            if ($colExists === 0) {
                $pdo->exec("ALTER TABLE `tbl_customers` ADD COLUMN `reservation_id` VARCHAR(255) NULL");
                $results[] = ['label' => 'Add tbl_customers.reservation_id', 'ok' => true, 'note' => 'OK'];
            } else {
                $results[] = ['label' => 'Add tbl_customers.reservation_id', 'ok' => true, 'note' => 'Already exists'];
            }

            $idxExists = (int) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = 'tbl_customers'
                   AND INDEX_NAME   = 'uniq_tbl_customers_reservation_id'"
            )->fetchColumn();

            if ($idxExists > 0) {
                $pdo->exec("ALTER TABLE `tbl_customers` DROP INDEX `uniq_tbl_customers_reservation_id`");
                $results[] = ['label' => 'Drop tbl_customers unique index', 'ok' => true, 'note' => 'OK'];
            }
        }
    } catch (Throwable $e) {
        $results[] = ['label' => 'tbl_customers alterations', 'ok' => false, 'note' => $e->getMessage()];
    }

    return $results;
}

// ── AJAX handlers ─────────────────────────────────────────────────────────────

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');

    switch ($action) {

        // Save .env -----------------------------------------------------------
        case 'save_env':
            $fields = $_POST['fields'] ?? [];
            if (empty($fields) || !is_array($fields)) {
                echo json_encode(['ok' => false, 'error' => 'No fields provided.']);
                exit;
            }
            $lines = [];
            foreach ($fields as $k => $v) {
                $k = preg_replace('/[^A-Z0-9_]/', '', strtoupper((string) $k));
                if ($k === '') {
                    continue;
                }
                $v = (string) $v;
                if ($v === '' || preg_match('/\s/', $v)) {
                    $v = '"' . addslashes($v) . '"';
                }
                $lines[] = $k . '=' . $v;
            }
            $written = file_put_contents($envPath, implode("\n", $lines) . "\n");
            echo json_encode(
                $written !== false
                    ? ['ok' => true]
                    : ['ok' => false, 'error' => 'Cannot write .env — check directory permissions.']
            );
            exit;

        // Test DB connection --------------------------------------------------
        case 'test_db':
            $env = parseEnvFile($envPath);
            [$pdo, $err] = connectPdo($env);
            if ($pdo === null) {
                echo json_encode(['ok' => false, 'error' => $err]);
            } else {
                $ver = $pdo->query('SELECT VERSION()')->fetchColumn();
                echo json_encode(['ok' => true, 'version' => $ver]);
            }
            exit;

        // Run migrations ------------------------------------------------------
        case 'run_migrations':
            $env = parseEnvFile($envPath);
            [$pdo, $err] = connectPdo($env);
            if ($pdo === null) {
                echo json_encode(['ok' => false, 'error' => $err]);
                exit;
            }
            echo json_encode(['ok' => true, 'results' => runMigrations($pdo)]);
            exit;

        // Add client ----------------------------------------------------------
        case 'add_token':
            $clientId = trim((string) ($_POST['client_id'] ?? 'quinos'));
            $apiKey   = '';
            $itemId   = null;
            if ($clientId === '') {
                echo json_encode(['ok' => false, 'error' => 'client_id is required.']);
                exit;
            }
            $env = parseEnvFile($envPath);
            [$pdo, $err] = connectPdo($env);
            if ($pdo === null) {
                echo json_encode(['ok' => false, 'error' => $err]);
                exit;
            }
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO `access_token` (`client_id`, `api_key`, `item_id`, `updated_at`)
                     VALUES (?, ?, ?, CURRENT_TIMESTAMP)'
                );
                $stmt->execute([$clientId, $apiKey, $itemId]);
                echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
            } catch (Throwable $e) {
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            }
            exit;

        // List existing clients ----------------------------------------------
        case 'list_tokens':
            $env = parseEnvFile($envPath);
            [$pdo, $err] = connectPdo($env);
            if ($pdo === null) {
                echo json_encode(['ok' => false, 'error' => $err]);
                exit;
            }
            try {
                $rows = $pdo->query(
                    'SELECT `id`, `client_id`, `item_id`, `created_at` FROM `access_token` ORDER BY `id`'
                )->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['ok' => true, 'tokens' => $rows]);
            } catch (Throwable $e) {
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            }
            exit;

        // Start composer (background) -----------------------------------------
        case 'start_composer':
            if (!function_exists('exec')) {
                echo json_encode(['ok' => false, 'error' => 'exec() is disabled in php.ini.']);
                exit;
            }
            $cmd = findComposer($rootPath);
            if ($cmd === null) {
                echo json_encode([
                    'ok'    => false,
                    'error' => 'Composer not found. Install it globally or drop composer.phar in the project root.',
                ]);
                exit;
            }
            $subcmd = ($_POST['cmd'] ?? 'install') === 'update' ? 'update' : 'install';
            @unlink($tmpOut);
            @unlink($tmpPid);
            $full = $cmd . ' ' . $subcmd
                . ' --no-interaction'
                . ' --working-dir=' . escapeshellarg($rootPath)
                . ' > ' . escapeshellarg($tmpOut) . ' 2>&1 & echo $!';
            $pid = trim((string) shell_exec($full));
            file_put_contents($tmpPid, $pid);
            echo json_encode(['ok' => true, 'pid' => $pid]);
            exit;

        // Mark setup complete (write lock file) --------------------------------
        case 'mark_complete':
            $storageDir = dirname($lockFile);
            if (!is_dir($storageDir)) {
                @mkdir($storageDir, 0755, true);
            }
            $written = file_put_contents($lockFile, json_encode([
                'completed_at' => date('c'),
                'php'          => PHP_VERSION,
                'host'         => $_SERVER['HTTP_HOST'] ?? 'cli',
            ], JSON_PRETTY_PRINT) . "\n");
            echo json_encode(
                $written !== false
                    ? ['ok' => true]
                    : ['ok' => false, 'error' => 'Cannot write storage/setup.lock — check directory permissions.']
            );
            exit;

        // Poll composer output ------------------------------------------------
        case 'poll_composer':
            $offset  = max(0, (int) ($_GET['offset'] ?? 0));
            $content = file_exists($tmpOut) ? (string) file_get_contents($tmpOut) : '';
            $all     = $content === '' ? [] : explode("\n", $content);
            $chunk   = array_slice($all, $offset);

            $running = false;
            if (file_exists($tmpPid)) {
                $pid = trim((string) file_get_contents($tmpPid));
                if ($pid !== '' && is_numeric($pid)) {
                    exec("ps -p {$pid}", $psOut, $psCode);
                    $running = ($psCode === 0 && count($psOut) > 1);
                }
            }

            echo json_encode([
                'ok'      => true,
                'lines'   => $chunk,
                'offset'  => $offset + count($chunk),
                'running' => $running,
                'done'    => !$running,
            ]);
            exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
    exit;
}

// ── Page-level state (for rendering) ─────────────────────────────────────────

$phpOk   = version_compare(PHP_VERSION, '8.1.0', '>=');
$pdoOk   = extension_loaded('pdo_mysql');
$execOk  = function_exists('exec')
    && !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions') ?: '')));
$envExOk = file_exists($envExPath);
$envOk   = file_exists($envPath);
$vendorOk  = is_dir($vendorDir);
$setupDone = file_exists($lockFile);

$composerFound = false;
$composerVer   = '';
if ($execOk) {
    $composerBin = findComposer($rootPath);
    $composerFound = $composerBin !== null;
    if ($composerFound) {
        exec($composerBin . ' --version 2>&1', $cOut);
        $composerVer = $cOut[0] ?? $composerBin;
    }
}

// Merge .env.example defaults with actual .env values
$envFields = [];
if ($envExOk) {
    $envFields = array_merge(parseEnvFile($envExPath), parseEnvFile($envPath));
}

$allGreen = $phpOk && $pdoOk && $envOk && $vendorOk;
$lockData = $setupDone ? json_decode((string) file_get_contents($lockFile), true) : null;

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Setup — Quinos Booking Layer</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    pre.terminal {
      background: #0f172a; color: #cbd5e1;
      font-family: ui-monospace, 'Menlo', monospace; font-size: 0.78rem;
      line-height: 1.6; padding: 1rem; border-radius: 0.75rem;
      max-height: 340px; overflow-y: auto;
      white-space: pre-wrap; word-break: break-all;
    }
  </style>
</head>
<body class="bg-slate-50 min-h-screen text-slate-800 antialiased">

<div class="max-w-2xl mx-auto px-4 py-12">

  <!-- Header -->
  <div class="mb-10">
    <p class="text-xs font-semibold uppercase tracking-widest text-sky-600 mb-1">Quinos</p>
    <h1 class="text-3xl font-bold text-slate-900 tracking-tight">Booking Layer Setup</h1>
    <p class="mt-2 text-slate-500 text-sm">Follow the steps below to get the application running.</p>
  </div>

  <?php if ($setupDone): ?>
  <!-- Already-configured banner -->
  <div class="mb-6 flex items-center gap-3 rounded-xl bg-emerald-50 border border-emerald-200 px-5 py-4">
    <span class="text-emerald-500 text-xl">✓</span>
    <div>
      <p class="font-semibold text-emerald-800 text-sm">Setup already completed.</p>
      <p class="text-emerald-700 text-xs mt-0.5">
        Logged on <?= htmlspecialchars($lockData['completed_at'] ?? 'unknown') ?>.
        You can still update clients or re-run individual steps.
      </p>
    </div>
    <a href="<?= htmlspecialchars($appUrl) ?>" class="ml-auto shrink-0 rounded-lg bg-emerald-600 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-500 transition">Open App →</a>
  </div>
  <?php endif; ?>

  <!-- ── Step 1: System Requirements ─────────────────────────────────────── -->
  <section class="bg-white rounded-2xl border border-slate-200 p-6 mb-4">
    <h2 class="font-semibold text-slate-900 mb-4 flex items-center gap-2">
      <span class="flex items-center justify-center w-6 h-6 rounded-full bg-sky-100 text-sky-700 text-xs font-bold">1</span>
      System Requirements
    </h2>
    <ul class="space-y-2.5">
      <?php
        $reqs = [
            ['PHP ≥ 8.1',             $phpOk,          $phpOk  ? 'PHP ' . PHP_VERSION : 'Current: ' . PHP_VERSION . ' — upgrade required'],
            ['pdo_mysql extension',   $pdoOk,          $pdoOk  ? 'Loaded' : 'Missing — enable pdo_mysql in php.ini'],
            ['exec() enabled',        $execOk,         $execOk ? 'Available' : 'Disabled — remove exec from disable_functions'],
            ['Composer',              $composerFound,  $composerFound ? $composerVer : 'Not found — install globally or place composer.phar here'],
            ['.env.example present',  $envExOk,        $envExOk ? 'Found' : 'File missing from project root'],
        ];
        foreach ($reqs as [$label, $ok, $note]):
            $icon = $ok ? '✓' : '✗';
            $ic   = $ok ? 'text-emerald-500' : 'text-red-500';
            $nc   = $ok ? 'text-slate-400'   : 'text-red-400';
      ?>
      <li class="flex items-center gap-3 text-sm">
        <span class="font-mono font-bold <?= $ic ?> w-4 shrink-0"><?= $icon ?></span>
        <span class="w-44 shrink-0 text-slate-700"><?= htmlspecialchars($label) ?></span>
        <span class="<?= $nc ?> text-xs"><?= htmlspecialchars($note) ?></span>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php if (!$phpOk || !$pdoOk): ?>
    <div class="mt-4 rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
      Critical requirements not met. Fix the items above before continuing.
    </div>
    <?php endif; ?>
  </section>

  <!-- ── Step 2: Environment ──────────────────────────────────────────────── -->
  <section class="bg-white rounded-2xl border border-slate-200 p-6 mb-4" id="step-env">
    <div class="flex items-center justify-between mb-4">
      <h2 class="font-semibold text-slate-900 flex items-center gap-2">
        <span class="flex items-center justify-center w-6 h-6 rounded-full bg-sky-100 text-sky-700 text-xs font-bold">2</span>
        Environment Configuration
      </h2>
      <?php if ($envOk): ?>
      <span class="text-xs bg-emerald-100 text-emerald-700 font-semibold px-2.5 py-1 rounded-full">.env exists</span>
      <?php else: ?>
      <span class="text-xs bg-amber-100 text-amber-700 font-semibold px-2.5 py-1 rounded-full">No .env yet</span>
      <?php endif; ?>
    </div>

    <form id="env-form" class="space-y-5">
      <?php
        $groups = [
            'Application' => [
                'APP_NAME'    => ['Application name',       'text',     'Quinos - Booking Layer'],
                'APP_BASE_PATH' => ['Base path (leave empty for root)', 'text', '/booking-layer'],
                'APP_ENV'     => ['Environment',            'text',     'local'],
                'APP_DEBUG'   => ['Debug mode',             'text',     'true'],
            ],
            'Database' => [
                'DB_HOST'     => ['Host',       'text',     '127.0.0.1'],
                'DB_PORT'     => ['Port',       'text',     '3306'],
                'DB_DATABASE' => ['Database',   'text',     ''],
                'DB_USERNAME' => ['Username',   'text',     'root'],
                'DB_PASSWORD' => ['Password',   'password', ''],
                'DB_CHARSET'  => ['Charset',    'text',     'utf8'],
            ],
            'BookingLayer API' => [
                'BOOKINGLAYER_BASE_URL'             => ['API Base URL',          'text', 'https://api.bookinglayer.io/private/'],
                'BOOKINGLAYER_RESERVATION_STATUS'   => ['Reservation status filter', 'text', 'confirmed'],
            ],
        ];
        foreach ($groups as $groupLabel => $fields):
      ?>
      <div>
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2"><?= htmlspecialchars($groupLabel) ?></p>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <?php foreach ($fields as $key => [$hint, $type, $placeholder]):
            $val = $envFields[$key] ?? '';
          ?>
          <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">
              <?= htmlspecialchars($key) ?>
              <?php if ($hint): ?><span class="text-slate-400 font-normal"> — <?= htmlspecialchars($hint) ?></span><?php endif; ?>
            </label>
            <input
              type="<?= $type ?>"
              name="fields[<?= htmlspecialchars($key) ?>]"
              value="<?= htmlspecialchars($val) ?>"
              placeholder="<?= htmlspecialchars($placeholder) ?>"
              class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-sky-400"
            >
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>

      <div class="flex items-center gap-3 pt-1">
        <button type="submit" class="rounded-lg bg-sky-600 px-5 py-2 text-sm font-semibold text-white hover:bg-sky-500 transition">
          Save .env
        </button>
        <span id="env-msg" class="text-sm"></span>
      </div>
    </form>
  </section>

  <!-- ── Step 3: Database ─────────────────────────────────────────────────── -->
  <section class="bg-white rounded-2xl border border-slate-200 p-6 mb-4">
    <h2 class="font-semibold text-slate-900 mb-4 flex items-center gap-2">
      <span class="flex items-center justify-center w-6 h-6 rounded-full bg-sky-100 text-sky-700 text-xs font-bold">3</span>
      Database Setup
    </h2>
    <p class="text-sm text-slate-500 mb-4">Test the connection first, then run migrations to create or update the required tables.</p>
    <div class="flex flex-wrap gap-3 mb-4">
      <button onclick="testDb()" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 transition">
        Test Connection
      </button>
      <button onclick="runMigrations()" class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-500 transition">
        Run Migrations
      </button>
    </div>
    <p id="db-msg" class="text-sm text-slate-500"></p>
    <ul id="migration-list" class="mt-3 space-y-1.5 text-sm"></ul>
  </section>

  <!-- ── Step 4: Client ID ────────────────────────────────────────────────── -->
  <section class="bg-white rounded-2xl border border-slate-200 p-6 mb-4">
    <h2 class="font-semibold text-slate-900 mb-1 flex items-center gap-2">
      <span class="flex items-center justify-center w-6 h-6 rounded-full bg-sky-100 text-sky-700 text-xs font-bold">4</span>
      Client ID
    </h2>
    <p class="text-sm text-slate-500 mb-4 ml-8">Set the client identifier used by this installation.</p>

    <div id="token-list" class="ml-8 mb-4"></div>

    <form id="token-form" class="ml-8 grid grid-cols-1 gap-3 sm:grid-cols-2">
      <div>
        <label class="block text-xs font-medium text-slate-600 mb-1">client_id <span class="text-red-400">*</span></label>
        <input id="tk-client" type="text" value="quinos" placeholder="quinos"
          class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-sky-400">
      </div>
      <div class="sm:col-span-2 flex items-center gap-3">
        <button type="submit" class="rounded-lg bg-sky-600 px-5 py-2 text-sm font-semibold text-white hover:bg-sky-500 transition">
          Save Client
        </button>
        <span id="token-msg" class="text-sm"></span>
      </div>
    </form>
  </section>

  <!-- ── Step 5: Install Dependencies ─────────────────────────────────────── -->
  <section class="bg-white rounded-2xl border border-slate-200 p-6 mb-4">
    <div class="flex items-center justify-between mb-1">
      <h2 class="font-semibold text-slate-900 flex items-center gap-2">
        <span class="flex items-center justify-center w-6 h-6 rounded-full bg-sky-100 text-sky-700 text-xs font-bold">5</span>
        Install Dependencies
      </h2>
      <?php if ($vendorOk): ?>
      <span class="text-xs bg-emerald-100 text-emerald-700 font-semibold px-2.5 py-1 rounded-full">vendor/ present</span>
      <?php elseif ($setupDone): ?>
      <span class="text-xs bg-amber-100 text-amber-700 font-semibold px-2.5 py-1 rounded-full">vendor/ missing</span>
      <?php endif; ?>
    </div>
    <p class="text-sm text-slate-500 mb-4 ml-8">
      Runs <code class="bg-slate-100 px-1.5 py-0.5 rounded text-xs font-mono">composer install</code> in the project root.
      <?php if (!$composerFound): ?>
        <br><span class="text-amber-600">Composer not found — install it globally or drop <code class="bg-slate-100 px-1 rounded text-xs">composer.phar</code> in the project root, then refresh.</span>
      <?php endif; ?>
    </p>
    <div class="flex gap-3 ml-8 mb-4">
      <button
        id="btn-install"
        onclick="startComposer('install')"
        <?= !$composerFound ? 'disabled' : '' ?>
        class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-500 transition disabled:opacity-40 disabled:cursor-not-allowed">
        composer install
      </button>
      <button
        id="btn-update"
        onclick="startComposer('update')"
        <?= !$composerFound ? 'disabled' : '' ?>
        class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 transition disabled:opacity-40 disabled:cursor-not-allowed">
        composer update
      </button>
    </div>
    <pre class="terminal hidden ml-8" id="composer-out"></pre>
    <p id="composer-msg" class="text-sm text-slate-500 mt-2 ml-8"></p>
  </section>

  <!-- ── Finish Setup ─────────────────────────────────────────────────────── -->
  <section class="bg-white rounded-2xl border border-slate-200 p-6 mb-4">
    <h2 class="font-semibold text-slate-900 mb-1 flex items-center gap-2">
      <span class="flex items-center justify-center w-6 h-6 rounded-full bg-sky-100 text-sky-700 text-xs font-bold">6</span>
      Finish Setup
    </h2>
    <p class="text-sm text-slate-500 mb-4 ml-8">
      Click the button below once all steps above are done. This writes
      <code class="bg-slate-100 px-1.5 py-0.5 rounded text-xs font-mono">storage/setup.lock</code>
      as a timestamped record that setup has been completed.
      <?php if ($setupDone): ?>
      <br><span class="text-emerald-600 font-medium">Lock file already exists — setup was previously completed.</span>
      <?php endif; ?>
    </p>
    <div class="ml-8 flex items-center gap-3">
      <button onclick="markComplete()" class="rounded-lg bg-emerald-600 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-500 transition">
        <?= $setupDone ? 'Re-mark as Complete' : 'Mark Setup Complete' ?>
      </button>
      <?php if ($setupDone): ?>
      <a href="<?= htmlspecialchars($appUrl) ?>" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 transition">
        Open App →
      </a>
      <?php endif; ?>
      <span id="complete-msg" class="text-sm"></span>
    </div>
  </section>

  <!-- Footer -->
  <p class="text-center text-xs text-slate-400 mt-6">
    Restrict or delete <code class="bg-slate-100 px-1 rounded">setup.php</code> after installation.
  </p>

</div>

<script>
// ── Utilities ─────────────────────────────────────────────────────────────────
function apiPost(action, body) {
  return fetch('?action=' + action, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams(body),
  }).then(r => r.json());
}
function apiGet(action, params) {
  return fetch('?' + new URLSearchParams({ action, ...params })).then(r => r.json());
}
function msg(id, ok, text) {
  const el = document.getElementById(id);
  el.textContent = text;
  el.className = 'text-sm ' + (ok ? 'text-emerald-600' : 'text-red-500');
}

// ── Step 2: Save .env ─────────────────────────────────────────────────────────
document.getElementById('env-form').addEventListener('submit', async e => {
  e.preventDefault();
  msg('env-msg', true, 'Saving…');
  const res = await fetch('?action=save_env', { method: 'POST', body: new FormData(e.target) });
  const json = await res.json();
  msg('env-msg', json.ok, json.ok ? '.env saved ✓' : 'Error: ' + json.error);
});

// ── Step 3: Database ──────────────────────────────────────────────────────────
async function testDb() {
  msg('db-msg', true, 'Connecting…');
  const json = await apiGet('test_db', {});
  msg('db-msg', json.ok, json.ok ? 'Connected ✓  MySQL ' + json.version : 'Error: ' + json.error);
}

async function runMigrations() {
  msg('db-msg', true, 'Running migrations…');
  document.getElementById('migration-list').innerHTML = '';
  const json = await apiGet('run_migrations', {});
  if (!json.ok) { msg('db-msg', false, 'Error: ' + json.error); return; }
  msg('db-msg', true, 'Migrations complete ✓');
  const ul = document.getElementById('migration-list');
  json.results.forEach(r => {
    const li = document.createElement('li');
    li.className = 'flex items-start gap-2';
    li.innerHTML =
      `<span class="font-mono font-bold shrink-0 ${r.ok ? 'text-emerald-500' : 'text-red-500'}">${r.ok ? '✓' : '✗'}</span>` +
      `<span class="text-slate-700">${r.label}</span>` +
      `<span class="text-xs text-slate-400 ml-auto shrink-0">${r.note}</span>`;
    ul.appendChild(li);
  });
  loadTokens();
}

// ── Step 4: Clients ───────────────────────────────────────────────────────────
async function loadTokens() {
  const json = await apiGet('list_tokens', {});
  const el = document.getElementById('token-list');
  if (!json.ok || !json.tokens || json.tokens.length === 0) { el.innerHTML = ''; return; }
  el.innerHTML = '<p class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Existing clients</p>';
  const ul = document.createElement('ul');
  ul.className = 'space-y-1.5 mb-2';
  json.tokens.forEach(t => {
    const li = document.createElement('li');
    li.className = 'flex items-center gap-2 text-sm bg-slate-50 border border-slate-100 rounded-lg px-3 py-2';
    li.innerHTML =
      `<span class="font-mono text-sky-600 font-semibold text-xs">#${t.id}</span>` +
      `<span class="font-medium">${t.client_id}</span>` +
      (t.item_id ? `<span class="text-xs text-slate-400">item: ${t.item_id}</span>` : '') +
      `<span class="ml-auto text-xs text-slate-400">${t.created_at}</span>`;
    ul.appendChild(li);
  });
  el.appendChild(ul);
}
loadTokens();

document.getElementById('token-form').addEventListener('submit', async e => {
  e.preventDefault();
  const clientId = document.getElementById('tk-client').value.trim();
  if (!clientId) { msg('token-msg', false, 'client_id is required.'); return; }
  msg('token-msg', true, 'Saving…');
  const json = await apiPost('add_token', { client_id: clientId });
  msg('token-msg', json.ok, json.ok ? 'Client saved ✓  (id: ' + json.id + ')' : 'Error: ' + json.error);
  if (json.ok) {
    document.getElementById('tk-client').value = 'quinos';
    loadTokens();
  }
});

// ── Step 5: Composer ──────────────────────────────────────────────────────────
let _pollTimer = null;
let _pollOffset = 0;

async function startComposer(subcmd) {
  const out = document.getElementById('composer-out');
  out.textContent = '';
  out.classList.remove('hidden');
  msg('composer-msg', true, 'Starting composer ' + subcmd + '…');
  document.getElementById('btn-install').disabled = true;
  document.getElementById('btn-update').disabled  = true;

  const json = await apiPost('start_composer', { cmd: subcmd });
  if (!json.ok) {
    msg('composer-msg', false, 'Error: ' + json.error);
    document.getElementById('btn-install').disabled = false;
    document.getElementById('btn-update').disabled  = false;
    return;
  }

  _pollOffset = 0;
  clearInterval(_pollTimer);
  _pollTimer = setInterval(pollComposer, 700);
}

async function pollComposer() {
  const json = await apiGet('poll_composer', { offset: _pollOffset });
  if (!json.ok) { clearInterval(_pollTimer); return; }

  if (json.lines && json.lines.length) {
    const out = document.getElementById('composer-out');
    out.textContent += json.lines.join('\n') + '\n';
    out.scrollTop = out.scrollHeight;
  }
  _pollOffset = json.offset;

  if (json.done) {
    clearInterval(_pollTimer);
    msg('composer-msg', true, 'Done ✓  Reloading…');
    setTimeout(() => location.reload(), 2500);
  }
}

// ── Step 6: Mark complete ──────────────────────────────────────────────────────
async function markComplete() {
  msg('complete-msg', true, 'Writing lock file…');
  const json = await apiPost('mark_complete', {});
  msg('complete-msg', json.ok, json.ok ? 'setup.lock written ✓  Reloading…' : 'Error: ' + json.error);
  if (json.ok) setTimeout(() => location.reload(), 1500);
}
</script>
</body>
</html>
