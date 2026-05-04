<?php

function nox_runtime_online_window(array $logstat): ?int
{
    $value = $logstat['NOx_sensor_lifetime_sec'] ?? null;
    if (!is_numeric($value) || (int) $value <= 0) {
        return null;
    }

    return (int) $value;
}

function nox_runtime_sensor_detected(array $sensor, ?int $now = null, ?int $onlineWindowSec = null): bool
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
    if ($onlineWindowSec === null) {
        // Older core builds did not export the lifetime. In that case, trust
        // core's logstat filtering instead of inventing a web-side timeout.
        return true;
    }

    return $lastSeen <= ($now + 2) && ($now - $lastSeen) <= $onlineWindowSec;
}

function nox_runtime_bus_detected(array $logstat, ?int $now = null): bool
{
    $sensors = $logstat['NOx_sensors'] ?? $logstat['sensors'] ?? [];
    if (!is_array($sensors)) {
        return false;
    }

    $now = $now ?? time();
    $onlineWindowSec = nox_runtime_online_window($logstat);
    foreach ($sensors as $sensor) {
        if (is_array($sensor) && nox_runtime_sensor_detected($sensor, $now, $onlineWindowSec)) {
            return true;
        }
    }

    return false;
}
