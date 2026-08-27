<?php

require_once __DIR__ . '/../repositories/logstat_repository.php';

const SYSTEM_STATUS_HIDDEN_LOGGER_FILES = [
    'LOG_daily_update_check.log',
];

function system_status_entries(string $ramdisk): array
{
    $entries = [];

    foreach (logstat_collect_paths('logstat_SDAQ*.json', $ramdisk) as $path) {
        $json = logstat_load_json($path);
        if ($json === null) continue;

        $entries[] = system_status_build_sdaq_entry($json, $path);
    }

    foreach (logstat_collect_paths('logstat_sys.json', $ramdisk) as $path) {
        $json = logstat_load_json($path);
        if ($json === null) continue;

        $entries[] = system_status_build_sys_entry($json);
        break;
    }

    return $entries;
}

function system_status_detect_bus(array $data, string $path): string
{
    if (!empty($data['CANBus_interface']) && is_string($data['CANBus_interface'])) {
        return strtoupper($data['CANBus_interface']);
    }

    if (preg_match('/logstat_sdaq.*_(can\w+)/i', basename($path), $m)) {
        return strtoupper($m[1]);
    }

    return 'CAN1';
}

function system_status_build_sdaq_entry(array $data, string $path): array
{
    $bus   = system_status_detect_bus($data, $path);
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
            'unit'  => '\u00b0C',
        ];
    }

    return $entry;
}

function system_status_build_sys_entry(array $data): array
{
    $entry = [
        'if_name'     => 'RPi_Health_Status',
        'connections' => [],
    ];

    $entry['connections'][] = [
        'name' => 'CPU_temp',
        'value' => $data['CPU_temp'] ?? null,
        'unit' => '\u00b0C',
    ];
    $entry['connections'][] = ['name' => 'CPU_Util', 'value' => $data['CPU_Util'] ?? null, 'unit' => '%'];
    $entry['connections'][] = ['name' => 'RAM_Util', 'value' => $data['RAM_Util'] ?? null, 'unit' => '%'];
    $entry['connections'][] = ['name' => 'Disk_Util', 'value' => $data['Disk_Util'] ?? null, 'unit' => '%'];
    $entry['connections'][] = ['name' => 'Up_time', 'value' => $data['Up_time'] ?? null, 'unit' => ''];

    return $entry;
}

function system_status_resolve_loggers_dir(string $logCfgPath, string $ramdisk): string
{
    if (is_file($logCfgPath)) {
        $raw = @file_get_contents($logCfgPath);
        if ($raw !== false) {
            $xml = @simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NONET);
            if ($xml !== false) {
                $candidate = trim((string)($xml->LOGGERS_DIR ?? ''));
                if ($candidate !== '') {
                    return rtrim($candidate, '/') . '/';
                }
            }
        }
    }

    return rtrim($ramdisk, '/') . '/Morfeas_Loggers/';
}

function system_status_collect_loggers(string $dir): array
{
    $list = [];
    if (!is_dir($dir)) {
        return $list;
    }

    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        if (in_array($name, SYSTEM_STATUS_HIDDEN_LOGGER_FILES, true)) {
            continue;
        }
        $path = $dir . $name;
        if (!is_file($path)) {
            continue;
        }
        $list[] = $name;
    }

    sort($list, SORT_NATURAL | SORT_FLAG_CASE);
    return $list;
}

function system_status_parse_logger_name_list($raw): array
{
    $parts = [];
    if (is_array($raw)) {
        $parts = $raw;
    } else {
        $parts = preg_split('/[\s,]+/', (string)$raw) ?: [];
    }

    $out = [];
    foreach ($parts as $part) {
        $name = basename(trim((string)$part));
        if ($name === '') {
            continue;
        }
        $out[$name] = true;
    }

    $names = array_keys($out);
    if (count($names) > 200) {
        $names = array_slice($names, 0, 200);
    }

    return $names;
}

