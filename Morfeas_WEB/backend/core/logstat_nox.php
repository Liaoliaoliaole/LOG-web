<?php

require_once __DIR__ . '/nox_runtime.php';

function nox_reserved_error_code($value): ?int
{
    if (!is_numeric($value)) {
        return null;
    }

    $code = (int)round((float)$value);
    return in_array($code, [-901, -902, -903, -904, -905, -906, -907], true) ? $code : null;
}

function nox_status_from_error_code(int $code): string
{
    if ($code === -901) {
        return 'OFF-Line';
    }
    if ($code === -902) {
        return 'No sensor';
    }
    if ($code === -903) {
        return 'Heating';
    }
    if ($code === -905) {
        return 'Unreachable';
    }
    if ($code === -906) {
        return 'Heater off';
    }
    if ($code === -907) {
        return 'Signal Invalid';
    }
    return 'Unclassified';
}

function nox_sensor_is_offline_only(?int $noxErrorCode, ?int $o2ErrorCode, string $heaterMode): bool
{
    if ($noxErrorCode !== -901 || $o2ErrorCode !== -901) {
        return false;
    }

    if (strcasecmp($heaterMode, 'OFF-Line') === 0) {
        return true;
    }

    return false;
}

function nox_canonical_anchor_base(?string $iface, ?int $addr, ?string $fallbackAnchor): ?string
{
    $bus = strtolower(trim((string)$iface));
    if ($addr !== null && $bus !== '') {
        return sprintf('%s.addr_%d', $bus, $addr);
    }

    $raw = trim((string)$fallbackAnchor);
    if ($raw === '') {
        return null;
    }

    if (preg_match('/^(can\w+)\.(?:sensor|addr[:_]?)(\d+)$/i', $raw, $m)) {
        return sprintf('%s.addr_%d', strtolower($m[1]), (int)$m[2]);
    }

    return strtolower($raw);
}

function nox_load_anchor_map(string $jsonPath): array
{
    if (!is_file($jsonPath)) {
        return [];
    }

    $raw = file_get_contents($jsonPath);
    if ($raw === false || $raw === '') {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [];
    }

    $iface   = $data['CANBus_interface'] ?? 'can2';
    $ifaceUc = strtoupper($iface);
    $sensorLifetime = isset($data['NOx_sensor_lifetime_sec']) && is_numeric($data['NOx_sensor_lifetime_sec'])
        ? (int)$data['NOx_sensor_lifetime_sec']
        : null;


    $sensors = $data['NOx_sensors'] ?? $data['sensors'] ?? [];
    if (!is_array($sensors)) {
        return [];
    }

    $map = [];

    $allowedErrors = ['Open Wire', 'Short circuit'];
    foreach ($sensors as $s) {
        if (!is_array($s)) {
            continue;
        }
        if (count($s) === 0) {
            continue;
        }

        $noxVal = $s['NOx_value_avg'] ?? $s['NOx_ppm'] ?? null;
        $o2Val  = $s['O2_value_avg'] ?? null;
        $noxErrorCode = nox_reserved_error_code($noxVal);
        $o2ErrorCode  = nox_reserved_error_code($o2Val);

        $addr = isset($s['addr']) ? (int)$s['addr'] : (isset($s['Address']) ? (int)$s['Address'] : null);

        $anchorBase = nox_canonical_anchor_base($iface, $addr, $s['anchor'] ?? null);

        if ($anchorBase === null) {
            continue;
        }

        $errors      = is_array($s['errors'] ?? null) ? $s['errors'] : [];
        $statusFlags = is_array($s['status'] ?? null) ? $s['status'] : [];
        $heaterMode  = trim((string)($statusFlags['heater_mode_state'] ?? ''));

        $noxValid = !empty($statusFlags['is_NOx_value_valid']);
        $o2Valid  = !empty($statusFlags['is_O2_value_valid']);

        $errText = null;
        if (!$noxValid || !$o2Valid) {
            
            foreach (['heater', 'NOx', 'O2'] as $key) {
                $val = trim((string)($errors[$key] ?? ''));
                foreach ($allowedErrors as $allowed) {
                    if ($val !== '' && strcasecmp($val, $allowed) === 0) {
                        $errText = $allowed;
                        break 2;
                    }
                }
            }
        }

        if (($errText === null) && (!$noxValid || !$o2Valid)) {
            $errText = $heaterMode !== '' ? $heaterMode : 'Unclassified';
        }

        // Offline-only NOX sensors remain selectable so operators can create
        // the link before physically reconnecting the sensor; the search UI
        // marks them as OFF-Line to avoid presenting them as live sources.
        $isOfflineOnly = nox_sensor_is_offline_only($noxErrorCode, $o2ErrorCode, $heaterMode);

        $noxStatus = $noxErrorCode !== null ? nox_status_from_error_code($noxErrorCode) : ($noxValid ? 'Okay' : ($errText ?: 'Unclassified'));

        $noxRow = [
            'status'        => $noxStatus,
            'is_meas_valid' => $noxValid && $noxErrorCode === null,
            'meas_value'    => is_numeric($noxVal) ? (float)$noxVal : null,
            'meas_unit'     => 'ppm',
            'is_offline_only' => $isOfflineOnly,
        ];

        $o2Status = $o2ErrorCode !== null ? nox_status_from_error_code($o2ErrorCode) : ($o2Valid ? 'Okay' : ($errText ?: 'Unclassified'));

        $o2Row = [
            'status'        => $o2Status,
            'is_meas_valid' => $o2Valid && $o2ErrorCode === null,
            'meas_value'    => is_numeric($o2Val) ? (float)$o2Val : null,
            'meas_unit'     => '%',
            'is_offline_only' => $isOfflineOnly,
        ];

        $keys = [
            $anchorBase . '.NOx',
            $anchorBase . '.O2',
            strtoupper($anchorBase) . '.NOx',
            strtoupper($anchorBase) . '.O2',
        ];

        if ($addr !== null) {
            $keys = array_merge($keys, [
                sprintf('%s.sensor%d.NOx', strtolower($iface), $addr),
                sprintf('%s.sensor%d.O2', strtolower($iface), $addr),
                sprintf('%s.ADDR:%d.NOx',  $ifaceUc, $addr),
                sprintf('%s.ADDR:%d.O2',   $ifaceUc, $addr),
                sprintf('%s.ADDR:%02d.NOx', $ifaceUc, $addr),
                sprintf('%s.ADDR:%02d.O2',  $ifaceUc, $addr),
            ]);
        }

        foreach ($keys as $idx => $k) {
            if (strpos($k, '.NOx') !== false) {
                $map[$k] = $noxRow;
            } else {
                $map[$k] = $o2Row;
            }
        }
    }

    return $map;
}
