<?php

require_once __DIR__ . '/../repositories/logstat_repository.php';

const IOBOX_DEVICE_NAME_REGEX = '/^[A-Za-z0-9_-]{1,64}$/';

function iobox_validate_name(string $name): string
{
    $normalized = trim($name);
    if ($normalized === '' || preg_match(IOBOX_DEVICE_NAME_REGEX, $normalized) !== 1) {
        throw new InvalidArgumentException('Invalid IOBOX device name');
    }

    return $normalized;
}

function iobox_find_logstat_by_name(string $ramdisk, string $name): ?array
{
    $normalizedName = iobox_validate_name($name);
    $directPath = rtrim($ramdisk, '/') . '/logstat_IOBOX_' . $normalizedName . '.json';
    $direct = logstat_load_json($directPath);
    if (is_array($direct)) {
        return $direct;
    }

    foreach (logstat_collect_paths('logstat_IOBOX*.json', $ramdisk) as $path) {
        $base = basename($path);
        if (preg_match('/^logstat_IOBOX_(.+)\.json$/', $base, $m) !== 1) {
            continue;
        }
        if (strcasecmp($m[1], $normalizedName) !== 0) {
            continue;
        }
        $data = logstat_load_json($path);
        if (is_array($data)) {
            return $data;
        }
    }

    return null;
}

function iobox_load_state(string $ramdisk, string $name): array
{
    $normalizedName = trim($name);
    $raw = iobox_find_logstat_by_name($ramdisk, $normalizedName);

    if (!is_array($raw)) {
        return [
            'Dev_name' => $normalizedName,
            'Connection_status' => 'No logstat data',
            'IPv4_address' => '',
        ];
    }

    if (trim((string)($raw['Dev_name'] ?? '')) === '') {
        $raw['Dev_name'] = $normalizedName;
    }

    return $raw;
}
