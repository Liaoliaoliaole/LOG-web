<?php
// backend/core/logstat_sdaq.php

/**
 * 从 SDAQ logstat JSON 生成 “anchor -> 状态/测量值” 的映射
 *
 * @param string $jsonPath 例如 /mnt/ramdisk/logstat_SDAQs_can1.json
 * @return array 形如：
 *   [
 *     'CAN1.ADDR:01.CH:01' => [
 *       'status'        => 'Okay' | 'Stall' | 'Out of Range' | 'Over Range' | 'No sensor' | 'Unclassified',
 *       'is_meas_valid' => bool,
 *       'meas_value'    => float|null,
 *       'meas_unit'     => string|null,
 *     ],
 *     ...
 *   ]
 */
function sdaq_detect_bus(array $data, string $jsonPath): string
{
    if (!empty($data['CANBus_interface'])) {
        return strtoupper((string)$data['CANBus_interface']);
    }

    // 尝试从文件名推断：logstat_SDAQs_can0.json -> CAN0
    $name = strtolower(basename($jsonPath));
    if (preg_match('/logstat_sdaq.*_(can\w+)/i', $name, $m)) {
        return strtoupper($m[1]);
    }

    return 'CAN1';
}

/**
 * 收集每个 SDAQ 的设备类型（按总线 + 地址）
 * 返回形如：['CAN1.ADDR:01' => 'SDAQ-I', ...]
 */
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

function sdaq_load_anchor_map(string $jsonPath): array
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

    $map = [];

    // CAN 接口名字：can1 -> CAN1（若字段缺失则从文件名推断）
    $can = sdaq_detect_bus($data, $jsonPath);

    if (empty($data['SDAQs_data']) || !is_array($data['SDAQs_data'])) {
        return $map;
    }

    foreach ($data['SDAQs_data'] as $sdaq) {
        $deviceType = $sdaq['SDAQ_type'] ?? null;
        $addr = $sdaq['Address'] ?? null;
        if ($addr === null) {
            continue;
        }

        $measArr = $sdaq['Meas'] ?? [];
        if (!is_array($measArr)) continue;

        foreach ($measArr as $meas) {
            $ch = $meas['Channel'] ?? null;
            if ($ch === null) continue;

            // 组装 anchor：CAN1.ADDR:01.CH:01
            $anchor = sprintf('%s.ADDR:%02d.CH:%02d', $can, $addr, $ch);

            $cnt   = (int)($meas['CNT'] ?? 0);
            $cs    = $meas['Channel_Status'] ?? [];
            $unit  = $meas['Unit'] ?? null;
            $value = $meas['Last_Meas'] ?? $meas['Meas_avg'] ?? null;

            $stVal  = $cs['Channel_status_val'] ?? 0;
            $noSens = !empty($cs['No_Sensor']);
            $over   = !empty($cs['Over_Range']);
            $out    = !empty($cs['Out_of_Range']);

            $status = 'Okay';
            $valid  = true;

            if ($cnt === 0) {
                $status = 'Stall';
                $valid  = false;
                $value  = null;
            } elseif ($noSens) {
                $status = 'No sensor';
                $valid  = false;
                $value  = null;
            } elseif ($over) {
                $status = 'Over Range';
                $valid  = false;
                $value  = null;
            } elseif ($out) {
                $status = 'Out of Range';
                $valid  = false;   // 这里按“有错误”处理
            } elseif (!empty($stVal)) {
                // 有错误标志但不在以上几类
                $status = 'Unclassified';
                $valid  = false;
            }

            $map[$anchor] = [
                'status'        => $status,
                'is_meas_valid' => $valid,
                'meas_value'    => is_numeric($value) ? (float)$value : null,
                'meas_unit'     => is_string($unit) ? $unit : null,
                'device_user_identifier' => is_string($deviceType) ? $deviceType : null,
            ];
        }
    }

    return $map;
}
