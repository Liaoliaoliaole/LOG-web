<?php

require __DIR__ . '/core/paths.php';
require __DIR__ . '/core/request.php';
require __DIR__ . '/services/nox_service.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        $bus = trim((string) ($_GET['bus'] ?? ''));
        if ($bus === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'bus is required'], JSON_PRETTY_PRINT);
            exit;
        }

        $state = nox_load_state(backend_ramdisk_dir(), $bus);
        echo json_encode(['ok' => true, 'data' => $state], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($method === 'POST') {
        $body = read_json_body();
        $action = strtolower(trim((string) ($body['action'] ?? '')));
        $bus = trim((string) ($body['bus'] ?? ''));
        if ($bus === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'bus is required'], JSON_PRETTY_PRINT);
            exit;
        }

        switch ($action) {
            case 'heater':
                $address = (int) ($body['address'] ?? 0);
                $enabled = filter_var($body['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($enabled === null) {
                    $enabled = false;
                }
                $result = nox_set_heater($bus, $address, $enabled);
                $state = nox_load_state(backend_ramdisk_dir(), $bus);
                echo json_encode(['ok' => true, 'data' => ['result' => $result, 'state' => $state]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                exit;

            case 'auto_off':
                $value = (int) ($body['value'] ?? -1);
                $result = nox_set_auto_sw_off($bus, $value);
                $state = nox_load_state(backend_ramdisk_dir(), $bus);
                echo json_encode(['ok' => true, 'data' => ['result' => $result, 'state' => $state]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                exit;

            default:
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'action must be heater or auto_off'], JSON_PRETTY_PRINT);
                exit;
        }
    }

    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_PRETTY_PRINT);
} catch (InvalidArgumentException $e) {
    api_fail_response($e->getMessage(), 400, 'api_nox.validation', $e);
} catch (Throwable $e) {
    api_fail_response('Failed to process NOX request', 500, 'api_nox', $e);
}
