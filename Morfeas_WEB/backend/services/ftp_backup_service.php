<?php

require_once __DIR__ . '/../core/paths.php';
require_once __DIR__ . '/../core/concurrency.php';
require_once __DIR__ . '/../core/opcua_config.php';
require_once __DIR__ . '/../core/log_config_validation.php';
require_once __DIR__ . '/../repositories/log_config_repository.php';
// For restore_ipv4_to_core_identifier(): FTP's cross-file handler matching
// must derive the Core identifier from an IPv4 with the exact same byte
// order Local JSON Restore uses, so the two entry points cannot disagree
// about whether a given handler matches a given channel.
require_once __DIR__ . '/channel_restore_service.php';

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

function ftp_backup_health_file(): string
{
    return '/tmp/ftp_backup_health.json';
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

function ftp_backup_record_health(bool $ok, string $message, ?array $config = null): void
{
    $payload = [
        'ok' => $ok,
        'message' => trim($message),
        'checked_at' => time(),
        'host' => is_array($config) ? (string) ($config['host'] ?? '') : '',
        'dir' => is_array($config) ? (string) ($config['dir'] ?? '') : '',
    ];

    @file_put_contents(ftp_backup_health_file(), json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function ftp_backup_read_health(): array
{
    $path = ftp_backup_health_file();
    if (!is_file($path)) {
        return [
            'ok' => null,
            'message' => 'FTP status has not been checked yet',
            'checked_at' => null,
        ];
    }

    $raw = @file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        return [
            'ok' => null,
            'message' => 'FTP status file is invalid',
            'checked_at' => null,
        ];
    }

    return [
        'ok' => array_key_exists('ok', $data) ? (bool) $data['ok'] : null,
        'message' => (string) ($data['message'] ?? ''),
        'checked_at' => isset($data['checked_at']) ? (int) $data['checked_at'] : null,
    ];
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
        @unlink(ftp_backup_health_file());
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
        ftp_backup_record_health(false, 'FTP connect failed', $config);
        throw new RuntimeException('FTP connect failed', 502);
    }

    if (!@ftp_login($conn, $config['user'], $config['pass'])) {
        @ftp_close($conn);
        ftp_backup_record_health(false, 'FTP login failed', $config);
        throw new RuntimeException('FTP login failed', 502);
    }

    if (!@ftp_pasv($conn, true)) {
        @ftp_close($conn);
        ftp_backup_record_health(false, 'Failed to enable FTP passive mode', $config);
        throw new RuntimeException('Failed to enable FTP passive mode', 502);
    }

    ftp_backup_record_health(true, 'FTP connection is valid', $config);
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

function ftp_backup_sanitize_browse_path(string $raw): string
{
    // Split on '/', keep only safe segments (no '..' or '.' or special chars)
    $segments = array_values(array_filter(
        explode('/', $raw),
        static fn($s) => $s !== '' && $s !== '.' && $s !== '..'
            && preg_match('/^[A-Za-z0-9_. -]+$/', $s) === 1
    ));
    return $segments === [] ? '/' : '/' . implode('/', $segments);
}

function ftp_backup_list_dirs(string $host, string $path): array
{
    if (!ftp_backup_host_is_valid($host)) {
        throw new InvalidArgumentException('Host IP must be a valid IPv4 address');
    }

    $safePath = ftp_backup_sanitize_browse_path($path);
    $credentials = ftp_backup_load_credentials();

    $conn = @ftp_connect($host, 21, 10);
    if (!$conn) {
        throw new RuntimeException('FTP connect failed', 502);
    }

    if (!@ftp_login($conn, $credentials['user'], $credentials['pass'])) {
        @ftp_close($conn);
        throw new RuntimeException('FTP login failed', 502);
    }

    @ftp_pasv($conn, true);

    try {
        $rawList = @ftp_rawlist($conn, $safePath);
    } finally {
        @ftp_close($conn);
    }

    $dirs = [];
    if (is_array($rawList)) {
        foreach ($rawList as $line) {
            $line = (string) $line;
            // Unix-style listing: first char 'd' means directory
            if ($line === '' || $line[0] !== 'd') {
                continue;
            }
            // Format: drwxrwxrwx 2 user group size Month Day time name
            $parts = preg_split('/\s+/', $line, 9);
            if (!is_array($parts) || count($parts) < 9) {
                continue;
            }
            $name = trim($parts[8]);
            if ($name !== '' && $name !== '.' && $name !== '..') {
                $dirs[] = $name;
            }
        }
    }

    sort($dirs, SORT_NATURAL | SORT_FLAG_CASE);
    ftp_backup_log('INFO', 'Listed ' . count($dirs) . " directories at $safePath");

    return ['dirs' => $dirs, 'path' => $safePath];
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
    $localFile = @tempnam(sys_get_temp_dir(), 'morfeas_ftp_backup_');
    if (!is_string($localFile) || $localFile === '') {
        throw new RuntimeException('Failed to allocate local backup temp file', 500);
    }

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

/*
 * Pure decode step: gzdecode + JSON-decode + the bundle's own embedded
 * CRC32 checksum. No network, no filesystem, no XML parsing -- separated
 * out from the FTP download itself so it (and everything downstream of it)
 * can be unit-tested with synthetic bytes, without a real FTP server.
 */
function ftp_backup_decode_bundle(string $rawBytes): array
{
    if ($rawBytes === '') {
        throw new RuntimeException('Backup file content is empty', 500);
    }

    $json = @gzdecode($rawBytes);
    $bundle = is_string($json) ? json_decode($json, true) : null;
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

    return ['opc_ua' => $opcUa, 'morfeas' => $morfeas];
}

/* Commit only the exact FTP bytes that the user reviewed in preflight. */
function ftp_backup_restore_digest(string $filename, string $rawBytes): string
{
    return hash('sha256', $filename . "\0" . $rawBytes);
}

/*
 * Warn when an IOBOX/MTI channel lacks a same-type handler in this bundle.
 * It remains restorable because Core accepts offline orphan definitions.
 */
function ftp_backup_check_bundle_handler_matching(SimpleXMLElement $xml, DOMDocument $morfeasDom): array
{
    $identifiers = ['IOBOX' => [], 'MTI' => []];
    $xpath = new DOMXPath($morfeasDom);
    foreach (['IOBOX' => '//IOBOX_HANDLER', 'MTI' => '//MTI_HANDLER'] as $type => $query) {
        foreach ($xpath->query($query) as $handler) {
            /** @var DOMElement $handler */
            $ipNode = $handler->getElementsByTagName('IPv4_ADDR')->item(0);
            $ip = $ipNode ? trim($ipNode->textContent) : '';
            $identifier = restore_ipv4_to_core_identifier($ip);
            if ($identifier !== null) {
                $identifiers[$type][$identifier] = true;
            }
        }
    }

    $errors = [];
    foreach ($xml->CHANNEL as $ch) {
        $type = strtoupper(trim((string)$ch->INTERFACE_TYPE));
        if ($type !== 'IOBOX' && $type !== 'MTI') {
            continue; // SDAQ/NOX identity is bus-based, not handler-IP-based
        }
        $iso = trim((string)$ch->ISO_CHANNEL);
        $identity = iso_parse_source_identity($type, trim((string)$ch->ANCHOR));
        if ($identity === null) {
            continue; // already reported by iso_validate_document()
        }
        $handlerIssue = restore_check_device_handler($type, $identity, $identifiers);
        if ($handlerIssue !== null) {
            $errors[] = [
                'code' => $handlerIssue['code'],
                'message' => "ISO_CHANNEL \"$iso\": " . $handlerIssue['detail'],
            ];
        }
    }
    return $errors;
}

function ftp_backup_validate_bundle_candidates(string $opcUa, string $morfeas, string $dtdDir): array
{
    // Validate raw final bytes; Restore must not normalize invalid XML first.
    $opcUaErrors = [];
    $xml = false;
    try {
        $xml = iso_validate_final_xml_bytes($opcUa, $dtdDir, true);
    } catch (ChannelConfigException $e) {
        $opcUaErrors[] = ['code' => $e->apiCode(), 'message' => $e->getMessage()];
    }

    $dom = new DOMDocument('1.0');
    libxml_use_internal_errors(true);
    $loaded = $dom->loadXML($morfeas);
    libxml_clear_errors();
    if (!$loaded) {
        $morfeasErrors = [['code' => 'invalid_document_structure', 'message' => 'Morfeas_Config.xml is not well-formed XML']];
    } else {
        // Report every violation in this candidate, not just the first one
        // (F-20 / plan §6.1: preflight must not stop at the first error).
        $morfeasErrors = log_config_collect_document_errors($dom, $dtdDir);
    }

    // Warnings are useful only after both files pass hard validation.
    $warnings = [];
    if (empty($opcUaErrors) && empty($morfeasErrors) && $xml !== false) {
        $warnings = array_merge(
            log_config_collect_document_warnings($dom),
            ftp_backup_check_bundle_handler_matching($xml, $dom)
        );
    }

    // Warnings require acknowledgement but do not reject Core-valid backups.
    return [
        'opc_ua' => ['valid' => empty($opcUaErrors), 'errors' => $opcUaErrors, 'channel_count' => $xml !== false ? count($xml->CHANNEL) : null],
        'morfeas' => ['valid' => empty($morfeasErrors), 'errors' => $morfeasErrors],
        'warnings' => $warnings,
        'can_commit' => empty($opcUaErrors) && empty($morfeasErrors),
    ];
}

/*
 * Write Morfeas_Config.xml first and OPC_UA_Config.xml last, so Core reloads
 * only after both candidates are in place. A failed second write restores the
 * first best-effort; a crash between renames is recovered by re-running Restore.
 */
function ftp_backup_apply_ordered_replace(string $opcUaContent, string $morfeasContent, string $xmlPath, string $logConfigPath): void
{
    $oldMorfeas = is_file($logConfigPath) ? @file_get_contents($logConfigPath) : '';
    if ($oldMorfeas === false) {
        $oldMorfeas = '';
    }

    backend_atomic_write_file_synced($logConfigPath, $morfeasContent, 0644);
    try {
        backend_atomic_write_file_synced($xmlPath, $opcUaContent, 0644);
    } catch (Throwable $e) {
        $rolledBack = false;
        try {
            backend_atomic_write_file_synced($logConfigPath, $oldMorfeas, 0644);
            $rolledBack = true;
        } catch (Throwable $ignored) {
            // best-effort only; fall through to the failure below either way
        }
        throw new ChannelConfigException(
            'Failed to write OPC_UA_Config.xml after Morfeas_Config.xml was already replaced'
                . ($rolledBack ? '; Morfeas_Config.xml was rolled back to its prior content' : '; Morfeas_Config.xml could NOT be rolled back and now reflects the new backup while OPC_UA_Config.xml does not')
                . '. Re-run this Restore to retry.',
            500,
            'ftp_restore_partial_failure'
        );
    }
    @shell_exec('sync'); // directory-entry durability for both renames; best-effort, not load-bearing for correctness
}

/*
 * Downloads one .mbl over FTP and returns its raw bytes. The only piece of
 * FTP restore that actually needs a real FTP server -- everything it feeds
 * into (ftp_backup_decode_bundle(), ftp_backup_validate_bundle_candidates(),
 * ftp_backup_apply_ordered_replace()) is unit-tested independently of this.
 */
function ftp_backup_download_raw(array $config, string $filename): string
{
    if (!ftp_backup_restore_filename_is_valid($filename)) {
        throw new InvalidArgumentException('Invalid restore file name');
    }

    $conn = ftp_backup_open_connection($config);
    $localFile = @tempnam(sys_get_temp_dir(), 'morfeas_ftp_restore_');
    if (!is_string($localFile) || $localFile === '') {
        @ftp_close($conn);
        throw new RuntimeException('Failed to allocate local restore temp file', 500);
    }

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
    @unlink($localFile);
    if (!is_string($raw) || $raw === '') {
        throw new RuntimeException('Downloaded backup file is empty or unreadable', 500);
    }

    return $raw;
}

/*
 * Read-only: downloads, decodes and validates both candidate documents
 * without writing anything. Mirrors Local JSON Restore's preflight/commit
 * split, at whole-file granularity since FTP is a full-config replace, not
 * a per-channel merge.
 *
 * The FTP round-trip happens with no lock held (a slow/unreachable server
 * must not block unrelated Add/Edit/Device requests); only the local-file
 * digest read at the end takes the same fixed-order lock pair commit() uses
 * (log_config, then opcua_config), so that digest describes one consistent
 * instant of the local files. It travels back to the caller as
 * local_config_digest and must be echoed into restore_commit() alongside
 * the remote-bytes digest -- the remote digest alone cannot detect a local
 * config edit that landed between preflight and commit.
 */
/*
 * Read the local snapshot digest under the same fixed-order lock pair
 * commit() uses (log_config, then opcua_config). Factored out of
 * ftp_backup_restore_preflight() so it can be exercised directly in unit
 * tests without a live FTP connection -- everything else preflight does
 * (download, decode, validate) requires the network round-trip that only
 * ftp_backup_restore_commit() has a test-time injection point for.
 */
function ftp_backup_local_config_digest_locked(string $xmlPath, string $logConfigPath): string
{
    return log_config_with_xml_lock(
        $logConfigPath,
        static function () use ($xmlPath, $logConfigPath) {
            return iso_with_xml_lock($xmlPath, static function () use ($xmlPath, $logConfigPath) {
                return restore_compute_digest($xmlPath, $logConfigPath);
            });
        }
    );
}

function ftp_backup_restore_preflight(string $filename, string $xmlPath, string $logConfigPath, string $dtdDir): array
{
    $config = ftp_backup_load_config_raw();
    $raw = ftp_backup_download_raw($config, $filename);
    $bundle = ftp_backup_decode_bundle($raw);
    $report = ftp_backup_validate_bundle_candidates($bundle['opc_ua'], $bundle['morfeas'], $dtdDir);

    return array_merge($report, [
        'filename' => $filename,
        'digest' => ftp_backup_restore_digest($filename, $raw),
        'local_config_digest' => ftp_backup_local_config_digest_locked($xmlPath, $logConfigPath),
    ]);
}

/*
 * Re-download and revalidate the reviewed bytes, then apply them. The
 * download and validation below do not touch local config state; only the
 * final ftp_backup_apply_ordered_replace() call needs the shared config
 * locks, so it is the only part that acquires them. Holding both locks for
 * the FTP network round-trip would block every unrelated Add/Edit/Device
 * request for as long as the remote server takes to respond.
 *
 * $expectedLocalConfigDigest is checked inside that same lock pair, right
 * before the write: it is the only thing that can catch a local config edit
 * (an unrelated Add/Edit/Device, or another Restore) that landed after
 * preflight computed its snapshot. The remote-bytes digest above cannot see
 * that -- it only proves the backup on the FTP server has not changed.
 */
function ftp_backup_restore_commit(
    string $filename,
    string $expectedDigest,
    string $expectedLocalConfigDigest,
    string $xmlPath,
    string $logConfigPath,
    string $dtdDir,
    bool $acknowledgeWarnings = false,
    ?callable $downloadRaw = null
): array
{
    if ($downloadRaw !== null) {
        $raw = $downloadRaw($filename);
        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('Restore download returned empty or unreadable content', 500);
        }
    } else {
        $config = ftp_backup_load_config_raw();
        $raw = ftp_backup_download_raw($config, $filename);
    }

    $actualDigest = ftp_backup_restore_digest($filename, $raw);
    if (!hash_equals($actualDigest, $expectedDigest)) {
        throw new ChannelConfigException(
            'The backup file on the FTP server changed since this was reviewed; please re-run the preflight check',
            409,
            'ftp_restore_candidate_changed'
        );
    }

    $bundle = ftp_backup_decode_bundle($raw);
    $report = ftp_backup_validate_bundle_candidates($bundle['opc_ua'], $bundle['morfeas'], $dtdDir);
    if (!$report['can_commit']) {
        $firstError = $report['opc_ua']['errors'][0] ?? $report['morfeas']['errors'][0] ?? null;
        throw new ChannelConfigException(
            'Backup candidate failed validation: ' . ($firstError['message'] ?? 'unknown error'),
            409,
            $firstError['code'] ?? 'invalid_document_structure'
        );
    }

    // Core-valid warnings require explicit acknowledgement from every caller.
    if (!empty($report['warnings']) && !$acknowledgeWarnings) {
        $count = count($report['warnings']);
        $first = $report['warnings'][0]['message'] ?? '';
        throw new ChannelConfigException(
            "This backup has $count warning(s) that must be acknowledged before restoring. First: $first"
                . ' Re-submit with acknowledge_warnings=true to proceed.',
            409,
            'ftp_restore_warnings_not_acknowledged'
        );
    }

    return log_config_with_xml_lock($logConfigPath, function () use ($bundle, $xmlPath, $logConfigPath, $filename, $expectedLocalConfigDigest) {
        return iso_with_xml_lock($xmlPath, function () use ($bundle, $xmlPath, $logConfigPath, $filename, $expectedLocalConfigDigest) {
            $actualLocalConfigDigest = restore_compute_digest($xmlPath, $logConfigPath);
            if (!hash_equals($actualLocalConfigDigest, $expectedLocalConfigDigest)) {
                throw new ChannelConfigException(
                    'The local configuration changed since this backup was reviewed; please re-run the preflight check',
                    409,
                    'ftp_restore_local_config_changed'
                );
            }

            ftp_backup_apply_ordered_replace($bundle['opc_ua'], $bundle['morfeas'], $xmlPath, $logConfigPath);

            @touch(ftp_backup_config_file());
            ftp_backup_log('INFO', "Restored from backup: $filename");

            return ['filename' => $filename];
        });
    });
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

    $health = ftp_backup_read_health();
    $configured = $config !== null;

    return [
        'configured' => $configured,
        'connected' => $configured && ($health['ok'] === true),
        'updated' => $updated,
        'config' => $config,
        'health' => $health,
    ];
}
