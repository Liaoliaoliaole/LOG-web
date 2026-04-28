<?php

require_once __DIR__ . '/../repositories/log_config_repository.php';
require_once __DIR__ . '/../repositories/logstat_repository.php';
require_once __DIR__ . '/../core/nox_runtime.php';

const DEVICE_LEGACY_MDAQ_MESSAGE = 'Legacy MDAQ config found in XML. Remove it manually before using this page.';

function device_restart_morfeas_core(): void
{
    $output = [];
    $code = 0;
    exec('sudo -n /bin/systemctl restart Morfeas_system.service 2>&1', $output, $code);

    if ($code !== 0) {
        $details = trim(implode("\n", $output));
        if ($details === '') {
            $details = 'unknown error';
        }
        throw new RuntimeException('Failed to restart Morfeas_system.service: ' . $details);
    }
}

function device_collect_sdaq_devices(string $ramdisk): array
{
    $paths = glob($ramdisk . 'logstat*.json') ?: [];

    $devices = [];

    foreach ($paths as $path) {
        if (!is_file($path)) continue;

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') continue;

        $data = json_decode($raw, true);
        if (!is_array($data)) continue;

        $list = $data['SDAQs_data'] ?? null;
        if (empty($list) || !is_array($list)) continue;

        $busRaw = $data['CANBus_interface'] ?? '';

        if ($busRaw === '') {
            $basename = basename($path);
            if (preg_match('/logstat[^_]*_SDAQs_([^\.]+)\.json$/i', $basename, $m)) {
                $busRaw = $m[1];
            }
        }

        if ($busRaw === '') {
            $busRaw = 'can0';
        }
        $busKey = strtolower((string)$busRaw);
        $busDisplay = $busRaw === '' ? '-' : $busRaw;

        foreach ($list as $sdaq) {
            if (!is_array($sdaq)) continue;

            $addr = $sdaq['Address'] ?? null;
            if ($addr === null || $addr === '') continue;

            $statusArr = $sdaq['SDAQ_Status'] ?? [];
            $status = !empty($statusArr['Error']) ? 'Error' : 'Okay';

            $type = $sdaq['SDAQ_type'] ?? 'SDAQ';
            $name = (string)$addr;

            $id = sprintf('SDAQ:%s:%s', $busKey, $addr);
            $devices[$id] = [
                'id'     => $id,
                'type'   => $type,
                'bus'    => $busDisplay,
                'ip'     => '',
                'name'   => $name,
                'status' => $status,
                'origin' => 'auto',
            ];
        }
    }

    return array_values($devices);
}

function device_normalize_runtime_status(?string $status, bool $detected = true): string
{
    $raw = trim((string)$status);
    if ($raw === '') {
        return $detected ? 'Connected' : 'Not detected';
    }

    $lower = strtolower($raw);
    if ($lower === 'okay') {
        return 'Connected';
    }

    if (in_array($lower, ['off-line', 'offline', 'disconnected'], true)) {
        return 'Not connected';
    }

    return $raw;
}

function device_build_runtime_maps(string $ramdisk): array
{
    $ipDevices = [];
    $noxBuses = [];

    $ioboxPaths = logstat_collect_paths('logstat_IOBOX*.json', $ramdisk);
    foreach ($ioboxPaths as $jsonPath) {
        $data = logstat_load_json($jsonPath);
        if (!is_array($data)) {
            continue;
        }

        $identifier = trim((string)($data['Identifier'] ?? ''));
        $ipv4 = trim((string)($data['IPv4_address'] ?? ''));
        $status = trim((string)($data['Connection_status'] ?? ''));
        $entry = [
            'type' => 'IOBOX',
            'identifier' => $identifier,
            'ip' => $ipv4,
            'runtime_status' => device_normalize_runtime_status($status, true),
            'detected' => true,
        ];

        if ($identifier !== '') {
            $ipDevices['IOBOX']['name:' . strtoupper($identifier)] = $entry;
        }
        if ($ipv4 !== '') {
            $ipDevices['IOBOX']['ip:' . $ipv4] = $entry;
        }
    }

    $mtiPaths = logstat_collect_paths('logstat_MTI*.json', $ramdisk);
    foreach ($mtiPaths as $jsonPath) {
        $data = logstat_load_json($jsonPath);
        if (!is_array($data)) {
            continue;
        }

        $identifier = trim((string)($data['Identifier'] ?? ''));
        $ipv4 = trim((string)($data['IPv4_address'] ?? ''));
        $status = trim((string)($data['Connection_status'] ?? ''));
        $entry = [
            'type' => 'MTI',
            'identifier' => $identifier,
            'ip' => $ipv4,
            'runtime_status' => device_normalize_runtime_status($status, true),
            'detected' => true,
        ];

        if ($identifier !== '') {
            $ipDevices['MTI']['name:' . strtoupper($identifier)] = $entry;
        }
        if ($ipv4 !== '') {
            $ipDevices['MTI']['ip:' . $ipv4] = $entry;
        }
    }

    $noxPaths = logstat_collect_paths('logstat_NOX*.json', $ramdisk);
    foreach ($noxPaths as $jsonPath) {
        $data = logstat_load_json($jsonPath);
        if (!is_array($data)) {
            continue;
        }

        $bus = strtolower(trim((string)($data['CANBus_interface'] ?? '')));
        if ($bus === '') {
            continue;
        }

        $detected = nox_runtime_bus_detected($data);
        $noxBuses[$bus] = [
            'detected' => $detected,
            'runtime_status' => $detected ? 'Connected' : 'Not detected',
        ];
    }

    return [
        'ip_devices' => $ipDevices,
        'nox_buses' => $noxBuses,
    ];
}

