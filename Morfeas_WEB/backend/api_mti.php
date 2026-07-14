<?php

require __DIR__ . '/core/paths.php';
require __DIR__ . '/core/request.php';
require __DIR__ . '/services/mti_service.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        $name = trim((string)($_GET['name'] ?? ''));
        if ($name === '') {
            echo json_encode([
                'ok' => true,
                'data' => [
                    'devices' => mti_collect_names(backend_ramdisk_dir()),
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $state = mti_load_state(backend_ramdisk_dir(), $name);
        echo json_encode(['ok' => true, 'data' => $state], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($method === 'POST') {
        $body = read_json_body();
        $action = strtolower(trim((string)($body['action'] ?? '')));
        $name = trim((string)($body['name'] ?? ''));
        if ($name === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'name is required'], JSON_PRETTY_PRINT);
            exit;
        }

        switch ($action) {
            case 'config':
                $result = mti_set_config($name, $body);
                $state = mti_load_state(backend_ramdisk_dir(), $name);
                echo json_encode(['ok' => true, 'data' => ['result' => $result, 'state' => $state]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                exit;

            case 'global_power':
                $enabled = filter_var($body['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($enabled === null) {
                    $enabled = false;
                }
                $result = mti_set_global_power($name, $enabled);
                $state = mti_load_state(backend_ramdisk_dir(), $name);
                echo json_encode(['ok' => true, 'data' => ['result' => $result, 'state' => $state]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                exit;

            case 'tele_switch':
                $result = mti_control_tele_switch($name, $body);
                $state = mti_load_state(backend_ramdisk_dir(), $name);
                echo json_encode(['ok' => true, 'data' => ['result' => $result, 'state' => $state]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                exit;

            case 'pwm_config':
                $result = mti_set_pwm_config($name, $body);
                $state = mti_load_state(backend_ramdisk_dir(), $name);
                echo json_encode(['ok' => true, 'data' => ['result' => $result, 'state' => $state]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                exit;

            default:
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'action must be config, global_power, tele_switch, or pwm_config'], JSON_PRETTY_PRINT);
                exit;
        }
    }

    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_PRETTY_PRINT);
} catch (InvalidArgumentException $e) {
    api_fail_response($e->getMessage(), 400, 'api_mti.validation', $e);
} catch (Throwable $e) {
    api_fail_response('Failed to process MTI request', 500, 'api_mti', $e);
}
