<?php

function nox_runtime_online_window(array $logstat): ?int
{
    $value = $logstat['NOx_sensor_lifetime_sec'] ?? null;
    return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
}

function nox_runtime_sensor_detected(array $sensor): bool
{
    $lastSeen = $sensor['last_seen'] ?? null;
    if (!is_numeric($lastSeen)) {
        return false;
    }

    $lastSeen = (int) $lastSeen;
    if ($lastSeen <= 0) {
        return false;
    }

    return true;
}

function nox_runtime_bus_detected(array $logstat): bool
{
    $sensors = $logstat['NOx_sensors'] ?? $logstat['sensors'] ?? [];
    if (!is_array($sensors)) {
        return false;
    }

    foreach ($sensors as $sensor) {
        if (is_array($sensor) && nox_runtime_sensor_detected($sensor)) {
            return true;
        }
    }

    return false;
}
