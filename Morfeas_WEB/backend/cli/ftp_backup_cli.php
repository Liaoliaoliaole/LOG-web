<?php

require __DIR__ . '/../services/ftp_backup_service.php';

function ftp_backup_cli_out(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

try {
    $action = strtolower(trim((string) ($argv[1] ?? '')));
    switch ($action) {
        case 'backup':
            $result = ftp_backup_run_backup();
            ftp_backup_cli_out([
                'ok' => true,
                'action' => 'backup',
                'data' => $result,
            ]);
            exit(0);

        case 'upload-log':
            $result = ftp_backup_upload_logs();
            ftp_backup_cli_out([
                'ok' => true,
                'action' => 'upload-log',
                'data' => $result,
            ]);
            exit(0);

        case 'list':
            $files = ftp_backup_list_files();
            ftp_backup_cli_out([
                'ok' => true,
                'action' => 'list',
                'data' => ['files' => $files],
            ]);
            exit(0);

        default:
            fwrite(STDERR, "Usage: php ftp_backup_cli.php [backup|upload-log|list]\n");
            exit(2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
