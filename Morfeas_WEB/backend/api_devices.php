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

            if (!devices_validate_name($name, $type)) {
                devices_fail(
                    $type === 'MTI'
                        ? 'name must be 1..' . log_config_dev_name_safe_max_length()
                            . ' chars, contain only letters, numbers and "_", and not start with a digit'
                            . ' (Core builds the MTI D-Bus interface name from it)'
                        : 'name must be 1..' . log_config_dev_name_safe_max_length()
                            . ' chars and contain only letters, numbers, "_" or "-"',
                    400
                );
            }
            if (!devices_validate_ipv4($ip)) {
                devices_fail('ip must be a valid IPv4 address', 400);
            }

            // Name/IP uniqueness is NOT checked here any more. It used to be,
            // against a snapshot read outside the log_config lock, which is
            // the F-15 race: two Adds could both pass it. The single
            // authoritative check now runs inside the lock in
            // log_config_append_device(), against the same DOM that is about
            // to be written, and raises the 409 from there.
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
} catch (ChannelConfigException $e) {
    // The in-lock validation and uniqueness failures added by F-15 carry
    // their own status and code; without this they would surface as a bare
    // 500 "Failed to process device request" and the operator would have no
    // idea which rule the name or IP broke.
    http_response_code($e->status());
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'code' => $e->apiCode(),
    ], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    api_fail_response('Failed to process device request', 500, 'api_devices', $e);
}
