<?php

require_once __DIR__ . '/core/paths.php';
require_once __DIR__ . '/core/request.php';
require_once __DIR__ . '/services/can_role_service.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'POST';

try {
    if ($method !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_PRETTY_PRINT);
        exit;
    }

    $body = read_json_body();
    $action = strtolower(trim((string) ($body['action'] ?? '')));
    $bus = trim((string) ($body['bus'] ?? ''));

    $targetMode = match ($action) {
        'switch_to_nox' => 'NOX',
        'switch_to_sdaq' => 'SDAQ',
        default => null,
    };

    if ($targetMode === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'action must be switch_to_nox or switch_to_sdaq'], JSON_PRETTY_PRINT);
        exit;
    }

    $result = can_role_transition(backend_ramdisk_dir(), backend_log_config_path(), $bus, $targetMode);
    echo json_encode(['ok' => true, 'data' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $e) {
    api_fail_response($e->getMessage(), 400, 'api_can_role_transition.validation', $e);
} catch (Throwable $e) {
    api_fail_response('Failed to change CAN role', 500, 'api_can_role_transition', $e);
}