function device_merge_manual_runtime_status(array $manual, array $runtimeMaps): array
{
    $ipDevices = $runtimeMaps['ip_devices'] ?? [];
    $noxBuses = $runtimeMaps['nox_buses'] ?? [];

    foreach ($manual as &$dev) {
        $type = strtoupper(str_replace(['-', '_', ' '], '', trim((string)($dev['type'] ?? ''))));
        $status = trim((string)($dev['status'] ?? ''));
        $disabled = strtolower($status) === 'disabled';

        if ($disabled) {
            $dev['runtime_status'] = 'Disabled';
            $dev['detected'] = false;
            continue;
        }

        if ($type === 'NOX') {
            $bus = strtolower(trim((string)($dev['bus'] ?? '')));
            $runtime = $bus !== '' ? ($noxBuses[$bus] ?? null) : null;
            $dev['runtime_status'] = $runtime['runtime_status'] ?? 'Not detected';
            $dev['detected'] = (bool)($runtime['detected'] ?? false);
            continue;
        }

        if (!in_array($type, ['IOBOX', 'MTI'], true)) {
            $dev['runtime_status'] = $status !== '' ? $status : 'Configured';
            $dev['detected'] = false;
            continue;
        }

        $name = strtoupper(trim((string)($dev['name'] ?? '')));
        $ip = trim((string)($dev['ip'] ?? ''));
        $runtime = null;

        if ($name !== '' && !empty($ipDevices[$type]['name:' . $name])) {
            $runtime = $ipDevices[$type]['name:' . $name];
        } elseif ($ip !== '' && !empty($ipDevices[$type]['ip:' . $ip])) {
            $runtime = $ipDevices[$type]['ip:' . $ip];
        }

        $dev['runtime_status'] = $runtime['runtime_status'] ?? 'Not detected';
        $dev['detected'] = (bool)($runtime['detected'] ?? false);
    }
    unset($dev);

    return $manual;
}

function device_list(string $ramdisk, string $logConfig): array
{
    $auto      = device_collect_sdaq_devices($ramdisk);
    $xmlConfig = log_config_read_all($logConfig);
    $manual    = device_merge_manual_runtime_status($xmlConfig['manual_devices'], device_build_runtime_maps($ramdisk));
    $all       = array_merge($manual, $auto);
    $legacyMdaqPresent = $xmlConfig['has_legacy_mdaq'];

    return [
        'ok'         => true,
        'data'       => $all,
        'components' => [
            'total' => $xmlConfig['component_count'],
        ],
        'legacy' => [
            'mdaq_present' => $legacyMdaqPresent,
            'blocking' => $legacyMdaqPresent,
            'message' => $legacyMdaqPresent ? DEVICE_LEGACY_MDAQ_MESSAGE : null,
        ],
    ];
}

function device_add(string $logConfig, array $payload): array
{
    $device = log_config_append_device($logConfig, $payload);
    device_restart_morfeas_core();
    return $device;
}

function device_delete(string $logConfig, array $ids): void
{
    log_config_delete_devices($logConfig, $ids);
    device_restart_morfeas_core();
}
