<?php

require __DIR__ . '/core/request.php';

header('Content-Type: application/json; charset=utf-8');

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

    $cmd = 'sudo -n ' . $commands[$action];
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);

    if ($code !== 0) {
        $raw = trim(implode("\n", $out));
        $error = $raw !== '' ? $raw : 'Command failed';
        if (stripos($error, 'a password is required') !== false) {
            $error .= '. Add NOPASSWD for www-data in /etc/sudoers.d/Morfeas_web_allow';
        }
        throw new RuntimeException($error);
    }

    echo json_encode([
        'ok' => true,
        'data' => [
            'action' => $action,
            'accepted_at' => time(),
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
