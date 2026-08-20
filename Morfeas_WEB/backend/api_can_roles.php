<?php

require_once __DIR__ . '/core/paths.php';
require_once __DIR__ . '/core/request.php';
require_once __DIR__ . '/services/can_role_service.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_PRETTY_PRINT);
        exit;
    }

    $snapshot = can_role_list(backend_ramdisk_dir(), backend_log_config_path());
    echo json_encode([
        'ok' => true,
        'data' => [
            'rows' => $snapshot['rows'],
            'warnings' => $snapshot['warnings'],
            'legacy' => $snapshot['legacy'] ?? null,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $e) {
    api_fail_response($e->getMessage(), 400, 'api_can_roles.validation', $e);
} catch (Throwable $e) {
    api_fail_response('Failed to load CAN roles', 500, 'api_can_roles', $e);
}
