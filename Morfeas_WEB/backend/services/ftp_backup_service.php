<?php

require_once __DIR__ . '/../core/paths.php';
require_once __DIR__ . '/../core/concurrency.php';

function ftp_backup_default_host(): string
{
    return '10.193.135.70';
}

function ftp_backup_config_file(): string
{
    return '/home/morfeas/configuration/ftp_config.json';
}

function ftp_backup_credential_file(): string
{
    return '/home/morfeas/configuration/LOG_ftp_credential.conf';
}

function ftp_backup_log_file(): string
{
    return '/tmp/ftp_debug.log';
}

function ftp_backup_php_error_log_file(): string
{
    return '/tmp/php_errors.log';
}

function ftp_backup_logger_mirror_file(): string
{
    return '/mnt/ramdisk/Morfeas_Loggers/LOG_FTP_backup.log';
}

function ftp_backup_status_logger_file(): string
{
    return '/mnt/ramdisk/Morfeas_Loggers/LOG_FTP_php_errors.log';
}

function ftp_backup_engine_is_valid(string $engine): bool
{
    if ($engine === '.' || $engine === '..') {
        return false;
    }
    return preg_match('/^[A-Za-z0-9_.-]+$/', $engine) === 1;
}

function ftp_backup_host_is_valid(string $host): bool
{
    return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
}

function ftp_backup_log(string $level, string $message): void
{
    $line = sprintf(
        "[%s] [FTP_BACKUP] [%s] %s\n",
        date('Y-m-d H:i:s'),
        strtoupper(trim($level)),
        trim($message)
    );

    @file_put_contents(ftp_backup_log_file(), $line, FILE_APPEND | LOCK_EX);
    @file_put_contents(ftp_backup_logger_mirror_file(), $line, FILE_APPEND | LOCK_EX);
}

function ftp_backup_parse_kv_file(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('FTP credential file missing', 500);
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        throw new RuntimeException('Unable to read FTP credential file', 500);
    }

    $out = [];
    foreach ($lines as $rawLine) {
        $line = trim((string) $rawLine);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1]);
        $value = preg_split('/\s*[#;].*$/', $value, 2)[0] ?? $value;
        $value = trim($value, " \t\n\r\0\x0B\"'");

        if ($key !== '') {
            $out[$key] = $value;
        }
    }

    return $out;
}

function ftp_backup_load_credentials(): array
{
    $parsed = ftp_backup_parse_kv_file(ftp_backup_credential_file());
    $user = trim((string) ($parsed['FTP_USER'] ?? ''));
    $pass = trim((string) ($parsed['FTP_PASS'] ?? ''));

    if ($user === '' || $pass === '') {
        throw new RuntimeException('Invalid credentials in credential file', 500);
    }

    return [
        'user' => $user,
        'pass' => $pass,
    ];
}

function ftp_backup_public_config(array $config): array
{
    return [
        'host' => (string) ($config['host'] ?? ''),
        'dir' => (string) ($config['dir'] ?? ''),
        'log' => (string) ($config['log'] ?? ''),
    ];
}

function ftp_backup_load_config_raw(): array
{
    $path = ftp_backup_config_file();
    if (!is_file($path)) {
        throw new RuntimeException('No config file found. Please connect first.', 409);
    }

    $raw = @file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        throw new RuntimeException('Invalid FTP config JSON', 500);
    }

    $required = ['host', 'dir', 'log'];
    foreach ($required as $key) {
        if (!isset($data[$key]) || trim((string) $data[$key]) === '') {
            throw new RuntimeException("Incomplete FTP config data: missing $key", 500);
        }
    }

    $credentials = ftp_backup_load_credentials();
    return [
        'host' => trim((string) $data['host']),
        'user' => $credentials['user'],
        'pass' => $credentials['pass'],
        'dir' => trim((string) $data['dir']),
        'log' => trim((string) $data['log']),
    ];
}