function system_status_build_combined_logger_export(string $dir, array $selectedNames, array $knownNames): array
{
    $knownSet = array_fill_keys($knownNames, true);
    $names = [];
    foreach ($selectedNames as $name) {
        if (isset($knownSet[$name])) {
            $names[] = $name;
        }
    }

    if (!$names) {
        throw new InvalidArgumentException('No valid logger files selected', 404);
    }

    $parts = [];
    $added = 0;
    foreach ($names as $name) {
        $path = $dir . $name;
        if (!is_file($path) || !is_readable($path)) {
            continue;
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            continue;
        }
        $parts[] = "===== BEGIN {$name} =====\n" . $content . "\n===== END {$name} =====";
        $added++;
    }

    if ($added === 0) {
        throw new InvalidArgumentException('No readable logger files to export', 404);
    }

    return [
        'filename' => 'system_logs_' . date('Ymd_His') . '.txt',
        'content' => implode("\n\n", $parts) . "\n",
    ];
}

function system_status_parse_journal_lines($raw): int
{
    $value = (int)$raw;
    if ($value <= 0) {
        $value = 500;
    }
    if ($value < 50) {
        $value = 50;
    }
    if ($value > 3000) {
        $value = 3000;
    }
    return $value;
}

function system_status_parse_journal_units($raw): array
{
    if (is_array($raw)) {
        $parts = $raw;
    } else {
        $parts = preg_split('/[,\s]+/', (string)$raw) ?: [];
    }

    $units = [];
    foreach ($parts as $part) {
        $unit = trim((string)$part);
        if ($unit === '') {
            continue;
        }
        if (preg_match('/^[A-Za-z0-9@_.:-]+$/', $unit) !== 1) {
            continue;
        }
        if (!str_contains($unit, '.')) {
            $unit .= '.service';
        }
        if (!in_array($unit, $units, true)) {
            $units[] = $unit;
        }
    }

    if (count($units) > 8) {
        $units = array_slice($units, 0, 8);
    }

    return $units;
}

function system_status_build_journal_command(int $lines, array $units, bool $useSudo): string
{
    $parts = [];
    if ($useSudo) {
        $parts[] = 'sudo';
        $parts[] = '-n';
    }
    $parts[] = '/usr/bin/journalctl';
    $parts[] = '--no-pager';
    $parts[] = '-o';
    $parts[] = 'short-iso';
    $parts[] = '-n';
    $parts[] = (string)$lines;
    foreach ($units as $unit) {
        $parts[] = '-u';
        $parts[] = $unit;
    }

    return implode(' ', array_map(static fn($p) => escapeshellarg($p), $parts));
}

function system_status_run_command(string $command): array
{
    $out = [];
    $code = 0;
    exec($command . ' 2>&1', $out, $code);
    return [
        'code' => (int)$code,
        'output' => trim(implode("\n", $out)),
    ];
}

function system_status_is_permission_issue(string $output): bool
{
    $text = strtolower($output);
    return str_contains($text, 'permission denied')
        || str_contains($text, 'not in the')
        || str_contains($text, 'insufficient')
        || str_contains($text, 'a password is required');
}

function system_status_read_journal(int $lines, array $units): array
{
    $normalCmd = system_status_build_journal_command($lines, $units, false);
    $normal = system_status_run_command($normalCmd);
    if ($normal['code'] === 0) {
        return [
            'content' => $normal['output'],
            'units' => $units,
            'lines' => $lines,
            'used_sudo' => false,
        ];
    }

    if (!system_status_is_permission_issue($normal['output'])) {
        throw new RuntimeException('journalctl failed');
    }

    $sudoCmd = system_status_build_journal_command($lines, $units, true);
    $sudo = system_status_run_command($sudoCmd);
    if ($sudo['code'] === 0) {
        return [
            'content' => $sudo['output'],
            'units' => $units,
            'lines' => $lines,
            'used_sudo' => true,
        ];
    }

    if (str_contains(strtolower($sudo['output']), 'a password is required')) {
        throw new RuntimeException('journal permission denied; configure sudoers for /usr/bin/journalctl');
    }

    throw new RuntimeException('journalctl failed');
}
