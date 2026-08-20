<?php

require_once __DIR__ . '/core/request.php';
require_once __DIR__ . '/services/network_service.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        echo json_encode([
            'ok' => true,
            'data' => network_get_state(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($method === 'POST') {
        $body = read_json_body();
        $action = strtolower(trim((string) ($body['action'] ?? '')));

        switch ($action) {
            case 'apply':
                $payload = $body['payload'] ?? [];
                if (!is_array($payload)) {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => 'payload must be an object'], JSON_PRETTY_PRINT);
                    exit;
                }
                $timeoutSec = (int) ($body['timeout_sec'] ?? NETWORK_DEFAULT_TIMEOUT_SEC);
                $autoConfirm = true;
                if (array_key_exists('auto_confirm', $body)) {
                    $autoConfirm = filter_var($body['auto_confirm'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($autoConfirm === null) {
                        $autoConfirm = true;
                    }
                }
                $result = network_apply_staged($payload, $timeoutSec, $autoConfirm);
                echo json_encode(['ok' => true, 'data' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                exit;

            case 'confirm':
                $pendingId = trim((string) ($body['pending_id'] ?? ''));
                if ($pendingId === '') {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => 'pending_id is required for confirm'], JSON_PRETTY_PRINT);
                    exit;
                }
                $result = network_confirm_pending($pendingId);
                echo json_encode(['ok' => true, 'data' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                exit;

            case 'rollback':
                $pendingId = trim((string) ($body['pending_id'] ?? ''));
                $result = network_manual_rollback($pendingId !== '' ? $pendingId : null);
                echo json_encode(['ok' => true, 'data' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                exit;

            default:
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'action must be apply, confirm, or rollback'], JSON_PRETTY_PRINT);
                exit;
        }
    }

    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_PRETTY_PRINT);
} catch (InvalidArgumentException $e) {
    api_fail_response($e->getMessage(), 400, 'api_network_config.validation', $e);
} catch (Throwable $e) {
    api_fail_response('Failed to process network configuration', 500, 'api_network_config', $e);
}
