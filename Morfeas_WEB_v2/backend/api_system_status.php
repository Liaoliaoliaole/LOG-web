<?php
// backend/api_system_status.php
// 提供 System Status（Details）数据：合并 ramdisk 与 sandbox 的 logstat。

header('Content-Type: application/json');

$ramdisk    = '/mnt/ramdisk/';
$sandboxDir = __DIR__ . '/config_sandbox/';

$action = $_GET['action'] ?? 'details';
if ($action !== 'details') {
    echo json_encode(['ok' => false, 'error' => 'Unknown action'], JSON_PRETTY_PRINT);
    exit;
}

function collect_paths(string $pattern, string $sandboxDir, string $ramdisk): array
{
    $sandbox = glob($sandboxDir . $pattern) ?: [];
    $ram     = glob($ramdisk . $pattern) ?: [];

    // sandbox 先，ramdisk 后，确保实时数据覆盖样本
    return array_values(array_unique(array_merge($sandbox, $ram)));
}

function detect_bus(array $data, string $path): string
{
    if (!empty($data['CANBus_interface']) && is_string($data['CANBus_interface'])) {
        return strtoupper($data['CANBus_interface']);
    }

    if (preg_match('/logstat_sdaq.*_(can\w+)/i', basename($path), $m)) {
        return strtoupper($m[1]);
    }

    return 'CAN1';
}

function load_json(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function build_sdaq_entry(array $data, string $path): array
{
    $bus   = detect_bus($data, $path);
    $entry = [
        'if_name'     => sprintf('SDAQs (%s)', strtolower($bus)),
        'connections' => [],
    ];

    $entry['connections'][] = ['name' => 'BUS_Utilization', 'value' => $data['BUS_Utilization'] ?? null, 'unit' => '%'];
    $entry['connections'][] = ['name' => 'BUS_Error_Rate', 'value' => $data['BUS_Error_rate'] ?? null, 'unit' => '%'];
    $entry['connections'][] = ['name' => 'Detected_SDAQs', 'value' => $data['Detected_SDAQs'] ?? null, 'unit' => ''];
    $entry['connections'][] = ['name' => 'Incomplete_SDAQs', 'value' => $data['Incomplete_SDAQs'] ?? null, 'unit' => ''];

    if (!empty($data['Electrics']) && is_array($data['Electrics'])) {
        $elec = $data['Electrics'];
        $entry['connections'][] = [
            'name'  => sprintf('SDAQnet_(%s)_last_calibration_UNIX', strtolower($bus)),
            'value' => $elec['Last_calibration_UNIX'] ?? null,
            'unit'  => '',
        ];
        $entry['connections'][] = [
            'name'  => sprintf('SDAQnet_(%s)_outVoltage', strtolower($bus)),
            'value' => $elec['BUS_voltage'] ?? null,
            'unit'  => 'V',
        ];
        $entry['connections'][] = [
            'name'  => sprintf('SDAQnet_(%s)_outAmperage', strtolower($bus)),
            'value' => $elec['BUS_amperage'] ?? null,
            'unit'  => 'A',
        ];
        $entry['connections'][] = [
            'name'  => sprintf('SDAQnet_(%s)_ShuntTemp', strtolower($bus)),
            'value' => $elec['BUS_Shunt_Res_temp'] ?? null,
            'unit'  => '\u00b0F',
        ];
    }

    return $entry;
}

function build_sys_entry(array $data): array
{
    $entry = [
        'if_name'     => 'RPi_Health_Status',
        'connections' => [],
    ];

    $entry['connections'][] = ['name' => 'CPU_temp', 'value' => $data['CPU_temp'] ?? null, 'unit' => '\u00b0F'];
    $entry['connections'][] = ['name' => 'CPU_Util', 'value' => $data['CPU_Util'] ?? null, 'unit' => '%'];
    $entry['connections'][] = ['name' => 'RAM_Util', 'value' => $data['RAM_Util'] ?? null, 'unit' => '%'];
    $entry['connections'][] = ['name' => 'Disk_Util', 'value' => $data['Disk_Util'] ?? null, 'unit' => '%'];
    $entry['connections'][] = ['name' => 'Up_time', 'value' => $data['Up_time'] ?? null, 'unit' => ''];

    return $entry;
}

$entries = [];

foreach (collect_paths('logstat_SDAQ*.json', $sandboxDir, $ramdisk) as $path) {
    $json = load_json($path);
    if ($json === null) continue;

    $entries[] = build_sdaq_entry($json, $path);
}

foreach (collect_paths('logstat_sys.json', $sandboxDir, $ramdisk) as $path) {
    $json = load_json($path);
    if ($json === null) continue;

    $entries[] = build_sys_entry($json);
    break; // 只需要一份 sys
}

echo json_encode(['ok' => true, 'entries' => $entries], JSON_PRETTY_PRINT);