function ftp_backup_save_config(string $host, string $engine): array
{
    if (!ftp_backup_host_is_valid($host)) {
        throw new InvalidArgumentException('Host IP must be a valid IPv4 address');
    }
    if (!ftp_backup_engine_is_valid($engine)) {
        throw new InvalidArgumentException('Engine Number allows only letters, numbers, ".", "_" and "-"');
    }

    ftp_backup_load_credentials();
    $hostname = trim((string) @file_get_contents('/etc/hostname'));
    if ($hostname === '') {
        $hostname = php_uname('n');
    }
    if ($hostname === '') {
        $hostname = 'morfeas';
    }

    $config = [
        'host' => $host,
        'dir' => $engine,
        'log' => $hostname,
    ];

    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Failed to serialize FTP config', 500);
    }

    $path = ftp_backup_config_file();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create FTP config directory', 500);
    }

    if (@file_put_contents($path, $json, LOCK_EX) === false) {
        throw new RuntimeException('Failed to save FTP config', 500);
    }
    @chgrp($path, 'morfeas');
    @chmod($path, 0660);

    ftp_backup_log('INFO', "Config saved for host=$host engine=$engine");
    return ftp_backup_public_config($config);
}

function ftp_backup_clear_config(): void
{
    $path = ftp_backup_config_file();
    if (is_file($path)) {
        @unlink($path);
        ftp_backup_log('INFO', 'Config cleared');
    } else {
        ftp_backup_log('INFO', 'Config clear requested but file missing');
    }
}

function ftp_backup_open_connection(array $config)
{
    if (!function_exists('ftp_connect')) {
        throw new RuntimeException('PHP FTP extension is not installed', 500);
    }

    $conn = @ftp_connect($config['host'], 21, 10);
    if (!$conn) {
        throw new RuntimeException('FTP connect failed', 502);
    }

    if (!@ftp_login($conn, $config['user'], $config['pass'])) {
        @ftp_close($conn);
        throw new RuntimeException('FTP login failed', 502);
    }

    if (!@ftp_pasv($conn, true)) {
        @ftp_close($conn);
        throw new RuntimeException('Failed to enable FTP passive mode', 502);
    }

    return $conn;
}

function ftp_backup_ensure_remote_dir($conn, string $engine): string
{
    $engine = trim($engine);
    if ($engine === '' || !ftp_backup_engine_is_valid($engine)) {
        throw new RuntimeException('Invalid engine number in FTP config', 500);
    }

    $remoteDir = '/' . trim($engine, '/');
    $parts = array_values(array_filter(explode('/', trim($remoteDir, '/')), static fn($p) => $p !== ''));

    $path = '';
    foreach ($parts as $part) {
        $path .= '/' . $part;
        if (!@ftp_chdir($conn, $path)) {
            if (!@ftp_mkdir($conn, $path)) {
                throw new RuntimeException("Failed to create FTP directory: $path", 502);
            }
        }
    }

    if (!@ftp_chdir($conn, $remoteDir)) {
        throw new RuntimeException("Failed to switch FTP directory: $remoteDir", 502);
    }

    return $remoteDir;
}

function ftp_backup_list_files(): array
{
    $config = ftp_backup_load_config_raw();
    $conn = ftp_backup_open_connection($config);

    try {
        ftp_backup_ensure_remote_dir($conn, $config['dir']);
        $files = @ftp_nlist($conn, '.') ?: [];
    } finally {
        @ftp_close($conn);
    }

    $out = [];
    foreach ($files as $file) {
        $base = basename((string) $file);
        if (preg_match('/\.mbl$/i', $base) === 1) {
            $out[] = $base;
        }
    }

    rsort($out, SORT_NATURAL | SORT_FLAG_CASE);
    ftp_backup_log('INFO', 'Listed ' . count($out) . ' backup files');
    return $out;
}

