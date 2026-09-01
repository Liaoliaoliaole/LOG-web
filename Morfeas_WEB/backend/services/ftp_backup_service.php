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
 * Warn when an IOBOX/MTI/NOX channel lacks a same-type ENABLED handler in
 * this bundle. It remains restorable because Core loads all of these
 * configs unmodified (offline orphan definitions and disabled handlers
 * alike). Shares restore_check_device_handler() with Local JSON Restore
 * : building the identifier maps is the only bundle-vs-local-file
 * difference between the two paths; the match/warn logic itself must not
 * drift between them.
 */
function ftp_backup_check_bundle_handler_matching(SimpleXMLElement $xml, DOMDocument $morfeasDom): array
{
    $identifiers = ['IOBOX' => [], 'MTI' => [], 'NOX' => []];
    $xpath = new DOMXPath($morfeasDom);
    foreach (['IOBOX' => '//IOBOX_HANDLER', 'MTI' => '//MTI_HANDLER'] as $type => $query) {
        foreach ($xpath->query($query) as $handler) {
            /** @var DOMElement $handler */
            $enabled = strtolower($handler->getAttribute('Disable')) !== 'true';
            $ipNode = $handler->getElementsByTagName('IPv4_ADDR')->item(0);
            $ip = $ipNode ? trim($ipNode->textContent) : '';
            $identifier = restore_ipv4_to_core_identifier($ip);
            if ($identifier !== null) {
                $identifiers[$type][$identifier] = $enabled;
            }
        }
    }
    foreach ($xpath->query('//NOX_HANDLER') as $handler) {
        /** @var DOMElement $handler */
        $enabled = strtolower($handler->getAttribute('Disable')) !== 'true';
        $busNode = $handler->getElementsByTagName('CANBUS_IF')->item(0);
        // RAW case preserved -- see restore_load_device_identifiers()'s
        // docblock for why NOX bus casing must not be normalized here.
        $bus = $busNode ? trim($busNode->textContent) : '';
        if ($bus !== '') {
            $identifiers['NOX'][$bus] = $enabled;
        }
    }

    $errors = [];
    foreach ($xml->CHANNEL as $ch) {
        $type = strtoupper(trim((string)$ch->INTERFACE_TYPE));
        if ($type !== 'IOBOX' && $type !== 'MTI' && $type !== 'NOX') {
            continue; // SDAQ identity is bus-based with no bus in the anchor -- no precise per-channel check possible
        }
        $iso = trim((string)$ch->ISO_CHANNEL);
        $identity = iso_parse_source_identity($type, trim((string)$ch->ANCHOR));
        if ($identity === null) {
            continue; // already reported by iso_validate_document()
        }
        $handlerIssue = restore_check_device_handler($type, $identity, $identifiers, "this backup's Morfeas_Config.xml");
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
    // Structure (well-formed, root element, DOCTYPE, DTD) stays all-or-nothing,
    // same as log_config_validate_dtd_structure() on the Morfeas side below --
    // there is nothing to enumerate CHANNEL-level violations across until the
    // document is at least that well-formed. Once past it, every semantic
    // violation is collected instead of stopping at the first one.
    $opcUaErrors = [];
    $xml = false;
    try {
        $xml = iso_validate_final_xml_structure($opcUa, $dtdDir, true);
        $opcUaErrors = iso_collect_document_errors($xml);
    } catch (ChannelConfigException $e) {
        $opcUaErrors[] = ['code' => $e->apiCode(), 'message' => $e->getMessage()];
        $xml = false;
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
 * Read the local channels/handlers/digest under the same fixed-order lock
 * pair commit() uses (log_config, then opcua_config), as one snapshot --
 * so the impact report built from it and the digest that guards it always
 * describe the same instant. Factored out of ftp_backup_restore_preflight()
 * so it can be exercised directly in unit tests without a live FTP
 * connection -- everything else preflight does (download, decode, validate)
 * requires the network round-trip that only ftp_backup_restore_commit() has
 * a test-time injection point for.
 */
function ftp_backup_local_config_snapshot_locked(string $xmlPath, string $logConfigPath): array
{
    return log_config_with_xml_lock(
        $logConfigPath,
        static function () use ($xmlPath, $logConfigPath) {
            return iso_with_xml_lock($xmlPath, static function () use ($xmlPath, $logConfigPath) {
                return [
                    'digest' => restore_compute_digest($xmlPath, $logConfigPath),
                    'channels' => iso_load_channels($xmlPath),
                    'handlers' => log_config_load_manual_devices($logConfigPath),
                ];
            });
        }
    );
}

/* Thin convenience wrapper for callers that only need the digest. */
function ftp_backup_local_config_digest_locked(string $xmlPath, string $logConfigPath): string
{
    return ftp_backup_local_config_snapshot_locked($xmlPath, $logConfigPath)['digest'];
}

/*
 * Index IOBOX/MTI/NOX/SDAQ handlers (as returned by
 * log_config_load_manual_devices() for the local side, or
 * ftp_backup_bundle_handler_inventory() for the bundle side) by a
 * type+identity key -- IPv4 for IOBOX/MTI, CAN bus for NOX/SDAQ -- the same
 * identity restore_check_device_handler() already uses to match a channel
 * to a handler. Prefixing the key with $type is what keeps a NOX and a SDAQ
 * handler on the same CANBUS_IF from colliding in THIS inventory; that does
 * not depend on Core's own bus-uniqueness rule
 * (log_config_validate_can_bus_usage(), which only rejects the case where
 * BOTH are simultaneously enabled -- a disabled one sharing a bus with an
 * enabled one is legal). One shared key scheme is what lets local and
 * bundle inventories be diffed against each other at all.
 */
function ftp_backup_handler_inventory_key(string $type, string $ip, string $bus): string
{
    return in_array($type, ['NOX', 'SDAQ'], true) ? "$type:$bus" : "$type:$ip";
}

function ftp_backup_handler_inventory_from_devices(array $devices): array
{
    $out = [];
    foreach ($devices as $d) {
        $type = (string)($d['type'] ?? '');
        if (!in_array($type, ['IOBOX', 'MTI', 'NOX', 'SDAQ'], true)) {
            continue; // MDAQ_HANDLER is a retired interface, out of scope for this report
        }
        $ip = (string)($d['ip'] ?? '');
        $bus = (string)($d['bus'] ?? '');
        $key = ftp_backup_handler_inventory_key($type, $ip, $bus);
        $out[$key] = [
            'type' => $type,
            'ip' => $ip,
            'bus' => $bus,
            'name' => (string)($d['name'] ?? ''),
            'disabled' => (string)($d['status'] ?? '') === 'Disabled',
            'i2c_bus_num' => (string)($d['i2c_bus_num'] ?? ''),
        ];
    }
    return $out;
}

/* Same inventory shape as ftp_backup_handler_inventory_from_devices(), read from a bundle's own Morfeas_Config DOM instead of a local file. */
function ftp_backup_bundle_handler_inventory(DOMDocument $morfeasDom): array
{
    $out = [];
    $xpath = new DOMXPath($morfeasDom);
    $queries = ['IOBOX' => '//IOBOX_HANDLER', 'MTI' => '//MTI_HANDLER', 'NOX' => '//NOX_HANDLER', 'SDAQ' => '//SDAQ_HANDLER'];
    foreach ($queries as $type => $query) {
        foreach ($xpath->query($query) as $handler) {
            /** @var DOMElement $handler */
            $disabled = strtolower($handler->getAttribute('Disable')) === 'true';
            if ($type === 'NOX' || $type === 'SDAQ') {
                $busNode = $handler->getElementsByTagName('CANBUS_IF')->item(0);
                $bus = $busNode ? trim($busNode->textContent) : '';
                $ip = '';
                $name = $bus;
                $i2cNode = $handler->getElementsByTagName('I2CBUS_NUM')->item(0);
                $i2c = $i2cNode ? trim($i2cNode->textContent) : '';
            } else {
                $ipNode = $handler->getElementsByTagName('IPv4_ADDR')->item(0);
                $nameNode = $handler->getElementsByTagName('DEV_NAME')->item(0);
                $ip = $ipNode ? trim($ipNode->textContent) : '';
                $bus = '-'; // matches log_config_load_manual_devices()'s IOBOX/MTI placeholder, so the "same" comparison isn't tripped by a '' vs '-' mismatch
                $name = $nameNode ? trim($nameNode->textContent) : '';
                $i2c = '';
            }
            $key = ftp_backup_handler_inventory_key($type, $ip, $bus);
            $out[$key] = ['type' => $type, 'ip' => $ip, 'bus' => $bus, 'name' => $name, 'disabled' => $disabled, 'i2c_bus_num' => $i2c];
        }
    }
    return $out;
}

/*
 * Add/Replace/Unchanged/Remove for one identity-keyed inventory (handlers,
 * or -- via ftp_backup_channel_inventory_*() below -- channels). FTP Restore
 * replaces the whole file, so anything present only on the local side is
 * being deleted, not merely left alone; that must be named, not silently
 * implied by its absence from the report. A Replace row also carries
 * `before` (the current local values) and `changed_fields` (which of them
 * differ) -- without that, "Replace" only shows the bundle's final state,
 * leaving the operator unable to tell what is actually about to change.
 */
function ftp_backup_classify_inventory(array $localByKey, array $bundleByKey, callable $sameFn, callable $changedFieldsFn): array
{
    $keys = array_unique(array_merge(array_keys($localByKey), array_keys($bundleByKey)));
    sort($keys);

    $rows = [];
    foreach ($keys as $key) {
        $local = $localByKey[$key] ?? null;
        $bundle = $bundleByKey[$key] ?? null;
        if ($local === null) {
            $rows[] = $bundle + ['result' => 'Add', 'before' => null, 'changed_fields' => []];
        } elseif ($bundle === null) {
            $rows[] = $local + ['result' => 'Remove', 'before' => null, 'changed_fields' => []];
        } elseif ($sameFn($local, $bundle)) {
            $rows[] = $bundle + ['result' => 'Unchanged', 'before' => null, 'changed_fields' => []];
        } else {
            $rows[] = $bundle + ['result' => 'Replace', 'before' => $local, 'changed_fields' => $changedFieldsFn($local, $bundle)];
        }
    }
    return $rows;
}

function ftp_backup_handler_fields_changed(array $local, array $bundle): array
{
    $changed = [];
    foreach (['name', 'ip', 'bus', 'i2c_bus_num'] as $f) {
        if ((string)($local[$f] ?? '') !== (string)($bundle[$f] ?? '')) {
            $changed[] = $f;
        }
    }
    if ((bool)($local['disabled'] ?? false) !== (bool)($bundle['disabled'] ?? false)) {
        $changed[] = 'disabled';
    }
    return $changed;
}

function ftp_backup_classify_handlers(array $localDevices, DOMDocument $bundleMorfeasDom): array
{
    $localByKey = ftp_backup_handler_inventory_from_devices($localDevices);
    $bundleByKey = ftp_backup_bundle_handler_inventory($bundleMorfeasDom);
    return ftp_backup_classify_inventory(
        $localByKey,
        $bundleByKey,
        static function (array $local, array $bundle): bool {
            return ftp_backup_handler_fields_changed($local, $bundle) === [];
        },
        'ftp_backup_handler_fields_changed'
    );
}

function ftp_backup_channel_inventory_by_iso(array $channelRows): array
{
    $out = [];
    foreach ($channelRows as $row) {
        $key = iso_normalize_iso_channel((string)($row['iso_channel'] ?? ''));
        if ($key !== null && $key !== '') {
            $out[$key] = $row;
        }
    }
    return $out;
}

/*
 * Field equality matching restore_entry_matches_existing()'s own two
 * comparators exactly -- $strEq (plain trimmed string equality) and $numEq
 * (numeric-aware, so "0" and "0.0" are not a Replace-worthy difference).
 * Applying $numEq's numeric-aware rule to a $strEq field would be wrong in
 * the dangerous direction: DESCRIPTION "001" and "1" are numerically equal
 * but restore_entry_matches_existing() -- via $strEq -- correctly calls
 * that a Replace, so changed_fields must be able to name it too, not go
 * quiet on a row the row-level verdict already flagged as different.
 */
function ftp_backup_string_field_changed($a, $b): bool
{
    return trim((string)($a ?? '')) !== trim((string)($b ?? ''));
}

function ftp_backup_numeric_field_changed($a, $b): bool
{
    $an = ($a === null || $a === '') ? '0' : $a;
    $bn = ($b === null || $b === '') ? '0' : $b;
    if (is_numeric($an) && is_numeric($bn)) {
        return (float)$an !== (float)$bn;
    }
    return (string)$a !== (string)$b; // mirrors $numEq's own non-numeric fallback exactly
}

function ftp_backup_channel_fields_changed(array $local, array $bundle): array
{
    $changed = [];
    if ($local['interface_type'] !== $bundle['interface_type']) {
        $changed[] = 'interface_type';
    }
    if ($local['anchor'] !== $bundle['anchor']) {
        $changed[] = 'anchor';
    }

    // Same field-by-field split as restore_entry_matches_existing(): these
    // go through $strEq there, not $numEq.
    $stringFields = ['description', 'alarm_high', 'alarm_low'];
    $numericFields = ['min', 'max', 'alarm_high_val', 'alarm_low_val'];
    $interfaceType = (string)($bundle['interface_type'] ?? $local['interface_type'] ?? '');
    if ($interfaceType !== 'SDAQ') {
        // SDAQ's UNIT/CAL_DATE/CAL_PERIOD are runtime-owned (see
        // restore_entry_matches_existing()); listing them here would
        // contradict the "same" verdict that already ignores them.
        $stringFields[] = 'unit';
        $stringFields[] = 'cal_date';
        $numericFields[] = 'cal_period';
    }

    foreach ($stringFields as $f) {
        if (ftp_backup_string_field_changed($local[$f] ?? null, $bundle[$f] ?? null)) {
            $changed[] = $f;
        }
    }
    foreach ($numericFields as $f) {
        if (ftp_backup_numeric_field_changed($local[$f] ?? null, $bundle[$f] ?? null)) {
            $changed[] = $f;
        }
    }
    return $changed;
}

/*
 * Same/different uses restore_entry_matches_existing() -- the same field
 * comparison Local JSON Restore's "No change" vs "Update metadata" uses,
 * which already knows SDAQ's UNIT/CAL_DATE/CAL_PERIOD are runtime-owned and
 * must not be compared. Identity (INTERFACE_TYPE, ANCHOR) is compared
 * separately first: two rows can share an ISO_CHANNEL key while binding it
 * to a different source, which is a Replace, not merely a metadata update.
 */
function ftp_backup_classify_channels(array $localChannels, SimpleXMLElement $bundleXml): array
{
    $bundleChannelRows = [];
    foreach ($bundleXml->CHANNEL as $ch) {
        $bundleChannelRows[] = iso_channel_snapshot($ch);
    }

    $localByIso = ftp_backup_channel_inventory_by_iso($localChannels);
    $bundleByIso = ftp_backup_channel_inventory_by_iso($bundleChannelRows);

    return ftp_backup_classify_inventory(
        $localByIso,
        $bundleByIso,
        static function (array $local, array $bundle): bool {
            return $local['interface_type'] === $bundle['interface_type']
                && $local['anchor'] === $bundle['anchor']
                && restore_entry_matches_existing($local, $bundle, $bundle['interface_type']);
        },
        'ftp_backup_channel_fields_changed'
    );
}

function ftp_backup_summarize_impact(array $rows): array
{
    $summary = ['add' => 0, 'replace' => 0, 'unchanged' => 0, 'remove' => 0];
    foreach ($rows as $row) {
        $key = strtolower((string)($row['result'] ?? ''));
        if (isset($summary[$key])) {
            $summary[$key]++;
        }
    }
    return $summary;
}

/*
 * The full local-vs-bundle impact report: what FTP Restore will actually
 * Add/Replace/Remove/leave Unchanged, for both ISO channels and
 * IOBOX/MTI/NOX/SDAQ device handlers. This answers a different question
 * than ftp_backup_check_bundle_handler_matching()'s warnings, which are
 * about the bundle's OWN internal consistency (a channel vs a handler both
 * inside the same candidate) -- this compares the candidate against what is
 * on this machine right now. Only meaningful once both documents are
 * known-valid, since it re-parses the bundle strings directly. MDAQ_HANDLER
 * is intentionally excluded because the interface is retired and outside
 * the supported impact-report scope; FTP Restore continues to carry it
 * byte-for-byte as part of the ordinary full-file replace, exactly as
 * before this report existed.
 */
function ftp_backup_build_impact_report(array $localChannels, array $localHandlers, string $bundleOpcUa, string $bundleMorfeas): array
{
    $bundleXml = simplexml_load_string($bundleOpcUa);
    $bundleDom = new DOMDocument('1.0');
    $bundleDom->loadXML($bundleMorfeas);

    $channelRows = ftp_backup_classify_channels($localChannels, $bundleXml);
    $handlerRows = ftp_backup_classify_handlers($localHandlers, $bundleDom);

    return [
        'channels' => $channelRows,
        'channel_summary' => ftp_backup_summarize_impact($channelRows),
        'handlers' => $handlerRows,
        'handler_summary' => ftp_backup_summarize_impact($handlerRows),
    ];
}

/*
 * Read-only: downloads, decodes and validates both candidate documents
 * without writing anything. Mirrors Local JSON Restore's preflight/commit
 * split, at whole-file granularity since FTP is a full-config replace, not
 * a per-channel merge.
 *
 * The FTP round-trip happens with no lock held (a slow/unreachable server
 * must not block unrelated Add/Edit/Device requests); only the local
 * snapshot read at the end takes the same fixed-order lock pair commit()
 * uses (log_config, then opcua_config), so the impact report and the digest
 * that guards it both describe one consistent instant of the local files.
 * local_config_digest must be echoed into restore_commit() alongside the
 * remote-bytes digest -- the remote digest alone cannot detect a local
 * config edit that landed between preflight and commit. impact is null
 * when the candidate fails hard validation: there is nothing meaningful to
 * diff against a document that cannot be restored anyway.
 */
function ftp_backup_restore_preflight(string $filename, string $xmlPath, string $logConfigPath, string $dtdDir): array
{
    $config = ftp_backup_load_config_raw();
    $raw = ftp_backup_download_raw($config, $filename);
    $bundle = ftp_backup_decode_bundle($raw);
    $report = ftp_backup_validate_bundle_candidates($bundle['opc_ua'], $bundle['morfeas'], $dtdDir);

    $local = ftp_backup_local_config_snapshot_locked($xmlPath, $logConfigPath);

    $impact = $report['can_commit']
        ? ftp_backup_build_impact_report($local['channels'], $local['handlers'], $bundle['opc_ua'], $bundle['morfeas'])
        : null;

    return array_merge($report, [
        'filename' => $filename,
        'digest' => ftp_backup_restore_digest($filename, $raw),
        'local_config_digest' => $local['digest'],
        'impact' => $impact,
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

            // Recomputed fresh from the files this lock is holding, exactly
            // like the digest check just above -- never trust the impact
            // report the browser is holding, only what commit itself can
            // still see right before it writes.
            $impact = ftp_backup_build_impact_report(
                iso_load_channels($xmlPath),
                log_config_load_manual_devices($logConfigPath),
                $bundle['opc_ua'],
                $bundle['morfeas']
            );

            ftp_backup_apply_ordered_replace($bundle['opc_ua'], $bundle['morfeas'], $xmlPath, $logConfigPath);

            @touch(ftp_backup_config_file());
            ftp_backup_log('INFO', "Restored from backup: $filename");

            return ['filename' => $filename, 'impact' => $impact];
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
