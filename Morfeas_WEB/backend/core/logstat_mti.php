<?php

function mti_reserved_error_code($value): ?int
{
    if (!is_numeric($value)) {
        return null;
    }

    $code = (int)round((float)$value);
    return in_array($code, [-901, -902, -903, -904], true) ? $code : null;
}

function mti_status_from_error_code(int $code, ?string $connectionStatus = null): string
{
    // Translate a core-emitted error code into a human-readable status string.
    // Connection status distinguishes device-level OFF-Line from per-channel
    // Disconnected when both are represented by -901.
    if ($code === -901) {
        if ($connectionStatus !== null && strcasecmp(trim($connectionStatus), 'Okay') !== 0) {
            return 'OFF-Line';
        }
        return 'Disconnected';
    }
    if ($code === -902) {
        return 'No sensor';
    }
    return 'Unclassified';
}

function mti_load_anchor_map(array $paths): array
{
    $anchors     = [];
    $connections = [];
    $ipv4ById    = [];
    $teleTypes   = [];

    foreach ($paths as $jsonPath) {
        if (!is_file($jsonPath)) continue;

        $raw = file_get_contents($jsonPath);
        if ($raw === false || $raw === '') continue;

        $data = json_decode($raw, true);
        if (!is_array($data)) continue;

        $identifier = $data['Identifier'] ?? null;
        $identifierKey = is_numeric($identifier) ? (string)$identifier : null;
        if ($identifierKey !== null) {
            $connections[$identifierKey] = $data['Connection_status'] ?? null;
        }

        if ($identifierKey !== null && !empty($data['IPv4_address']) && is_string($data['IPv4_address'])) {
            $ipv4ById[$identifierKey] = $data['IPv4_address'];
        }

        $connectionStatus = is_string($data['Connection_status'] ?? null) ? $data['Connection_status'] : null;
        if ($connectionStatus === null || strcasecmp(trim($connectionStatus), 'Okay') !== 0) {
            continue;
        }

        $tele     = $data['Tele_data'] ?? null;
        $teleType = $data['MTI_status']['Tele_Device_type'] ?? null;
        if ($identifierKey !== null && is_string($teleType) && $teleType !== '') {
            $teleTypes[$identifierKey] = $teleType;
        }

        if (!$teleType || !is_array($tele) || $identifierKey === null) {
            continue;
        }

        $typeSlug = is_string($teleType) ? $teleType : '';
        $typeSlug = (substr($typeSlug, 0, 5) === 'Tele_') ? substr($typeSlug, 5) : $typeSlug;

        if ($typeSlug === 'RMSW/MUX') {
            foreach ($tele as $device) {
                if (!is_array($device) || ($device['Dev_type'] ?? null) !== 'Mini_RMSW') {
                    continue;
                }

                $devId = $device['Dev_ID'] ?? null;
                $chs = $device['CHs_meas'] ?? null;
                if ($devId === null || !is_array($chs)) {
                    continue;
                }

                foreach ($chs as $idx => $value) {
                    $chNum = $idx + 1;
                    $anchor = sprintf('%s.ID:%s.CH%d', $identifierKey, $devId, $chNum);
                    $valid = is_numeric($value);

                    $anchors[$anchor] = [
                        'status'        => $valid ? 'Okay' : (is_string($value) && $value !== '' ? $value : 'Unclassified'),
                        'is_meas_valid' => $valid,
                        'meas_value'    => $valid ? (float)$value : null,
                        'meas_unit'     => '°C',
                    ];
                }
            }
            continue;
        }

        if (!in_array($typeSlug, ['TC16', 'TC8', 'TC4', 'QUAD'], true) ||
            !isset($tele['CHs']) ||
            !is_array($tele['CHs'])) {
            continue;
        }

        foreach ($tele['CHs'] as $idx => $value) {
            $chNum = $idx + 1;
            $anchor = sprintf('%s.%s.CH%d', $identifierKey, $typeSlug, $chNum);

            $errorCode = mti_reserved_error_code($value);
            if ($errorCode !== null) {
                $status = mti_status_from_error_code($errorCode, $connectionStatus);
                $valid  = false;
                $meas   = (float)$errorCode;
            } elseif (is_numeric($value) && ($tele['IsValid'] ?? true)) {
                $status = 'Okay';
                $valid  = true;
                $meas   = (float)$value;
            } else {
                $status = 'Unclassified';
                $valid  = false;
                $meas   = null;
            }

            $anchors[$anchor] = [
                'status'        => $status,
                'is_meas_valid' => $valid,
                'meas_value'    => $meas,
                'meas_unit'     => $typeSlug === 'QUAD' ? '' : '°C',
            ];

            if ($typeSlug !== $teleType) {
                $anchors[sprintf('%s.%s.CH%d', $identifierKey, $teleType, $chNum)] = $anchors[$anchor];
            }
        }
    }

    return [
        'anchors'     => $anchors,
        'connections' => $connections,
        'ipv4'        => $ipv4ById,
        'tele_types'  => $teleTypes,
    ];
}
