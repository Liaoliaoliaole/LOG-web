<?php

function sdaq_reserved_error_code($value): ?int
{
    if (!is_numeric($value)) {
        return null;
    }

    $code = (int)round((float)$value);
    return in_array($code, [-901, -902, -903, -904, -905, -906, -907], true) ? $code : null;
}

function sdaq_detect_bus(array $data, string $jsonPath): string
{
    if (!empty($data['CANBus_interface'])) {
        return strtoupper((string)$data['CANBus_interface']);
    }

    $name = strtolower(basename($jsonPath));
    if (preg_match('/logstat_sdaq.*_(can\w+)/i', $name, $m)) {
        return strtoupper($m[1]);
    }

    return 'CAN1';
}

function sdaq_collect_device_types($jsonPaths): array
{
    if (!is_array($jsonPaths)) {
        $jsonPaths = [$jsonPaths];
    }

    $types = [];
    foreach ($jsonPaths as $jsonPath) {
        if (!is_file($jsonPath)) continue;
        $raw = file_get_contents($jsonPath);
        if ($raw === false || $raw === '') continue;

        $data = json_decode($raw, true);
        if (!is_array($data)) continue;

        $bus = sdaq_detect_bus($data, $jsonPath);
        if (empty($data['SDAQs_data']) || !is_array($data['SDAQs_data'])) {
            continue;
        }

        foreach ($data['SDAQs_data'] as $sdaq) {
            $addr = $sdaq['Address'] ?? null;
            $type = $sdaq['SDAQ_type'] ?? null;
            if ($addr === null || !is_string($type) || $type === '') {
                continue;
            }
            $key = sprintf('%s.ADDR:%02d', $bus, $addr);
            $types[$key] = $type;
        }
    }

    return $types;
}

