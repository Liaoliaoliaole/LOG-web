<?php
// backend/api_system_status.php
// Provides System Status (Details) and System Loggers data.

require __DIR__ . '/core/paths.php';
require __DIR__ . '/core/request.php';
require __DIR__ . '/services/system_status_service.php';

header('Content-Type: application/json; charset=utf-8');

$ramdisk = backend_ramdisk_dir();
$logCfg = backend_log_config_path();

$action = $_GET['action'] ?? 'details';

const SYSTEM_STATUS_HIDDEN_LOGGER_FILES = [
    'LOG_daily_update_check.log',
];

function system_status_fail(string $error, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $error], JSON_PRETTY_PRINT);
    exit;
}

function system_status_resolve_loggers_dir(string $logCfgPath, string $ramdisk): string
{
    if (is_file($logCfgPath)) {
        $xml = @simplexml_load_file($logCfgPath);
        if ($xml !== false) {
            $candidate = trim((string)($xml->LOGGERS_DIR ?? ''));
            if ($candidate !== '') {
                return rtrim($candidate, '/') . '/';
            }
        }
    }
    return rtrim($ramdisk, '/') . '/Morfeas_Loggers/';
}

function system_status_collect_loggers(string $dir): array
{
    $list = [];
    if (!is_dir($dir)) {
        return $list;
    }

    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        if (in_array($name, SYSTEM_STATUS_HIDDEN_LOGGER_FILES, true)) {
            continue;
        }
        $path = $dir . $name;
        if (!is_file($path)) {
            continue;
        }
        $list[] = $name;
    }

    sort($list, SORT_NATURAL | SORT_FLAG_CASE);
    return $list;
}

function system_status_parse_journal_lines($raw): int
{
    $value = (int) $raw;
    if ($value <= 0) {
        $value = 500;
    }
    if ($value < 50) {
        $value = 50;
    }
    if ($value > 3000) {
        $value = 3000;
    }
    return $value;
}

function system_status_parse_journal_units($raw): array
{
    if (is_array($raw)) {
        $parts = $raw;
    } else {
        $parts = preg_split('/[,\s]+/', (string) $raw) ?: [];
    }

    $units = [];
    foreach ($parts as $part) {
        $unit = trim((string) $part);
        if ($unit === '') {
            continue;
        }
        // Allow systemd unit-safe characters only.
        if (preg_match('/^[A-Za-z0-9@_.:-]+$/', $unit) !== 1) {
            continue;
        }
        if (!str_contains($unit, '.')) {
            $unit .= '.service';
        }
        if (!in_array($unit, $units, true)) {
            $units[] = $unit;
        }
    }

    if (count($units) > 8) {
        $units = array_slice($units, 0, 8);
    }

    return $units;
}

function system_status_build_journal_command(int $lines, array $units, bool $useSudo): string
{
    $parts = [];
    if ($useSudo) {
        $parts[] = 'sudo';
        $parts[] = '-n';
    }
    $parts[] = '/usr/bin/journalctl';
    $parts[] = '--no-pager';
    $parts[] = '-o';
    $parts[] = 'short-iso';
    $parts[] = '-n';
    $parts[] = (string) $lines;
    foreach ($units as $unit) {
        $parts[] = '-u';
        $parts[] = $unit;
    }

    return implode(' ', array_map(static fn($p) => escapeshellarg($p), $parts));
}

function system_status_run_command(string $command): array
{
    $out = [];
    $code = 0;
    exec($command . ' 2>&1', $out, $code);
    return [
        'code' => (int) $code,
        'output' => trim(implode("\n", $out)),
    ];
}

function system_status_is_permission_issue(string $output): bool
{
    $text = strtolower($output);
    return str_contains($text, 'permission denied')
        || str_contains($text, 'not in the')
        || str_contains($text, 'insufficient')
        || str_contains($text, 'a password is required');
}