function ftp_backup_create_bundle_payload(): string
{
    $opcUaPath = backend_opcua_config_path();
    $morfeasPath = backend_log_config_path();

    if (!is_file($opcUaPath) || !is_file($morfeasPath)) {
        throw new RuntimeException('Required configuration XML files not found', 500);
    }

    $opcUa = @file_get_contents($opcUaPath);
    $morfeas = @file_get_contents($morfeasPath);
    if (!is_string($opcUa) || !is_string($morfeas)) {
        throw new RuntimeException('Failed to read configuration XML files', 500);
    }

    $bundle = [
        'OPC_UA_Config' => $opcUa,
        'Morfeas_Config' => $morfeas,
        'Checksum' => ftp_backup_payload_checksum($opcUa, $morfeas),
    ];

    $json = json_encode($bundle, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Failed to encode backup payload', 500);
    }

    $compressed = gzencode($json);
    if (!is_string($compressed) || $compressed === '') {
        throw new RuntimeException('Failed to compress backup payload', 500);
    }

    return $compressed;
}

function ftp_backup_payload_checksum(string $opcUa, string $morfeas): string
{
    return sprintf('%u', crc32($opcUa . $morfeas));
}

function ftp_backup_payload_checksum_matches($expected, string $opcUa, string $morfeas): bool
{
    if (!is_scalar($expected)) {
        return false;
    }

    $expectedChecksum = trim((string) $expected);
    $unsigned = ftp_backup_payload_checksum($opcUa, $morfeas);
    $native = (string) crc32($opcUa . $morfeas);

    return hash_equals($expectedChecksum, $unsigned)
        || hash_equals($expectedChecksum, $native);
}

function ftp_backup_prune_remote_backups($conn, string $remoteDir): int
{
    $files = @ftp_nlist($conn, '.') ?: [];
    $mbis = [];
    foreach ($files as $file) {
        $base = basename((string) $file);
        if (preg_match('/\.mbl$/i', $base) === 1) {
            $mbis[] = $base;
        }
    }

    sort($mbis, SORT_NATURAL | SORT_FLAG_CASE);
    $excess = count($mbis) - 50;
    if ($excess <= 0) {
        return 0;
    }

    $deleted = 0;
    $remove = array_slice($mbis, 0, $excess);
    foreach ($remove as $filename) {
        $target = $remoteDir . '/' . $filename;
        if (@ftp_delete($conn, $target)) {
            $deleted++;
            ftp_backup_log('INFO', "Deleted old backup: $target");
        }
    }

    return $deleted;
}

function ftp_backup_run_backup(): array
{
    $config = ftp_backup_load_config_raw();
    $timestamp = date('Ymd_His');
    $filename = sprintf('%s_%s_%s.mbl', $config['dir'], $config['log'], $timestamp);
    $localFile = '/tmp/' . $filename;

    $payload = ftp_backup_create_bundle_payload();
    if (@file_put_contents($localFile, $payload, LOCK_EX) === false) {
        throw new RuntimeException('Failed to create local backup file', 500);
    }

    $conn = null;
    try {
        $conn = ftp_backup_open_connection($config);
        $remoteDir = ftp_backup_ensure_remote_dir($conn, $config['dir']);
        if (!@ftp_put($conn, $remoteDir . '/' . $filename, $localFile, FTP_BINARY)) {
            throw new RuntimeException("Failed to upload backup: $filename", 502);
        }
        $deletedCount = ftp_backup_prune_remote_backups($conn, $remoteDir);
    } finally {
        if ($conn !== null) {
            @ftp_close($conn);
        }
        @unlink($localFile);
    }

    @touch(ftp_backup_config_file());
    ftp_backup_log('INFO', "Backup uploaded: $filename");

    return [
        'filename' => $filename,
        'deleted_old_backups' => $deletedCount,
    ];
}

function ftp_backup_restore_filename_is_valid(string $filename): bool
{
    if ($filename === '' || basename($filename) !== $filename) {
        return false;
    }
    return preg_match('/^[A-Za-z0-9_.-]+\.mbl$/', $filename) === 1;
}

