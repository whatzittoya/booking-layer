<?php

declare(strict_types=1);

namespace App\Services;

class SchedulerService
{
    private string $varDir;
    private string $taskName;

    public function __construct(
        private readonly string $scriptPath,
        string $rootPath,
        string $tag = 'quinos:pull_reservations',
    ) {
        $this->varDir   = $rootPath . '/var/scheduler';
        $this->taskName = str_replace(':', '_', $tag);

        if (!is_dir($this->varDir)) {
            mkdir($this->varDir, 0755, true);
        }
    }

    public function getStatus(): array
    {
        if (!$this->taskExists()) {
            return ['enabled' => false, 'expression' => null, 'schedule' => null];
        }

        $schedule = $this->loadState();

        return [
            'enabled'    => true,
            'expression' => self::scheduleToExpression($schedule ?? ''),
            'schedule'   => $schedule,
        ];
    }

    public function enable(string $schedule): void
    {
        if (self::scheduleToExpression($schedule) === null) {
            throw new \InvalidArgumentException("Invalid schedule: $schedule");
        }

        $batPath = $this->ensureBatFile();
        $args    = $this->scheduleToSchtasksArgs($schedule);
        $cmd     = sprintf(
            'schtasks /create /tn %s /tr %s /sc %s%s%s /ru SYSTEM /f 2>&1',
            escapeshellarg($this->taskName),
            escapeshellarg($batPath),
            $args['sc'],
            isset($args['mo']) ? ' /mo ' . $args['mo'] : '',
            isset($args['st']) ? ' /st ' . $args['st'] : '',
        );

        exec($cmd, $outputLines, $exitCode);
        $output = implode("\n", $outputLines);

        if ($exitCode !== 0) {
            throw new \RuntimeException('schtasks /create failed: ' . trim($output));
        }

        $this->saveState($schedule);
    }

    public function disable(): void
    {
        $cmd = 'schtasks /delete /tn ' . escapeshellarg($this->taskName) . ' /f 2>&1';
        exec($cmd);
        $this->deleteState();
    }

    public static function scheduleToExpression(string $schedule): ?string
    {
        $map = [
            'every_5min'    => '*/5 * * * *',
            'every_15min'   => '*/15 * * * *',
            'every_30min'   => '*/30 * * * *',
            'every_hour'    => '0 * * * *',
            'every_2hours'  => '0 */2 * * *',
            'every_6hours'  => '0 */6 * * *',
            'every_12hours' => '0 */12 * * *',
        ];

        if (isset($map[$schedule])) {
            return $map[$schedule];
        }

        if (preg_match('/^daily_(\d{1,2}):(\d{2})$/', $schedule)) {
            return $schedule;
        }

        return null;
    }

    private function taskExists(): bool
    {
        exec('schtasks /query /tn ' . escapeshellarg($this->taskName) . ' /fo LIST 2>&1', $lines, $exitCode);

        return $exitCode === 0;
    }

    private function scheduleToSchtasksArgs(string $schedule): array
    {
        $map = [
            'every_5min'    => ['sc' => 'MINUTE', 'mo' => '5'],
            'every_15min'   => ['sc' => 'MINUTE', 'mo' => '15'],
            'every_30min'   => ['sc' => 'MINUTE', 'mo' => '30'],
            'every_hour'    => ['sc' => 'HOURLY', 'mo' => '1'],
            'every_2hours'  => ['sc' => 'HOURLY', 'mo' => '2'],
            'every_6hours'  => ['sc' => 'HOURLY', 'mo' => '6'],
            'every_12hours' => ['sc' => 'HOURLY', 'mo' => '12'],
        ];

        if (isset($map[$schedule])) {
            return $map[$schedule];
        }

        // daily_HH:MM
        if (preg_match('/^daily_(\d{1,2}):(\d{2})$/', $schedule, $m)) {
            return ['sc' => 'DAILY', 'st' => sprintf('%02d:%02d', (int) $m[1], (int) $m[2])];
        }

        throw new \InvalidArgumentException("Cannot convert schedule to schtasks args: $schedule");
    }

    private function ensureBatFile(): string
    {
        $logPath = $this->varDir . '/' . $this->taskName . '.log';
        $batPath = $this->varDir . '/' . $this->taskName . '.bat';
        $php     = $this->resolvePhpBinary();

        $content = sprintf(
            "@echo off\r\n\"%s\" \"%s\" >> \"%s\" 2>&1\r\n",
            $php,
            str_replace('/', '\\', $this->scriptPath),
            str_replace('/', '\\', $logPath),
        );

        file_put_contents($batPath, $content);

        return str_replace('/', '\\', $batPath);
    }

    private function resolvePhpBinary(): string
    {
        $binary = PHP_BINARY;

        // In a web/CGI context PHP_BINARY may point to php-cgi.exe — swap it for php.exe
        if (stripos($binary, 'php-cgi') !== false || stripos($binary, 'php-cgi.exe') !== false) {
            $candidate = rtrim(dirname($binary), '/\\') . DIRECTORY_SEPARATOR . 'php.exe';

            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return $binary;
    }

    private function stateFile(): string
    {
        return $this->varDir . '/' . $this->taskName . '.state.json';
    }

    private function saveState(string $schedule): void
    {
        file_put_contents($this->stateFile(), json_encode(['schedule' => $schedule], JSON_THROW_ON_ERROR));
    }

    private function loadState(): ?string
    {
        $file = $this->stateFile();

        if (!file_exists($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? ($data['schedule'] ?? null) : null;
    }

    private function deleteState(): void
    {
        $file = $this->stateFile();

        if (file_exists($file)) {
            unlink($file);
        }
    }
}
