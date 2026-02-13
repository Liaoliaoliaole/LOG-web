<?php

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


    $sensors = $data['NOx_sensors'] ?? $data['sensors'] ?? [];
    if (!is_array($sensors)) {
        return [];
    }

    $map = [];

    $allowedErrors = ['Open Wire', 'Short circuit'];

    foreach ($sensors as $s) {
        $addr = isset($s['addr']) ? (int)$s['addr'] : (isset($s['Address']) ? (int)$s['Address'] : null);

        $anchorBase = null;
        if ($addr !== null) {
            $anchorBase = sprintf('%s.sensor%d', strtolower($iface), $addr);
        } elseif (!empty($s['anchor'])) {
            $anchorBase = strtolower((string)$s['anchor']);
        }

        if ($anchorBase === null) {
            continue;
        }

        $errors      = is_array($s['errors'] ?? null) ? $s['errors'] : [];
        $statusFlags = is_array($s['status'] ?? null) ? $s['status'] : [];

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
            $errText = 'Unclassified';
        }


        $noxVal    = $s['NOx_value_avg'] ?? $s['NOx_ppm'] ?? null;
        $noxStatus = $noxValid ? 'Okay' : ($errText ?: 'Unclassified');

        $noxRow = [
            'status'        => $noxStatus,
            'is_meas_valid' => $noxValid,
            'meas_value'    => ($noxValid && is_numeric($noxVal)) ? (float)$noxVal : null,
            'meas_unit'     => 'ppm',
        ];

        $o2Val    = $s['O2_value_avg'] ?? null;
        $o2Status = $o2Valid ? 'Okay' : ($errText ?: 'Unclassified');

        $o2Row = [
            'status'        => $o2Status,
            'is_meas_valid' => $o2Valid,
            'meas_value'    => ($o2Valid && is_numeric($o2Val)) ? (float)$o2Val : null,
            'meas_unit'     => '%',
        ];

        $keys = [
            $anchorBase . '.NOx',
            $anchorBase . '.O2',
            strtoupper($anchorBase) . '.NOx',
            strtoupper($anchorBase) . '.O2',
        ];

        if ($addr !== null) {
            $keys = array_merge($keys, [
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