function ftp_backup_run_restore(string $filename): array
{
    if (!ftp_backup_restore_filename_is_valid($filename)) {
        throw new InvalidArgumentException('Invalid restore file name');
    }

    $config = ftp_backup_load_config_raw();
    $conn = ftp_backup_open_connection($config);
    $localFile = '/tmp/' . $filename;

    try {
        ftp_backup_ensure_remote_dir($conn, $config['dir']);
        if (!@ftp_get($conn, $localFile, $filename, FTP_BINARY)) {
            throw new RuntimeException("Download failed: $filename", 502);
        }
    } catch (Throwable $e) {
        @unlink($localFile);
        throw $e;
    } finally {
        @ftp_close($conn);
    }

    $raw = @file_get_contents($localFile);
    $json = is_string($raw) ? @gzdecode($raw) : false;
    $bundle = is_string($json) ? json_decode($json, true) : null;

    @unlink($localFile);

    if (!is_array($bundle)) {
        throw new RuntimeException('Invalid backup file content', 500);
    }

    $opcUa = $bundle['OPC_UA_Config'] ?? null;
    $morfeas = $bundle['Morfeas_Config'] ?? null;
    if (!is_string($opcUa) || !is_string($morfeas)) {
        throw new RuntimeException('Invalid backup payload', 500);
    }
    if (!ftp_backup_payload_checksum_matches($bundle['Checksum'] ?? null, $opcUa, $morfeas)) {
        throw new RuntimeException('Backup checksum mismatch', 500);
    }

    backend_with_resource_file_lock('opcua_config', backend_opcua_config_path(), function () use ($opcUa) {
        backend_atomic_write_file(backend_opcua_config_path(), $opcUa, 0644);
    });
    backend_with_resource_file_lock('log_config', backend_log_config_path(), function () use ($morfeas) {
        backend_atomic_write_file(backend_log_config_path(), $morfeas, 0644);
    });

    @touch(ftp_backup_config_file());
    ftp_backup_log('INFO', "Restored from backup: $filename");

    return [
        'filename' => $filename,
    ];
}

function ftp_backup_upload_logs(): array
{
    $config = ftp_backup_load_config_raw();
    $conn = ftp_backup_open_connection($config);

    try {
        ftp_backup_ensure_remote_dir($conn, $config['dir']);

        $map = [
            ftp_backup_log_file() => 'LOG_FTP_backup.log',
            ftp_backup_php_error_log_file() => 'LOG_FTP_php_errors.log',
        ];

        $uploaded = [];
        $skipped = [];
        foreach ($map as $local => $remoteName) {
            if (!is_file($local)) {
                $skipped[] = $remoteName;
                ftp_backup_log('WARN', "Log file missing: $local");
                continue;
            }
            if (!@ftp_put($conn, $remoteName, $local, FTP_BINARY)) {
                throw new RuntimeException("Failed to upload log file: $remoteName", 502);
            }
            $uploaded[] = $remoteName;
            ftp_backup_log('INFO', "Uploaded log file: $remoteName");
        }
    } finally {
        @ftp_close($conn);
    }

    return [
        'uploaded' => $uploaded,
        'skipped' => $skipped,
    ];
}

function ftp_backup_test_connection(): void
{
    $config = ftp_backup_load_config_raw();
    $conn = ftp_backup_open_connection($config);
    @ftp_close($conn);
    ftp_backup_log('INFO', "FTP test connection succeeded for host={$config['host']}");
}

function ftp_backup_config_if_updated(int $pollWindowSec = 2): array
{
    $path = ftp_backup_config_file();
    if (!is_file($path)) {
        return [
            'connected' => false,
            'updated' => false,
            'config' => null,
        ];
    }

    $mtime = @filemtime($path);
    $now = time();
    $updated = is_int($mtime) ? (($now - $mtime) <= $pollWindowSec) : false;

    try {
        $config = ftp_backup_public_config(ftp_backup_load_config_raw());
    } catch (Throwable $e) {
        $config = null;
    }

    return [
        'connected' => $config !== null,
        'updated' => $updated,
        'config' => $config,
    ];
}
