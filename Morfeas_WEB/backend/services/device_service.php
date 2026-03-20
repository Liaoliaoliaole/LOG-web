<?php

require_once __DIR__ . '/../repositories/log_config_repository.php';
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

function device_list(string $ramdisk, string $logConfig, int $maxComponents): array
{
    $auto   = device_collect_sdaq_devices($ramdisk);
    $manual = array_values(array_filter(
        log_config_load_manual_devices($logConfig),
        static function (array $dev): bool {
            $type = strtoupper(str_replace(['-', '_', ' '], '', trim((string) ($dev['type'] ?? ''))));
            return $type !== 'MDAQ';
        }
    ));
    $all    = array_merge($manual, $auto);

    return [
        'ok'         => true,
        'data'       => $all,
        'components' => [
            'total' => log_config_count_components($logConfig),
            'max'   => $maxComponents,
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
