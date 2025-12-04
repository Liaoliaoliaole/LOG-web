<?php
// backend/core/logstat_mti.php

/**
 * 读取一组 MTI logstat JSON
 *
 * 返回：
 *  - 'anchors'     => anchor -> 状态/测量
 *  - 'connections' => Identifier -> Connection_status
 */
function mti_load_anchor_map(array $paths): array
{
    $anchors     = [];
    $connections = [];
    $ipv4ById    = [];

    foreach ($paths as $jsonPath) {
        if (!is_file($jsonPath)) continue;

        $raw = file_get_contents($jsonPath);
        if ($raw === false || $raw === '') continue;

        $data = json_decode($raw, true);
        if (!is_array($data)) continue;

        $identifier = $data['Identifier'] ?? null;
        if (is_numeric($identifier)) {
            $connections[$identifier] = $data['Connection_status'] ?? null;
        }

        if ($identifier !== null && !empty($data['IPv4_address']) && is_string($data['IPv4_address'])) {
            $ipv4ById[(string)$identifier] = $data['IPv4_address'];
        }

        if (($data['Connection_status'] ?? null) !== 'Okay') {
            continue;
        }

        $tele     = $data['Tele_data'] ?? null;
        $teleType = $data['MTI_status']['Tele_Device_type'] ?? null;

        if (!$teleType || !is_array($tele) || !isset($tele['CHs']) || !is_array($tele['CHs']) || $identifier === null) {
            continue;
        }

        $typeSlug = is_string($teleType) ? $teleType : '';
        $typeSlug = str_starts_with($typeSlug, 'Tele_') ? substr($typeSlug, 5) : $typeSlug;

        foreach ($tele['CHs'] as $idx => $value) {
            $chNum = $idx + 1;
            $anchor = sprintf('%s.%s.CH%d', $identifier, $typeSlug, $chNum);

            $status = 'Okay';
            $valid  = is_numeric($value) && ($tele['IsValid'] ?? true);
            $meas   = $valid ? (float)$value : null;

            if (is_string($value) && strcasecmp($value, 'No sensor') === 0) {
                $status = 'No sensor';
                $valid  = false;
            } elseif (!$valid) {
                $status = 'Unclassified';
            }

            $anchors[$anchor] = [
                'status'        => $status,
                'is_meas_valid' => $valid,
                'meas_value'    => $meas,
                'meas_unit'     => '°C',
            ];

            if ($typeSlug !== $teleType) {
                $anchors[sprintf('%s.%s.CH%d', $identifier, $teleType, $chNum)] = $anchors[$anchor];
            }
        }
    }

    return [
        'anchors'     => $anchors,
        'connections' => $connections,
        'ipv4'        => $ipv4ById,
    ];
}
