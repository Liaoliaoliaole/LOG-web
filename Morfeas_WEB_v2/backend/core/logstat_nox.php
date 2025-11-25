<?php
// backend/core/logstat_nox.php

/**
 * 读取 NOX logstat JSON，生成 anchor -> 状态 的映射
 *
 * @param string $jsonPath 例如 '/mnt/ramdisk/logstat_NOX_can2.json'
 * @return array anchor => ['status','is_meas_valid','meas_value','meas_unit']
 */
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

    $iface   = strtoupper($data['CANBus_interface'] ?? 'CAN2');
    $sensors = $data['sensors'] ?? [];
    if (!is_array($sensors)) {
        return [];
    }

    $map = [];

    foreach ($sensors as $s) {
        $addr = isset($s['Address']) ? (int)$s['Address'] : null;

        // 原始 anchor（如果 JSON 里有）
        $anchorRaw = isset($s['anchor']) && $s['anchor'] !== ''
            ? $s['anchor']
            : null;

        // 按 Address 生成两种形式: ADDR:1.NOx 和 ADDR:01.NOx
        $keys = [];

        if ($anchorRaw) {
            $keys[] = $anchorRaw;
        }
        if ($addr !== null) {
            $keys[] = sprintf('%s.ADDR:%d.NOx',  $iface, $addr);   // 不补零
            $keys[] = sprintf('%s.ADDR:%02d.NOx', $iface, $addr);  // 补两位零
        }

        $keys = array_unique($keys);
        if (!$keys) {
            continue;
        }

        $valid = !empty($s['is_meas_valid']);
        $err   = trim((string)($s['Error_text'] ?? ''));

        $status = 'Okay';
        if (!$valid) {
            if ($err !== '' && strcasecmp($err, 'No error') !== 0) {
                $status = $err; // "Open Wire" / "Short circuit"
            } else {
                $status = 'Unclassified';
            }
        }

        $value = $valid ? ($s['NOx_ppm'] ?? null) : null;

        $row = [
            'status'        => $status,
            'is_meas_valid' => $valid,
            'meas_value'    => is_numeric($value) ? (float)$value : null,
            'meas_unit'     => 'ppm',
        ];

        foreach ($keys as $k) {
            $map[$k] = $row;
        }
    }

    return $map;
}
