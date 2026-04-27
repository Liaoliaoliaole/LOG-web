<?php

require __DIR__ . '/core/paths.php';
require __DIR__ . '/core/request.php';
require __DIR__ . '/services/device_service.php';

header('Content-Type: application/json; charset=utf-8');

$logConfig  = backend_log_config_path();
$ramdisk    = backend_ramdisk_dir();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function devices_legacy_mdaq_message(): string
{
    return defined('DEVICE_LEGACY_MDAQ_MESSAGE')
        ? DEVICE_LEGACY_MDAQ_MESSAGE
        : 'Legacy MDAQ config found in XML. Remove it manually before using this page.';
}

function devices_fail(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_PRETTY_PRINT);
    exit;
}

function devices_normalize_type($raw): string
{
    return strtoupper(str_replace(['-', '_', ' '], '', trim((string) $raw)));
}

function devices_normalize_bus($raw): string
{
    return strtolower(trim((string) $raw));
}

function devices_validate_name(string $name): bool
{
    if ($name === '') {
        return false;
    }
    if (strlen($name) > 64) {
        return false;
    }
    return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name) === 1;
}

function devices_validate_ipv4(string $ip): bool
{
    if ($ip === '') {
        return false;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return false;
    }
    if ($ip === '0.0.0.0' || $ip === '255.255.255.255') {
        return false;
    }
    return true;
}

function devices_find_conflict(
    string $name,
    string $ip,
    array $manualDevices
): ?string {
    foreach ($manualDevices as $dev) {
        $existingType = devices_normalize_type($dev['type'] ?? '');
        if (!in_array($existingType, ['IOBOX', 'MTI'], true)) {
            continue;
        }
        $existingName = trim((string) ($dev['name'] ?? ''));
        $existingIp = trim((string) ($dev['ip'] ?? ''));

        if (strcasecmp($existingName, $name) === 0) {
            return "Device name already exists: $name";
        }
        if ($existingIp !== '' && $existingIp === $ip) {
            return "Device IPv4 already exists: $ip";
        }
    }

    return null;
}

function devices_normalize_delete_ids($rawIds): array
{
    if (!is_array($rawIds)) {
        return [];
    }
    $ids = array_map(static fn ($id) => trim((string) $id), $rawIds);
    return array_values(array_filter($ids, static fn ($id) => $id !== ''));
}

try {
    switch ($method) {
        case 'GET':
            $payload = device_list($ramdisk, $logConfig);
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            break;

        case 'POST':
            if (log_config_has_legacy_mdaq($logConfig)) {
                devices_fail(devices_legacy_mdaq_message(), 409);
            }

            $body = read_json_body();

            $type = devices_normalize_type($body['type'] ?? '');
            $bus  = '-';
            $name = trim((string) ($body['name'] ?? ''));
            $ip   = trim((string) ($body['ip'] ?? ''));

            if ($type === '') {
                devices_fail('type is required', 400);
            }
            if ($type === 'SDAQ') {
                devices_fail('SDAQ is auto-discovered from logstat', 400);
            }
            if ($type === 'NOX') {
                devices_fail('NOX is managed via CAN bus role transition', 400);
            }
            if (!in_array($type, ['IOBOX', 'MTI'], true)) {
                devices_fail("unsupported type: $type", 400);
            }

            if (!devices_validate_name($name)) {
                devices_fail('name must be 1..64 chars and contain only letters, numbers, "_" or "-"', 400);
            }
            if (!devices_validate_ipv4($ip)) {
                devices_fail('ip must be a valid IPv4 address', 400);
            }

            $manualDevices = log_config_load_manual_devices($logConfig);
            $conflict = devices_find_conflict($name, $ip, $manualDevices);
            if ($conflict !== null) {
                devices_fail($conflict, 409);
            }

            $device = device_add($logConfig, [
                'type' => $type,
                'bus'  => $bus,
                'name' => $name,
                'ip'   => $ip,
            ]);

            echo json_encode(['ok' => true, 'data' => $device], JSON_PRETTY_PRINT);
            break;

        case 'DELETE':
            if (log_config_has_legacy_mdaq($logConfig)) {
                devices_fail(devices_legacy_mdaq_message(), 409);
            }

            $body = read_json_body();
            $ids = devices_normalize_delete_ids($body['ids'] ?? []);
            if (empty($ids)) {
                devices_fail('ids[] is required', 400);
            }

            device_delete($logConfig, $ids);
            echo json_encode(['ok' => true], JSON_PRETTY_PRINT);
            break;

        default:
            http_response_code(405);
            header('Allow: GET, POST, DELETE');
            echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_PRETTY_PRINT);
    }
} catch (Throwable $e) {
    api_fail_response('Failed to process device request', 500, 'api_devices', $e);
}
