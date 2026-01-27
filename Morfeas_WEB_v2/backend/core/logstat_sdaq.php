<?php
// backend/core/logstat_sdaq.php

/**
 * 从 SDAQ logstat JSON 生成 “anchor -> 状态/测量值” 的映射，
 * 同时附带每个通道的链接态信息，便于前端展示“未链接”通道。
 *
 * @param string $jsonPath    例如 /mnt/ramdisk/logstat_SDAQs_can1.json
 * @param array  $xmlAnchors  来自 OPC_UA_Config.xml 的 SDAQ anchor 集合（已大写）
 * @return array 形如：
 *   [
 *     'anchors'  => [
 *       'CAN1.ADDR:01.CH:01' => [
 *         'status'        => 'Okay' | 'Stall' | 'Out of Range' | 'Over Range' | 'No sensor' | 'Unclassified' | 'Unlinked',
 *         'is_meas_valid' => bool,
 *         'meas_value'    => float|null,
 *         'meas_unit'     => string|null,
 *         'link_state'    => 'Linked' | 'Unlinked',
 *       ],
 *       ...
 *     ],
 *     'channels' => [
 *       [
 *         'preferred_anchor' => '726057806.CH16', // Serial + CH 用于自动补行
 *         'aliases'          => [...],
 *         'link_state'       => 'Linked' | 'Unlinked',
 *         'has_sensor'       => bool,
 *         'registration'     => 'Done' | 'Unknown',
 *         'entry'            => 上述 anchors 里的 entry
 *       ],
 *       ...
 *     ]
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

    // CAN 接口名字：can1 -> CAN1（若字段缺失则从文件名推断）
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

        // 构建 channel -> 校准信息的快速索引（若存在）
        $calibByCh = [];
        if (!empty($sdaq['Calibration_Data']) && is_array($sdaq['Calibration_Data'])) {
            foreach ($sdaq['Calibration_Data'] as $cal) {
                $chId = $cal['Channel'] ?? null;
                if ($chId === null) continue;

                $calDate   = $cal['Calibration_date_UNIX'] ?? null;
                $calPeriod = $cal['Calibration_period'] ?? null; // 单位：天（旧版按天累加）

                if ($calDate !== null) {
                    $calibByCh[$chId] = [
                        'cal_date'   => gmdate('Y-m-d', (int)$calDate),
                        // 前端按“月”累加，下方把天数换算成约等的月份（向上取整，避免提前过期）
                        'cal_period' => is_numeric($calPeriod) ? (int)ceil(((float)$calPeriod) / 30) : null,
                    ];
                }
            }
        }

        foreach ($measArr as $meas) {
            $ch = $meas['Channel'] ?? null;
            if ($ch === null) continue;

            // 组装 anchor：CAN1.ADDR:01.CH:01，并附加一组兼容旧版/不补零的别名
            $canonical        = sprintf('%s.ADDR:%02d.CH:%02d', $can, $addr, $ch);
            $sensorPathLower  = sprintf('%s.%d.CH%d', strtolower($can), $addr, $ch); // can0.1.CH1（UI 期望）
            $sensorPathUpper  = sprintf('%s.%d.CH%d', strtoupper($can), $addr, $ch); // CAN0.1.CH1
            $serialAnchor     = null;

            $aliases = [
                $canonical,
                sprintf('%s.ADDR:%d.CH:%d', $can, $addr, $ch),                 // 不补零
                $sensorPathLower,
                $sensorPathUpper,
                sprintf('%s.ADDR:%d.CH%d', $can, $addr, $ch),                   // CAN0.ADDR:1.CH1
                sprintf('%s.addr:%02d.ch:%02d', strtolower($can), $addr, $ch), // 全小写
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
                // 设备报告了物理通道存在，但缺乏 Channel/Sensor 定义
                $status  = 'Unlinked';
                $valid   = false;
                $value   = null;
                $explain = 'Unlinked';
            }

            $regDone   = is_string($regStatus) && strcasecmp($regStatus, 'Done') === 0;
            $hasSensor = !$noSens;
            if ($explain === null && $regDone && $hasSensor && !$linkedByConfig) {
                // SDAQ 已注册 + 有传感器，但 OPC UA 中没有对应 anchor
                $status  = 'Unlinked';
                $valid   = false;
                $value   = null;
                $explain = 'Unlinked';
            }

            if ($explain === null) {
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
