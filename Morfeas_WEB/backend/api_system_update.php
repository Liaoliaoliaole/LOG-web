<?php

require __DIR__ . '/core/request.php';

header('Content-Type: application/json; charset=utf-8');

const UPDATE_SCRIPT = '/var/www/html/morfeas_web/update.sh';
const UPDATE_FLAG_FILE = '/var/lib/morfeas/update_needed';

function update_respond(
    bool $ok,
    ?array $data = null,
    ?string $error = null,
    ?string $message = null,
    int $status = 200,
    ?string $requestId = null,
    ?array $debug = null
): void {
    $payload = [
        'ok' => $ok,
        'data' => $data,
        'error' => $error,
        'message' => $message,
    ];
    if ($requestId !== null && $requestId !== '') {
        $payload['request_id'] = $requestId;
    }
    if (api_is_debug_mode() && is_array($debug) && !empty($debug)) {
        $payload['debug'] = $debug;
    }

    http_response_code($status);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function update_read_status(): array
{
    $exists = is_file(UPDATE_FLAG_FILE);
    $mtime = $exists ? @filemtime(UPDATE_FLAG_FILE) : false;

    return [
        'update_needed' => $exists,
        'flag_file' => UPDATE_FLAG_FILE,
        'flag_mtime_unix' => is_int($mtime) ? $mtime : null,
        'flag_mtime' => is_int($mtime) ? date('Y-m-d H:i:s', $mtime) : null,
    ];
}

function update_exec(string $mode): array
{
    $allowed = [
        'check' => '--check-only',
        'update' => '--update',
    ];

    if (!array_key_exists($mode, $allowed)) {
        throw new InvalidArgumentException('action must be check or update');
    }

    $arg = $allowed[$mode];
    $command = sprintf('sudo -n %s %s', escapeshellarg(UPDATE_SCRIPT), $arg);

    $out = [];
    $exitCode = 0;
    $startAt = microtime(true);
    exec($command . ' 2>&1', $out, $exitCode);
    $durationMs = (int) round((microtime(true) - $startAt) * 1000);

    $output = trim(implode("\n", $out));
    $status = update_read_status();
    $permissionDenied = stripos($output, 'a password is required') !== false;

    $result = 'unknown_failure';
    $ok = false;
    $message = 'Unknown failure while running update.sh';

    if ($mode === 'check') {
        if ($exitCode === 0) {
            $result = 'up_to_date';
            $ok = true;
            $message = 'System is up to date';
        } elseif ($exitCode === 100) {
            $result = 'update_available';
            $ok = true;
            $message = 'Update available';
        } elseif ($exitCode === 2) {
            $result = 'network_unreachable';
            $message = 'Network or git server unreachable';
        } else {
            $result = 'check_failed';
            $message = 'Update check failed';
        }
    } else {
        if ($exitCode === 0) {
            $result = 'update_executed';
            $ok = true;
            $message = 'Update command completed';
        } elseif ($exitCode === 2) {
            $result = 'network_unreachable';
            $message = 'Network or git server unreachable';
        } else {
            $result = 'update_failed';
            $message = 'Update command failed';
        }
    }
    if (!$ok && $permissionDenied) {
        $result = 'permission_denied';
        $message = 'Permission denied for update script execution';
    }

    $data = [
        'action' => $mode,
        'result' => $result,
        'exit_code' => $exitCode,
        'duration_ms' => $durationMs,
        'update_needed' => (bool) ($status['update_needed'] ?? false),
        'status' => $status,
    ];

    $error = $ok ? null : $message;

    return [
        'ok' => $ok,
        'data' => $data,
        'message' => $message,
        'error' => $error,
        'internal' => [
            'action' => $mode,
            'command' => $command,
            'stdout_stderr' => $output,
            'exit_code' => $exitCode,
            'duration_ms' => $durationMs,
        ],
    ];
}

function update_failure_http_status(array $result): int
{
    $kind = (string) (($result['data']['result'] ?? ''));
    return match ($kind) {
        'permission_denied' => 403,
        'network_unreachable' => 503,
        default => 500,
    };
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        $action = strtolower(trim((string) ($_GET['action'] ?? 'status')));
        if ($action !== 'status') {
            update_respond(false, null, 'action must be status for GET', 'Invalid action', 400);
        }

        update_respond(true, update_read_status(), null, 'Status read');
    }

    if ($method === 'POST') {
        $body = read_json_body();
        $action = strtolower(trim((string) ($body['action'] ?? '')));
        if ($action !== 'check' && $action !== 'update') {
            update_respond(false, null, 'action must be check or update', 'Invalid action', 400);
        }

        $result = update_exec($action);
        if (!$result['ok']) {
            $requestId = api_make_request_id();
            $details = (string) ($result['internal']['stdout_stderr'] ?? '');
            if ($details === '') {
                $details = 'update.sh exited with non-zero status';
            }
            api_log_internal_error(
                $requestId,
                'api_system_update.exec',
                $details,
                [
                    'action' => $action,
                    'exit_code' => (int) ($result['internal']['exit_code'] ?? -1),
                    'command' => (string) ($result['internal']['command'] ?? ''),
                ]
            );

            update_respond(
                false,
                $result['data'],
                $result['error'],
                $result['message'],
                update_failure_http_status($result),
                $requestId,
                $result['internal']
            );
        }

        update_respond(true, $result['data'], null, $result['message']);
    }

    header('Allow: GET, POST');
    update_respond(false, null, 'Method not allowed', 'Method not allowed', 405);
} catch (InvalidArgumentException $e) {
    update_respond(false, null, $e->getMessage(), 'Invalid request', 400);
} catch (Throwable $e) {
    api_fail_response('Failed to process system update request', 500, 'api_system_update', $e);
}
