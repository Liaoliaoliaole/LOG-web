<?php

require_once __DIR__ . '/core/paths.php';
require_once __DIR__ . '/core/request.php';
require_once __DIR__ . '/services/iobox_service.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        $name = trim((string)($_GET['name'] ?? ''));
        if ($name === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'name is required'], JSON_PRETTY_PRINT);
            exit;
        }

        $state = iobox_load_state(backend_ramdisk_dir(), $name);
        echo json_encode(['ok' => true, 'data' => $state], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_PRETTY_PRINT);
} catch (InvalidArgumentException $e) {
    api_fail_response($e->getMessage(), 400, 'api_iobox.validation', $e);
} catch (Throwable $e) {
    api_fail_response('Failed to process IOBOX request', 500, 'api_iobox', $e);
}