function sdaq_load_anchor_map(string $jsonPath, array $xmlAnchors = []): array
{
    if (!is_file($jsonPath)) {
        return ['anchors' => [], 'channels' => []];
    }

    $raw = file_get_contents($jsonPath);
    if ($raw === false || $raw === '') {
        return ['anchors' => [], 'channels' => []];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['anchors' => [], 'channels' => []];
    }

    $map = ['anchors' => [], 'channels' => []];

    $can = sdaq_detect_bus($data, $jsonPath);

    if (empty($data['SDAQs_data']) || !is_array($data['SDAQs_data'])) {
        return $map;
    }

    foreach ($data['SDAQs_data'] as $sdaq) {
        $deviceType = $sdaq['SDAQ_type'] ?? null;
        $addr = $sdaq['Address'] ?? null;
        $serial = $sdaq['Serial_number'] ?? null;
        if ($addr === null) {
            continue;
        }

        $regStatus = $sdaq['SDAQ_Status']['Registration_status'] ?? null;

        $measArr = $sdaq['Meas'] ?? [];
        if (!is_array($measArr)) continue;

        $calibByCh = [];
        if (!empty($sdaq['Calibration_Data']) && is_array($sdaq['Calibration_Data'])) {
            foreach ($sdaq['Calibration_Data'] as $cal) {
                $chId = $cal['Channel'] ?? null;
                if ($chId === null) continue;

                $calDate   = $cal['Calibration_date_UNIX'] ?? null;
                $calPeriod = $cal['Calibration_period'] ?? null; // Unit: days (legacy behavior).

                if ($calDate !== null) {
                    $calibByCh[$chId] = [
                        'cal_date'   => gmdate('Y-m-d', (int)$calDate),
                        // UI uses months; convert days to months (round up).
                        'cal_period' => is_numeric($calPeriod) ? (int)ceil(((float)$calPeriod) / 30) : null,
                    ];
                }
            }
        }

        foreach ($measArr as $meas) {
            $ch = $meas['Channel'] ?? null;
            if ($ch === null) continue;

            // Build anchor and legacy-compatible aliases.
            $canonical        = sprintf('%s.ADDR:%02d.CH:%02d', $can, $addr, $ch);
            $sensorPathLower  = sprintf('%s.%d.CH%d', strtolower($can), $addr, $ch); // UI expects can0.1.CH1.
            $sensorPathUpper  = sprintf('%s.%d.CH%d', strtoupper($can), $addr, $ch); // CAN0.1.CH1
            $serialAnchor     = null;

            $aliases = [
                $canonical,
                sprintf('%s.ADDR:%d.CH:%d', $can, $addr, $ch),                 // No zero padding.
                $sensorPathLower,
                $sensorPathUpper,
                sprintf('%s.ADDR:%d.CH%d', $can, $addr, $ch),                   // CAN0.ADDR:1.CH1
                sprintf('%s.addr:%02d.ch:%02d', strtolower($can), $addr, $ch), // Lowercase variant.
            ];
            if ($serial !== null && $serial !== '') {
                $serialAnchor = sprintf('%s.CH%d', $serial, $ch);
                $aliases[] = $serialAnchor;                 // Serial_number + CHx
            }

            $cnt   = (int)($meas['CNT'] ?? 0);
            $cs    = $meas['Channel_Status'] ?? [];
            $unit  = $meas['Unit'] ?? null;
            $value = $meas['Last_Meas'] ?? $meas['Meas_avg'] ?? null;

            $stVal  = $cs['Channel_status_val'] ?? 0;
            $noSens = !empty($cs['No_Sensor']);
            $over   = !empty($cs['Over_Range']);
            $out    = !empty($cs['Out_of_Range']);

            $anchorUpper = array_map('strtoupper', $aliases);
            $linkedByConfig = false;
            foreach ($anchorUpper as $au) {
                if (isset($xmlAnchors[$au])) {
                    $linkedByConfig = true;
                    break;
                }
            }

            $status  = 'Okay';
            $valid   = true;
            $explain = null;

            $hasUnit      = is_string($unit) && $unit !== '';
            $hasValueInfo = $value !== null
                || (isset($meas['Meas_avg']) && is_numeric($meas['Meas_avg']))
                || (isset($meas['Meas_max']) && is_numeric($meas['Meas_max']))
                || (isset($meas['Meas_min']) && is_numeric($meas['Meas_min']));

            if ($stVal && !$hasUnit && !$hasValueInfo) {
                // Device reports a physical channel but no Channel/Sensor definition.
                $status  = 'Unlinked';
                $valid   = false;
                $value   = null;
                $explain = 'Unlinked';
            }

            $regDone   = is_string($regStatus) && strcasecmp($regStatus, 'Done') === 0;
            $hasSensor = !$noSens;
            if ($explain === null && $regDone && $hasSensor && !$linkedByConfig) {
                // SDAQ registered + has sensor, but no OPC UA anchor.
                $status  = 'Unlinked';
                $valid   = false;
                $value   = null;
                $explain = 'Unlinked';
            }

            if ($explain === null) {
                if ($noSens) {
                    $status = 'NO_Sensor';
                    $valid  = false;
                } elseif (!empty($stVal) && !$over && !$out) {
                    // Error flag without a specific category.
                    $status = 'Unclassified';
                    $valid  = false;
                } elseif ($cnt === 0) {
                    // Matches core MORFEAS_MEAS_ERROR_STALL (-903).
                    $status = 'Stall';
                    $valid  = false;
                } elseif ($over) {
                    $status = 'Over Range';
                    $valid  = true;
                } elseif ($out) {
                    $status = 'Out of Range';
                    $valid  = true;
                }
            }

            // SDAQ logstat status is still defined by Channel_Status/CNT.
            // Current core emits reserved SDAQ values (-902..-904) in the same
            // situations, so here the code only marks the measurement invalid.
            if (sdaq_reserved_error_code($value) !== null) {
                $valid = false;
            }

            $entry = [
                'status'        => $status,
                'is_meas_valid' => $valid,
                'meas_value'    => is_numeric($value) ? (float)$value : null,
	                'meas_unit'     => is_string($unit) ? $unit : null,
	                'device_user_identifier' => is_string($deviceType) ? $deviceType : null,
	                'link_state'    => $linkedByConfig ? 'Linked' : 'Unlinked',
	                'address_anchor' => $canonical,
	            ];

            if ($explain !== null) {
                $entry['error_explanation'] = $explain;
            }

            if (isset($calibByCh[$ch])) {
                $entry['cal_date']   = $calibByCh[$ch]['cal_date'];
                $entry['cal_period'] = $calibByCh[$ch]['cal_period'];
            }

            foreach ($aliases as $alias) {
                $map['anchors'][$alias] = $entry;
            }

            $preferred = $serialAnchor ?: $sensorPathLower;

            $map['channels'][] = [
                'preferred_anchor'  => $preferred,
                'display_anchor'    => $preferred,
                'connection_anchor' => $preferred,
                'serial_anchor'     => $serialAnchor,
                'address_anchor'    => $canonical,
                'aliases'           => $aliases,
                'link_state'        => $linkedByConfig ? 'Linked' : 'Unlinked',
                'has_sensor'        => $hasSensor,
                'registration'      => $regStatus ?: 'Unknown',
                'entry'             => $entry,
            ];
        }
    }

    return $map;
}
