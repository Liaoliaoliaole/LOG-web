<?php

require __DIR__ . '/core/request.php';

header('Content-Type: application/json; charset=utf-8');

const UPDATE_SCRIPT = '/var/www/html/morfeas_web/update.sh';
const UPDATE_FLAG_FILE = '/tmp/update_needed';

function update_respond(
    bool $ok,
    ?array $data = null,
    ?string $error = null,
    ?string $message = null,
    $debug = null,
    int $status = 200
): void {
    http_response_code($status);
    echo json_encode([
        'ok' => $ok,
        'data' => $data,
        'error' => $error,
        'message' => $message,
        'debug' => $debug,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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

    $data = [
        'action' => $mode,
        'result' => $result,
        'exit_code' => $exitCode,
        'duration_ms' => $durationMs,
        'update_needed' => (bool) ($status['update_needed'] ?? false),
        'status' => $status,
    ];

    $debug = [
        'command' => $command,
        'stdout_stderr' => $output,
    ];

    $error = null;
    if (!$ok) {
        if ($output !== '') {
            $error = $output;
        } else {
            $error = $message;
        }
        if (stripos($error, 'a password is required') !== false) {
            $error .= '. Add NOPASSWD for www-data in /etc/sudoers.d/Morfeas_update_allow';
        }
    }

    return [
        'ok' => $ok,
        'data' => $data,
        'message' => $message,
        'error' => $error,
        'debug' => $debug,
    ];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        $action = strtolower(trim((string) ($_GET['action'] ?? 'status')));
        if ($action !== 'status') {
            update_respond(false, null, 'action must be status for GET', 'Invalid action', null, 400);
        }

        update_respond(true, update_read_status(), null, 'Status read');
    }

    if ($method === 'POST') {
        $body = read_json_body();
        $action = strtolower(trim((string) ($body['action'] ?? '')));
        if ($action !== 'check' && $action !== 'update') {
            update_respond(false, null, 'action must be check or update', 'Invalid action', null, 400);
        }

        $result = update_exec($action);
        $status = $result['ok'] ? 200 : 500;
        update_respond(
            $result['ok'],
            $result['data'],
            $result['error'],
            $result['message'],
            $result['debug'],
            $status
        );
    }

    header('Allow: GET, POST');
    update_respond(false, null, 'Method not allowed', 'Method not allowed', null, 405);
} catch (InvalidArgumentException $e) {
    update_respond(false, null, $e->getMessage(), 'Invalid request', null, 400);
} catch (Throwable $e) {
    update_respond(false, null, $e->getMessage(), 'Unhandled server error', null, 500);
}
