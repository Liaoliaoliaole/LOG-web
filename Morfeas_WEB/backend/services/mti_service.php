<?php

require_once __DIR__ . '/../repositories/logstat_repository.php';

const MTI_DEVICE_NAME_REGEX = '/^[A-Za-z0-9_-]{1,64}$/';
const MTI_MODES = ['Disabled', 'TC16', 'TC8', 'TC4', 'QUAD', 'RMSW/MUX'];

function mti_validate_name(string $name): string
{
    $normalized = trim($name);
    if ($normalized === '' || preg_match(MTI_DEVICE_NAME_REGEX, $normalized) !== 1) {
        throw new InvalidArgumentException('Invalid MTI device name');
    }

    return $normalized;
}

function mti_normalize_connection(?string $status): array
{
    $raw = trim((string)$status);
    if ($raw === '') {
        return [
            'runtime_status' => 'Not detected',
            'runtime_detail' => 'No logstat data',
            'detected' => false,
        ];
    }

    if (strcasecmp($raw, 'Okay') === 0) {
        return [
            'runtime_status' => 'Connected',
            'runtime_detail' => '',
            'detected' => true,
        ];
    }

    return [
        'runtime_status' => 'Not connected',
        'runtime_detail' => $raw,
        'detected' => false,
    ];
}

function mti_collect_names(string $ramdisk): array
{
    $names = [];
    foreach (logstat_collect_paths('logstat_MTI*.json', $ramdisk) as $path) {
        $base = basename($path);
        if (preg_match('/^logstat_MTI_(.+)\.json$/', $base, $m) === 1) {
            $names[] = $m[1];
        }
    }

    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values(array_unique($names));
}

function mti_find_logstat_by_name(string $ramdisk, string $name): ?array
{
    $normalizedName = mti_validate_name($name);
    $directPath = rtrim($ramdisk, '/') . '/logstat_MTI_' . $normalizedName . '.json';
    $direct = logstat_load_json($directPath);
    if (is_array($direct)) {
        return $direct;
    }

    foreach (logstat_collect_paths('logstat_MTI*.json', $ramdisk) as $path) {
        $base = basename($path);
        if (preg_match('/^logstat_MTI_(.+)\.json$/', $base, $m) !== 1) {
            continue;
        }
        if (strcasecmp($m[1], $normalizedName) !== 0) {
            continue;
        }
        $data = logstat_load_json($path);
        if (is_array($data)) {
            return $data;
        }
    }

    return null;
}

function mti_load_state(string $ramdisk, string $name): array
{
    $normalizedName = mti_validate_name($name);
    $raw = mti_find_logstat_by_name($ramdisk, $normalizedName);

    $state = [
        'name' => $normalizedName,
        'runtime_status' => 'Not detected',
        'runtime_detail' => 'No logstat data',
        'detected' => false,
        'ipv4_address' => null,
        'identifier' => null,
        'mti_status' => null,
        'tele_data' => null,
    ];

    if (!is_array($raw)) {
        return $state;
    }

    $runtime = mti_normalize_connection($raw['Connection_status'] ?? null);
    $teleData = $raw['Tele_data'] ?? null;

    return [
        'name' => $normalizedName,
        'runtime_status' => $runtime['runtime_status'],
        'runtime_detail' => $runtime['runtime_detail'],
        'detected' => $runtime['detected'],
        'ipv4_address' => trim((string)($raw['IPv4_address'] ?? '')) ?: null,
        'identifier' => $raw['Identifier'] ?? null,
        'mti_status' => is_array($raw['MTI_status'] ?? null) ? $raw['MTI_status'] : null,
        'tele_data' => is_array($teleData) ? $teleData : null,
    ];
}

function mti_gdbus_call(string $name, string $method, array $payload): string
{
    $normalizedName = mti_validate_name($name);
    if (!in_array($method, ['new_MTI_config', 'MTI_Global_SWs', 'ctrl_tele_SWs', 'new_PWM_config'], true)) {
        throw new InvalidArgumentException('Invalid MTI action');
    }

    $target = sprintf('org.freedesktop.Morfeas.MTI.%s', $normalizedName);
    $interface = sprintf('Morfeas.MTI.%s', $normalizedName);
    $fullMethod = $interface . '.' . $method;
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        throw new RuntimeException('Failed to encode MTI D-Bus payload');
    }

    $cmd = sprintf(
        'gdbus call --system --dest %s --object-path / --method %s %s',
        escapeshellarg($target),
        escapeshellarg($fullMethod),
        escapeshellarg($json)
    );

    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    $output = trim(implode("\n", $out));
    if ($code !== 0) {
        throw new RuntimeException($output !== '' ? $output : 'MTI control command failed');
    }

    if (preg_match("/\\('([^']*)'/", $output, $m) === 1) {
        return $m[1];
    }

    return $output;
}

