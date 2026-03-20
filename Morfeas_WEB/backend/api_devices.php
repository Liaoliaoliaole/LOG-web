<?php

require __DIR__ . '/core/paths.php';
require __DIR__ . '/core/request.php';
require __DIR__ . '/services/device_service.php';

header('Content-Type: application/json; charset=utf-8');

$logConfig  = backend_log_config_path();
$ramdisk    = backend_ramdisk_dir();
$maxComponents = 16; // legacy limit (Morfeas_comp_amount_max)

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($method) {
        case 'GET':
            $payload = device_list($ramdisk, $logConfig, $maxComponents);
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            break;

        case 'POST':
            $body = read_json_body();

            $type = strtoupper(str_replace('-', '', trim($body['type'] ?? '')));
            $bus  = trim($body['bus'] ?? '');
            $name = trim($body['name'] ?? '');
            $ip   = trim($body['ip'] ?? '');

            if ($type === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'type is required'], JSON_PRETTY_PRINT);
                break;
            }
            if ($type === 'SDAQ') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'SDAQ is auto-discovered from logstat'], JSON_PRETTY_PRINT);
                break;
            }

            if ($type === 'NOX' && $bus === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'bus is required for NOX'], JSON_PRETTY_PRINT);
                break;
            }

            if ($type !== 'NOX' && $bus === '') {
                $bus = '-';
            }

            $device = device_add($logConfig, [
                'type' => $type,
                'bus'  => $bus,
                'name' => $name,
                'ip'   => $ip,
            ]);

            echo json_encode(['ok' => true, 'data' => $device], JSON_PRETTY_PRINT);
            break;

        case 'DELETE':
            $body = read_json_body();
            $ids  = $body['ids'] ?? [];
            if (!is_array($ids) || empty($ids)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'ids[] is required'], JSON_PRETTY_PRINT);
                break;
            }

            device_delete($logConfig, array_map('strval', $ids));
            echo json_encode(['ok' => true], JSON_PRETTY_PRINT);
            break;

        default:
            http_response_code(405);
            header('Allow: GET, POST, DELETE');
            echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_PRETTY_PRINT);
    }
} catch (Throwable $e) {
    api_fail_response('Failed to process device request', 500, 'api_devices', $e);
}
