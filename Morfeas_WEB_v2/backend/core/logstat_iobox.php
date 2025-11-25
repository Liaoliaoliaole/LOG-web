<?php
// backend/core/logstat_iobox.php

/**
 * 读取一组 IOBOX logstat JSON，合并成 anchor -> 状态 的映射
 *
 * @param array $paths 例如 ['/mnt/ramdisk/logstat_IOBOX_IOBOX_A.json']
 * @return array anchor => ['status','is_meas_valid','meas_value','meas_unit']
 */
function iobox_load_anchor_map(array $paths): array
{
    $map = [];

    foreach ($paths as $jsonPath) {
        if (!is_file($jsonPath)) {
            continue;
        }

        $raw = file_get_contents($jsonPath);
        if ($raw === false || $raw === '') {
            continue;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            continue;
        }

        $channels = $data['channels'] ?? [];
        if (!is_array($channels)) {
            continue;
        }

        foreach ($channels as $ch) {
            $anchor = $ch['anchor'] ?? null;
            if (!$anchor) {
                continue;
            }

            $cnt   = (int)($ch['CNT'] ?? 0);
            $no    = !empty($ch['No_sensor']);
            $disc  = !empty($ch['Disconnected']);
            $value = $ch['meas_value'] ?? null;
            $unit  = $ch['Unit'] ?? null;

            $status = 'Okay';
            $valid  = true;

            if ($no) {
                $status = 'No sensor';
                $valid  = false;
                $value  = null;
            } elseif ($disc) {
                $status = 'Disconnected';
                $valid  = false;
                $value  = null;
            } elseif ($cnt === 0) {
                // 这里给个 Stall 示例（你也可以不要）
                $status = 'Stall';
                $valid  = false;
            }

            $map[$anchor] = [
                'status'        => $status,
                'is_meas_valid' => $valid,
                'meas_value'    => is_numeric($value) ? (float)$value : null,
                'meas_unit'     => is_string($unit) ? $unit : null,
            ];
        }
    }

    return $map;
}