function system_status_read_journal(int $lines, array $units): array
{
    $normalCmd = system_status_build_journal_command($lines, $units, false);
    $normal = system_status_run_command($normalCmd);
    if ($normal['code'] === 0) {
        return [
            'content' => $normal['output'],
            'units' => $units,
            'lines' => $lines,
            'used_sudo' => false,
        ];
    }

    if (!system_status_is_permission_issue($normal['output'])) {
        throw new RuntimeException('journalctl failed');
    }

    $sudoCmd = system_status_build_journal_command($lines, $units, true);
    $sudo = system_status_run_command($sudoCmd);
    if ($sudo['code'] === 0) {
        return [
            'content' => $sudo['output'],
            'units' => $units,
            'lines' => $lines,
            'used_sudo' => true,
        ];
    }

    if (str_contains(strtolower($sudo['output']), 'a password is required')) {
        throw new RuntimeException('journal permission denied; configure sudoers for /usr/bin/journalctl');
    }

    throw new RuntimeException('journalctl failed');
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET') {
        header('Allow: GET');
        system_status_fail('Method not allowed', 405);
    }

    switch ($action) {
        case 'details':
            $entries = system_status_entries($ramdisk);
            echo json_encode(['ok' => true, 'entries' => $entries], JSON_PRETTY_PRINT);
            exit;

        case 'loggers':
            $dir = system_status_resolve_loggers_dir($logCfg, $ramdisk);
            $loggers = system_status_collect_loggers($dir);
            echo json_encode([
                'ok' => true,
                'logger_names' => $loggers,
                'dir' => $dir,
            ], JSON_PRETTY_PRINT);
            exit;

        case 'logger':
            $dir = system_status_resolve_loggers_dir($logCfg, $ramdisk);
            $name = trim((string)($_GET['name'] ?? ''));
            $ifUpdated = isset($_GET['if_updated']) && (string)$_GET['if_updated'] === '1';
            $mtimeClient = isset($_GET['mtime']) ? (int)$_GET['mtime'] : 0;

            if ($name === '') {
                system_status_fail('Missing logger name', 400);
            }

            // Prevent path traversal and enforce known logger list.
            $name = basename($name);
            $known = system_status_collect_loggers($dir);
            if (!in_array($name, $known, true)) {
                system_status_fail("Logger not found: {$name}", 404);
            }

            $path = $dir . $name;
            $mtime = (int)@filemtime($path);
            if ($ifUpdated && $mtimeClient >= $mtime && $mtime > 0) {
                echo json_encode([
                    'ok' => true,
                    'updated' => false,
                    'mtime' => $mtime,
                    'name' => $name,
                ], JSON_PRETTY_PRINT);
                exit;
            }

            $content = @file_get_contents($path);
            if ($content === false) {
                system_status_fail('Failed to read logger file', 500);
            }

            echo json_encode([
                'ok' => true,
                'updated' => true,
                'mtime' => $mtime,
                'name' => $name,
                'content' => $content,
            ], JSON_PRETTY_PRINT);
            exit;

        case 'journal':
            $lines = system_status_parse_journal_lines($_GET['lines'] ?? null);
            $units = system_status_parse_journal_units($_GET['units'] ?? ($_GET['unit'] ?? ''));
            try {
                $journal = system_status_read_journal($lines, $units);
            } catch (RuntimeException $e) {
                $msg = $e->getMessage();
                $status = str_contains(strtolower($msg), 'permission denied') ? 403 : 500;
                system_status_fail($msg, $status);
            }
            echo json_encode([
                'ok' => true,
                'content' => $journal['content'],
                'lines' => $journal['lines'],
                'units' => $journal['units'],
                'used_sudo' => $journal['used_sudo'],
                'read_at_unix' => time(),
            ], JSON_PRETTY_PRINT);
            exit;

        default:
            system_status_fail('Unknown action', 400);
    }
} catch (Throwable $e) {
    api_fail_response('Failed to read system status', 500, 'api_system_status', $e);
}
