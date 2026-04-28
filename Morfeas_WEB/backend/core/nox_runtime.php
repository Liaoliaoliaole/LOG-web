<?php

const NOX_SENSOR_ONLINE_WINDOW_SEC = 15;

function nox_runtime_sensor_detected(array $sensor, ?int $now = null): bool
{
    $lastSeen = $sensor['last_seen'] ?? null;
    if (!is_numeric($lastSeen)) {
        return false;
    }

    $lastSeen = (int) $lastSeen;
    if ($lastSeen <= 0) {
        return false;
    }

    $now = $now ?? time();
    return $lastSeen <= ($now + 2) && ($now - $lastSeen) <= NOX_SENSOR_ONLINE_WINDOW_SEC;
}

function nox_runtime_bus_detected(array $logstat, ?int $now = null): bool
{
    $sensors = $logstat['NOx_sensors'] ?? $logstat['sensors'] ?? [];
    if (!is_array($sensors)) {
        return false;
    }

    $now = $now ?? time();
    foreach ($sensors as $sensor) {
        if (is_array($sensor) && nox_runtime_sensor_detected($sensor, $now)) {
            return true;
        }
    }

    return false;
}
