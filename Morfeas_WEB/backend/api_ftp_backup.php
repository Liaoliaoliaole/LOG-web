<?php

require_once __DIR__ . '/core/request.php';
require_once __DIR__ . '/services/ftp_backup_service.php';

header('Content-Type: application/json; charset=utf-8');

function ftp_backup_api_respond(bool $ok, ?array $data = null, ?string $message = null, int $status = 200): void
{
    http_response_code($status);
    echo json_encode([
        'ok' => $ok,
        'data' => $data,
        'message' => $message,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $action = strtolower(trim((string) ($_GET['action'] ?? '')));
        if ($action !== 'config_if_updated') {
            ftp_backup_api_respond(false, null, 'action must be config_if_updated for GET', 400);
        }

        $data = ftp_backup_config_if_updated(2);
        ftp_backup_api_respond(true, $data, 'Config status read');
    }

    if ($method !== 'POST') {
        header('Allow: GET, POST');
        ftp_backup_api_respond(false, null, 'Method not allowed', 405);
    }

    $body = read_json_body();
    $action = strtolower(trim((string) ($body['action'] ?? '')));

    switch ($action) {
        case 'saveconfig':
            $host = trim((string) ($body['host'] ?? ''));
            $dir = trim((string) ($body['dir'] ?? ''));
            if ($host === '' || $dir === '') {
                throw new InvalidArgumentException('host and dir are required');
            }
            $config = ftp_backup_save_config($host, $dir);
            ftp_backup_api_respond(true, ['config' => $config], 'Config saved');

        case 'testconnect':
            ftp_backup_test_connection();
            ftp_backup_api_respond(true, null, 'FTP connection is valid');

        case 'clearconfig':
            ftp_backup_clear_config();
            ftp_backup_api_respond(true, null, 'Config cleared');

        case 'listdirs':
            $host = trim((string) ($body['host'] ?? ''));
            $path = trim((string) ($body['path'] ?? '/'));
            if ($host === '') {
                // Fall back to saved config host if available
                try {
                    $cfg = ftp_backup_load_config_raw();
                    $host = $cfg['host'];
                } catch (Throwable $ignored) {
                    throw new InvalidArgumentException('host is required');
                }
            }
            $dirs = ftp_backup_list_dirs($host, $path);
            ftp_backup_api_respond(true, $dirs, 'Directories listed');

        case 'list':
            $files = ftp_backup_list_files();
            ftp_backup_api_respond(true, ['files' => $files], 'Backup list loaded');

        case 'backup':
            $result = ftp_backup_run_backup();
            ftp_backup_api_respond(true, $result, 'Backup uploaded');

        case 'restore_preflight':
            $file = trim((string) ($body['file'] ?? ''));
            if ($file === '') {
                throw new InvalidArgumentException('file is required for restore_preflight action');
            }
            // Read-only: downloads and validates the candidate, writes
            // nothing, so it does not need the system_action 'restore' lock
            // or the edit-mode blocker check -- those guard the write in
            // restore_commit below. It does take the local config locks
            // briefly, just to read a consistent local_config_digest.
            $preflightResult = ftp_backup_restore_preflight(
                $file,
                backend_opcua_config_path(),
                backend_log_config_path(),
                dirname(backend_log_config_path())
            );
            ftp_backup_api_respond(true, $preflightResult, 'Preflight complete');

        case 'restore_commit':
            $file = trim((string) ($body['file'] ?? ''));
            $digest = trim((string) ($body['digest'] ?? ''));
            $localConfigDigest = trim((string) ($body['local_config_digest'] ?? ''));
            if ($file === '') {
                throw new InvalidArgumentException('file is required for restore_commit action');
            }
            if ($digest === '') {
                throw new InvalidArgumentException('digest is required for restore_commit action');
            }
            if ($localConfigDigest === '') {
                throw new InvalidArgumentException('local_config_digest is required for restore_commit action');
            }
            // One exclusive, atomic acquire that also checks for an active
            // SDAQ calibration edit in the SAME session-registry critical
            // section (see backend_session_registry_acquire_lock()'s
            // docblock) -- a separate check-then-acquire pair would leave a
            // real window between them for SDAQ edit_start() (which checks
            // the symmetric case the same way) to slip in. exclusive:true
            // also means two tabs of the same browser (sharing one session
            // id via localStorage) cannot both hold this lock at once.
            $sessionId = backend_require_session_token('Missing session token for restore_commit action');
            $acquire = backend_session_registry_acquire_lock(
                'system_action',
                'restore',
                $sessionId,
                300,
                'running',
                ['action' => 'restore'],
                ['channel_edit', 'device_config', 'sdaq_edit'],
                static function (array $record): bool {
                    return (string)($record['mode'] ?? '') === 'edit';
                },
                true
            );
            if (!$acquire['acquired']) {
                if (isset($acquire['blocked_by'])) {
                    throw new RuntimeException(backend_restore_blocking_lock_message($acquire['blocked_by']), 409);
                }
                // exclusive:true means this can also be the SAME session in
                // a second browser tab, so the message must not claim
                // "another session" when that may not be true.
                throw new RuntimeException('Restore is already running.', 409);
            }

            $restoreResult = null;
            try {
                $restoreResult = ftp_backup_restore_commit(
                    $file,
                    $digest,
                    $localConfigDigest,
                    backend_opcua_config_path(),
                    backend_log_config_path(),
                    dirname(backend_log_config_path()),
                    !empty($body['acknowledge_warnings'])
                );
            } finally {
                backend_session_registry_release_lock('system_action', 'restore', $sessionId);
            }

            ftp_backup_api_respond(true, $restoreResult, "Restored from: $file");

        case 'uploadlog':
            $result = ftp_backup_upload_logs();
            ftp_backup_api_respond(true, $result, 'Log files uploaded');

        default:
            throw new InvalidArgumentException("Unknown action: $action");
    }
} catch (InvalidArgumentException $e) {
    api_fail_response($e->getMessage(), 400, 'api_ftp_backup.validation', $e);
} catch (ChannelConfigException $e) {
    // ChannelConfigException carries its own status()/apiCode() (used by
    // ftp_backup_restore_preflight()/_commit() for validation failures,
    // digest mismatches, and partial-write failures); it must not fall
    // into the generic RuntimeException branch below, which reads
    // getCode() (always 0 here, since the constructor never sets it) and
    // would collapse every one of these into a bare HTTP 500 with no code.
    ftp_backup_api_respond(false, ['code' => $e->apiCode()], $e->getMessage(), $e->status());
} catch (RuntimeException $e) {
    $status = (int) $e->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    api_fail_response($e->getMessage(), $status, 'api_ftp_backup.runtime', $e);
} catch (Throwable $e) {
    api_fail_response('Failed to process FTP backup request', 500, 'api_ftp_backup', $e);
}