function mti_set_config(string $name, array $body): array
{
    $mode = trim((string)($body['mode'] ?? ''));
    if (!in_array($mode, MTI_MODES, true)) {
        throw new InvalidArgumentException('Invalid MTI mode');
    }

    $rfChannel = (int)($body['rf_channel'] ?? 0);
    if ($mode !== 'RMSW/MUX' && ($rfChannel < 0 || $rfChannel > 126 || $rfChannel % 2 !== 0)) {
        throw new InvalidArgumentException('RF channel must be an even number from 0 to 126');
    }

    $payload = [
        'new_mode' => $mode,
        'new_RF_CH' => $mode === 'RMSW/MUX' ? 0 : $rfChannel,
    ];

    if (in_array($mode, ['TC16', 'TC8', 'TC4'], true)) {
        $stv = (int)($body['samples_to_valid'] ?? 0);
        $stf = (int)($body['samples_to_invalid'] ?? 0);
        if ($stv < 0 || $stv > 255 || $stf < 0 || $stf > 255) {
            throw new InvalidArgumentException('Samples values must be in range 0..255');
        }
        $payload['StV'] = $stv;
        $payload['StF'] = $stf;
    }

    if ($mode === 'RMSW/MUX') {
        $payload['G_SW'] = filter_var($body['global_control'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $payload['G_SL'] = false;
    }

    $reply = mti_gdbus_call($name, 'new_MTI_config', $payload);
    if (stripos($reply, 'Success') === false) {
        throw new RuntimeException($reply !== '' ? $reply : 'MTI config command failed');
    }

    return [
        'name' => mti_validate_name($name),
        'payload' => $payload,
        'reply' => $reply,
    ];
}

function mti_set_global_power(string $name, bool $enabled): array
{
    $payload = [
        'G_P_state' => $enabled,
        'G_S_state' => false,
    ];

    $reply = mti_gdbus_call($name, 'MTI_Global_SWs', $payload);
    if (stripos($reply, 'Success') === false) {
        throw new RuntimeException($reply !== '' ? $reply : 'MTI global switch command failed');
    }

    return [
        'name' => mti_validate_name($name),
        'enabled' => $enabled,
        'reply' => $reply,
    ];
}

function mti_control_tele_switch(string $name, array $body): array
{
    $memPos = (int)($body['mem_pos'] ?? -1);
    $teleType = trim((string)($body['tele_type'] ?? ''));
    $swName = trim((string)($body['sw_name'] ?? ''));
    $newState = filter_var($body['new_state'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

    if ($memPos < 0) {
        throw new InvalidArgumentException('mem_pos must be a positive number');
    }
    if (!in_array($teleType, ['MUX', 'RMSW', 'Mini_RMSW'], true)) {
        throw new InvalidArgumentException('Invalid telemetry device type');
    }
    $allowedSwitches = $teleType === 'MUX'
        ? ['Sel_1', 'Sel_2', 'Sel_3', 'Sel_4']
        : ($teleType === 'RMSW' ? ['Main_SW', 'SW_1', 'SW_2'] : ['Main_SW']);
    if (!in_array($swName, $allowedSwitches, true)) {
        throw new InvalidArgumentException('Invalid telemetry switch name');
    }
    if ($newState === null) {
        $newState = false;
    }

    $payload = [
        'mem_pos' => $memPos,
        'tele_type' => $teleType,
        'sw_name' => $swName,
        'new_state' => $newState,
    ];

    $reply = mti_gdbus_call($name, 'ctrl_tele_SWs', $payload);
    if (stripos($reply, 'Success') === false) {
        throw new RuntimeException($reply !== '' ? $reply : 'MTI telemetry switch command failed');
    }

    return [
        'name' => mti_validate_name($name),
        'payload' => $payload,
        'reply' => $reply,
    ];
}

function mti_set_pwm_config(string $name, array $body): array
{
    $items = $body['pwm_gens_config'] ?? null;
    if (!is_array($items) || count($items) !== 2) {
        throw new InvalidArgumentException('pwm_gens_config must contain two channels');
    }

    $payloadItems = [];
    foreach ($items as $idx => $item) {
        if ($item === null) {
            $payloadItems[] = null;
            continue;
        }
        if (!is_array($item)) {
            throw new InvalidArgumentException('Invalid PWM channel config');
        }

        $scaler = $item['scaler'] ?? null;
        $min = $item['min'] ?? null;
        $max = $item['max'] ?? null;
        $saturation = filter_var($item['saturation'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if (!is_numeric($scaler) || (float)$scaler == 0.0) {
            throw new InvalidArgumentException(sprintf('PWM CH%d scaler must be non-zero', $idx + 1));
        }
        if (!is_numeric($min) || !is_numeric($max)) {
            throw new InvalidArgumentException(sprintf('PWM CH%d min/max must be numbers', $idx + 1));
        }
        if ((float)$max <= (float)$min) {
            throw new InvalidArgumentException(sprintf('PWM CH%d max must be greater than min', $idx + 1));
        }
        if ($saturation === null) {
            $saturation = false;
        }

        $payloadItems[] = [
            'scaler' => (float)$scaler,
            'min' => (float)$min,
            'max' => (float)$max,
            'saturation' => $saturation,
        ];
    }

    $reply = mti_gdbus_call($name, 'new_PWM_config', [
        'PWM_gens_config' => $payloadItems,
    ]);
    if (stripos($reply, 'Success') === false) {
        throw new RuntimeException($reply !== '' ? $reply : 'MTI PWM config command failed');
    }

    return [
        'name' => mti_validate_name($name),
        'payload' => ['PWM_gens_config' => $payloadItems],
        'reply' => $reply,
    ];
}
