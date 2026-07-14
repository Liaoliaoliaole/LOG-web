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

function system_status_fail(string $error, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $error], JSON_PRETTY_PRINT);
    exit;
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

        case 'loggers_export':
        case 'loggers_zip':
            $dir = system_status_resolve_loggers_dir($logCfg, $ramdisk);
            $known = system_status_collect_loggers($dir);
            $selected = system_status_parse_logger_name_list($_GET['name'] ?? ($_GET['names'] ?? []));
            if (!$selected) {
                system_status_fail('Missing logger names', 400);
            }

            try {
                $export = system_status_build_combined_logger_export($dir, $selected, $known);
            } catch (InvalidArgumentException $e) {
                system_status_fail($e->getMessage(), $e->getCode() ?: 400);
            }

            header_remove('Content-Type');
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $export['filename'] . '"');
            header('Content-Length: ' . (string)strlen($export['content']));
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            echo $export['content'];
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
