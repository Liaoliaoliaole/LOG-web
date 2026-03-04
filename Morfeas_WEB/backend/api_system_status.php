<?php
// backend/api_system_status.php
// Provides System Status (Details) and System Loggers data.

require __DIR__ . '/core/paths.php';
require __DIR__ . '/services/system_status_service.php';

header('Content-Type: application/json');

$ramdisk = backend_ramdisk_dir();
$logCfg = backend_log_config_path();

$action = $_GET['action'] ?? 'details';

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
        $path = $dir . $name;
        if (!is_file($path)) {
            continue;
        }
        $list[] = $name;
    }

    sort($list, SORT_NATURAL | SORT_FLAG_CASE);
    return $list;
}

try {
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
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Missing logger name'], JSON_PRETTY_PRINT);
                exit;
            }

            // Prevent path traversal and enforce known logger list.
            $name = basename($name);
            $known = system_status_collect_loggers($dir);
            if (!in_array($name, $known, true)) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => "Logger not found: {$name}"], JSON_PRETTY_PRINT);
                exit;
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
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => 'Failed to read logger file'], JSON_PRETTY_PRINT);
                exit;
            }

            echo json_encode([
                'ok' => true,
                'updated' => true,
                'mtime' => $mtime,
                'name' => $name,
                'content' => $content,
            ], JSON_PRETTY_PRINT);
            exit;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown action'], JSON_PRETTY_PRINT);
            exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
