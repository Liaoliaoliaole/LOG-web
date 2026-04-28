<?php

require_once __DIR__ . '/../repositories/logstat_repository.php';
require_once __DIR__ . '/../core/nox_runtime.php';
require_once __DIR__ . '/../services/can_role_service.php';

function nox_validate_bus(string $bus): string
{
    return can_role_validate_bus($bus);
}

function nox_find_logstat_by_bus(string $ramdisk, string $bus): ?array
{
    $paths = logstat_collect_paths('logstat_NOX*.json', $ramdisk);
    $normalizedBus = strtolower(trim($bus));

    foreach ($paths as $path) {
        $data = logstat_load_json($path);
        if (!is_array($data)) {
            continue;
        }
        $iface = strtolower(trim((string) ($data['CANBus_interface'] ?? '')));
        if ($iface === $normalizedBus) {
            return $data;
        }
    }

    return null;
}

function nox_sensor_defaults(int $addr): array
{
    return [
        'addr' => $addr,
        'detected' => false,
        'last_seen' => null,
        'NOx_value_avg' => null,
        'NOx_value_min' => null,
        'NOx_value_max' => null,
        'O2_value_avg' => null,
        'O2_value_min' => null,
        'O2_value_max' => null,
        'integral_size' => [
            'NOx' => null,
            'O2' => null,
        ],
        'status' => [
            'meas_state' => false,
            'supply_in_range' => false,
            'in_temperature' => false,
            'is_NOx_value_valid' => false,
            'is_O2_value_valid' => false,
            'heater_mode_state' => '',
        ],
        'errors' => [
            'heater' => '',
            'NOx' => '',
            'O2' => '',
        ],
    ];
}

function nox_normalize_sensor(array $sensor, int $addr): array
{
    $base = nox_sensor_defaults($addr);
    $lastSeen = $sensor['last_seen'] ?? null;
    $detected = nox_runtime_sensor_detected($sensor);

    return [
        'addr' => $addr,
        'detected' => $detected,
        'last_seen' => is_numeric($lastSeen) ? (int) $lastSeen : null,
        'NOx_value_avg' => is_numeric($sensor['NOx_value_avg'] ?? null) ? (float) $sensor['NOx_value_avg'] : null,
        'NOx_value_min' => is_numeric($sensor['NOx_value_min'] ?? null) ? (float) $sensor['NOx_value_min'] : null,
        'NOx_value_max' => is_numeric($sensor['NOx_value_max'] ?? null) ? (float) $sensor['NOx_value_max'] : null,
        'O2_value_avg' => is_numeric($sensor['O2_value_avg'] ?? null) ? (float) $sensor['O2_value_avg'] : null,
        'O2_value_min' => is_numeric($sensor['O2_value_min'] ?? null) ? (float) $sensor['O2_value_min'] : null,
        'O2_value_max' => is_numeric($sensor['O2_value_max'] ?? null) ? (float) $sensor['O2_value_max'] : null,
        'integral_size' => [
            'NOx' => is_numeric($sensor['NOx_value_integral_size'] ?? null) ? (int) $sensor['NOx_value_integral_size'] : null,
            'O2' => is_numeric($sensor['O2_value_integral_size'] ?? null) ? (int) $sensor['O2_value_integral_size'] : null,
        ],
        'status' => [
            'meas_state' => !empty($sensor['status']['meas_state']),
            'supply_in_range' => !empty($sensor['status']['supply_in_range']),
            'in_temperature' => !empty($sensor['status']['in_temperature']),
            'is_NOx_value_valid' => !empty($sensor['status']['is_NOx_value_valid']),
            'is_O2_value_valid' => !empty($sensor['status']['is_O2_value_valid']),
            'heater_mode_state' => trim((string) ($sensor['status']['heater_mode_state'] ?? '')),
        ],
        'errors' => [
            'heater' => trim((string) ($sensor['errors']['heater'] ?? '')),
            'NOx' => trim((string) ($sensor['errors']['NOx'] ?? '')),
            'O2' => trim((string) ($sensor['errors']['O2'] ?? '')),
        ],
    ] + $base;
}

