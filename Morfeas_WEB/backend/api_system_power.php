<?php

require __DIR__ . '/core/request.php';

header('Content-Type: application/json; charset=utf-8');

const SYSTEM_POWER_LOCK_TYPE = 'system_action';
const SYSTEM_POWER_LOCK_ID = 'system_power';
const SYSTEM_POWER_LOCK_TTL_SEC = 45;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_PRETTY_PRINT);
    exit;
}

try {
    $body = read_json_body();
    $action = strtolower(trim((string) ($body['action'] ?? '')));

    $commands = [
        'reboot' => '/sbin/reboot',
        'shutdown' => '/sbin/poweroff',
    ];

    if (!array_key_exists($action, $commands)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'action must be reboot or shutdown'], JSON_PRETTY_PRINT);
        exit;
    }

    $sessionId = backend_require_session_token('Missing session token for system power action');
    $acquire = backend_session_registry_acquire_lock(
        SYSTEM_POWER_LOCK_TYPE,
        SYSTEM_POWER_LOCK_ID,
        $sessionId,
        SYSTEM_POWER_LOCK_TTL_SEC,
        'running',
        ['action' => $action]
    );
    if (!$acquire['acquired']) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'A system power action was already triggered by another session.'], JSON_PRETTY_PRINT);
        exit;
    }

    $cmd = 'sudo -n ' . $commands[$action];
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);

    if ($code !== 0) {
        backend_session_registry_release_lock(SYSTEM_POWER_LOCK_TYPE, SYSTEM_POWER_LOCK_ID, $sessionId);
        $raw = trim(implode("\n", $out));
        $publicError = 'System power command failed';
        $httpStatus = 500;
        if (stripos($raw, 'a password is required') !== false) {
            $publicError = 'Permission denied for system power command';
            $httpStatus = 403;
        }
        api_fail_response(
            $publicError,
            $httpStatus,
            'api_system_power.command',
            new RuntimeException($raw !== '' ? $raw : 'Command failed'),
            ['action' => $action, 'exit_code' => $code]
        );
    }

    echo json_encode([
        'ok' => true,
        'data' => [
            'action' => $action,
            'accepted_at' => time(),
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $e) {
    api_fail_response($e->getMessage(), 400, 'api_system_power.validation', $e);
} catch (Throwable $e) {
    api_fail_response('Failed to execute system power action', 500, 'api_system_power', $e);
}
