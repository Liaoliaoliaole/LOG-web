<?php

require __DIR__ . '/core/request.php';
require __DIR__ . '/services/ftp_backup_service.php';

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

        case 'list':
            $files = ftp_backup_list_files();
            ftp_backup_api_respond(true, ['files' => $files], 'Backup list loaded');

        case 'backup':
            $result = ftp_backup_run_backup();
            ftp_backup_api_respond(true, $result, 'Backup uploaded');

        case 'restore':
            $file = trim((string) ($body['file'] ?? ''));
            if ($file === '') {
                throw new InvalidArgumentException("file is required for restore action");
            }
            $result = ftp_backup_run_restore($file);
            ftp_backup_api_respond(true, $result, "Restored from: $file");

        case 'uploadlog':
            $result = ftp_backup_upload_logs();
            ftp_backup_api_respond(true, $result, 'Log files uploaded');

        default:
            throw new InvalidArgumentException("Unknown action: $action");
    }
} catch (InvalidArgumentException $e) {
    api_fail_response($e->getMessage(), 400, 'api_ftp_backup.validation', $e);
} catch (RuntimeException $e) {
    $status = (int) $e->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    api_fail_response($e->getMessage(), $status, 'api_ftp_backup.runtime', $e);
} catch (Throwable $e) {
    api_fail_response('Failed to process FTP backup request', 500, 'api_ftp_backup', $e);
}