function nox_load_state(string $ramdisk, string $bus): array
{
    $normalizedBus = nox_validate_bus($bus);
    $raw = nox_find_logstat_by_bus($ramdisk, $normalizedBus);

    $state = [
        'bus' => $normalizedBus,
        'runtime_status' => 'Not detected',
        'ws_port' => null,
        'electrics' => null,
        'bus_utilization' => null,
        'bus_error_rate' => null,
        'auto_sw_off_value' => null,
        'auto_sw_off_cnt' => null,
        'sensors' => [
            nox_sensor_defaults(0),
            nox_sensor_defaults(1),
        ],
    ];

    if (!is_array($raw)) {
        return $state;
    }

    $state['ws_port'] = is_numeric($raw['ws_port'] ?? null) ? (int) $raw['ws_port'] : null;
    $state['electrics'] = is_array($raw['Electrics'] ?? null) ? $raw['Electrics'] : null;
    $state['bus_utilization'] = is_numeric($raw['BUS_Utilization'] ?? null) ? (float) $raw['BUS_Utilization'] : null;
    $state['bus_error_rate'] = is_numeric($raw['BUS_Error_rate'] ?? null) ? (float) $raw['BUS_Error_rate'] : null;
    $state['auto_sw_off_value'] = is_numeric($raw['Auto_SW_OFF_value'] ?? null) ? (int) $raw['Auto_SW_OFF_value'] : null;
    $state['auto_sw_off_cnt'] = is_numeric($raw['Auto_SW_OFF_cnt'] ?? null) ? (int) $raw['Auto_SW_OFF_cnt'] : null;

    $normalizedSensors = [
        0 => nox_sensor_defaults(0),
        1 => nox_sensor_defaults(1),
    ];
    $sensors = is_array($raw['NOx_sensors'] ?? null) ? $raw['NOx_sensors'] : [];
    foreach ($sensors as $sensor) {
        if (!is_array($sensor)) {
            continue;
        }
        $addr = is_numeric($sensor['addr'] ?? null) ? (int) $sensor['addr'] : null;
        if ($addr === null || !array_key_exists($addr, $normalizedSensors)) {
            continue;
        }
        $normalizedSensors[$addr] = nox_normalize_sensor($sensor, $addr);
    }

    $state['sensors'] = array_values($normalizedSensors);
    $state['runtime_status'] = array_reduce($state['sensors'], static function (string $carry, array $sensor): string {
        return $carry === 'Connected' || !empty($sensor['detected']) ? 'Connected' : $carry;
    }, 'Not detected');

    return $state;
}

function nox_gdbus_call(string $bus, string $method, array $payload): string
{
    $normalizedBus = nox_validate_bus($bus);
    $target = sprintf('org.freedesktop.Morfeas.NOX.%s', $normalizedBus);
    $interface = sprintf('Morfeas.NOX.%s', $normalizedBus);
    $fullMethod = $interface . '.' . $method;
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        throw new RuntimeException('Failed to encode NOX D-Bus payload');
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
        throw new RuntimeException($output !== '' ? $output : 'NOX control command failed');
    }

    if (preg_match("/\\('([^']*)'/", $output, $m) === 1) {
        return $m[1];
    }

    return $output;
}

function nox_set_heater(string $bus, int $address, bool $enabled): array
{
    if (!in_array($address, [-1, 0, 1], true)) {
        throw new InvalidArgumentException('address must be -1, 0, or 1');
    }

    $reply = nox_gdbus_call($bus, 'NOX_heater', [
        'NOx_address' => $address,
        'NOx_heater' => $enabled,
    ]);
    if (stripos($reply, 'Success') === false) {
        throw new RuntimeException($reply !== '' ? $reply : 'NOX heater command failed');
    }

    return [
        'bus' => nox_validate_bus($bus),
        'address' => $address,
        'enabled' => $enabled,
        'reply' => $reply,
    ];
}

function nox_set_auto_sw_off(string $bus, int $value): array
{
    if ($value < 0 || $value > 65535) {
        throw new InvalidArgumentException('auto_off_value must be in range 0..65535');
    }

    $reply = nox_gdbus_call($bus, 'NOX_auto_sw_off', [
        'NOx_auto_sw_off_value' => $value,
    ]);
    if (stripos($reply, 'Success') === false) {
        throw new RuntimeException($reply !== '' ? $reply : 'NOX auto-off command failed');
    }

    return [
        'bus' => nox_validate_bus($bus),
        'auto_sw_off_value' => $value,
        'reply' => $reply,
    ];
}
