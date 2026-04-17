<?php

require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/concurrency.php';
require_once __DIR__ . '/session_registry.php';

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON body'], JSON_PRETTY_PRINT);
        exit;
    }

    return $data;
}

function api_is_debug_mode(): bool
{
    $value = getenv('MORFEAS_API_DEBUG');
    if ($value === false) {
        return false;
    }

    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function api_make_request_id(): string
{
    try {
        return bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        return dechex(time()) . dechex(mt_rand(0, 0xffff));
    }
}

function api_log_internal_error(
    string $requestId,
    string $context,
    string $details,
    array $extra = []
): void {
    $payload = [
        'ts' => date('c'),
        'request_id' => $requestId,
        'context' => $context,
        'details' => $details,
    ];
    if (!empty($extra)) {
        $payload['extra'] = $extra;
    }

    error_log('[MorfeasAPI] ' . json_encode($payload, JSON_UNESCAPED_SLASHES));
    api_log_to_system_status_logger($payload);
}

function api_system_status_logger_dir(): string
{
    static $cachedDir = null;
    if (is_string($cachedDir)) {
        return $cachedDir;
    }

    $dir = rtrim(backend_ramdisk_dir(), '/') . '/Morfeas_Loggers/';
    $cfg = backend_log_config_path();
    if (is_file($cfg)) {
        $xml = @simplexml_load_file($cfg);
        if ($xml !== false) {
            $candidate = trim((string)($xml->LOGGERS_DIR ?? ''));
            if ($candidate !== '') {
                $dir = rtrim($candidate, '/') . '/';
            }
        }
    }

    $cachedDir = $dir;
    return $cachedDir;
}

function api_log_to_system_status_logger(array $payload): void
{
    $dir = api_system_status_logger_dir();
    if ($dir === '') {
        return;
    }

    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return;
    }

    $line = sprintf(
        "[%s] [Morfeas_WEB_API] request_id=%s context=%s details=%s extra=%s",
        (string)($payload['ts'] ?? date('c')),
        api_log_compact_value($payload['request_id'] ?? ''),
        api_log_compact_value($payload['context'] ?? ''),
        api_log_compact_value($payload['details'] ?? ''),
        api_log_compact_value(isset($payload['extra']) ? json_encode($payload['extra'], JSON_UNESCAPED_SLASHES) : '')
    );

    @file_put_contents($dir . 'morfeas_web_api.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function api_log_compact_value($value): string
{
    $text = trim((string)$value);
    if ($text === '') {
        return '-';
    }
    $text = str_replace(["\r\n", "\n", "\r", "\t"], ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text) ?: $text;
    return $text;
}

function api_fail_response(
    string $publicError,
    int $status = 500,
    string $context = 'api',
    ?Throwable $exception = null,
    array $extra = []
): void {
    $requestId = api_make_request_id();

    if ($exception !== null) {
        $extra = array_merge($extra, [
            'exception' => get_class($exception),
        ]);
        api_log_internal_error($requestId, $context, $exception->getMessage(), $extra);
    } elseif (!empty($extra)) {
        api_log_internal_error($requestId, $context, 'operation_failed', $extra);
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $payload = [
        'ok' => false,
        'error' => $publicError,
        'request_id' => $requestId,
    ];

    if (api_is_debug_mode()) {
        if ($exception !== null) {
            $payload['debug'] = [
                'type' => get_class($exception),
                'message' => $exception->getMessage(),
            ];
        } elseif (!empty($extra)) {
            $payload['debug'] = $extra;
        }
    }

    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
