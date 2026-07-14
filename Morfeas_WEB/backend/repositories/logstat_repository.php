<?php

function logstat_collect_paths(string $pattern, string $ramdisk): array
{
    $ram = glob($ramdisk . $pattern) ?: [];
    return array_values(array_unique($ram));
}

function logstat_load_json(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}
