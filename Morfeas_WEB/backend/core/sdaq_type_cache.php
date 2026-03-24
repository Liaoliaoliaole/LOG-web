<?php

require_once __DIR__ . '/paths.php';

function backend_sdaq_type_cache_path(): string
{
    return backend_env_file(
        'MORFEAS_SDAQ_TYPE_CACHE_PATH',
        '/home/morfeas/configuration/morfeas_sdaq_type_cache.json',
        dirname(__DIR__, 2)
    );
}

function sdaq_type_cache_read(): array
{
    $path = backend_sdaq_type_cache_path();
    if (!is_file($path)) {
        return [];
    }

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $types = $decoded['types'] ?? $decoded;
    if (!is_array($types)) {
        return [];
    }

    $out = [];
    foreach ($types as $k => $v) {
        $key = strtoupper(trim((string)$k));
        $val = trim((string)$v);
        if ($key === '' || $val === '') {
            continue;
        }
        $out[$key] = $val;
    }
    return $out;
}

function sdaq_type_cache_write(array $types): void
{
    $path = backend_sdaq_type_cache_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return;
    }

    $payload = [
        'updated_at' => date('c'),
        'types' => $types,
    ];

    @file_put_contents(
        $path,
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        LOCK_EX
    );
}

function sdaq_cache_key_from_anchor(?string $anchor): ?string
{
    $anchor = strtoupper(trim((string)$anchor));
    if ($anchor === '') {
        return null;
    }

    if (preg_match('/^(CAN\w+)\.ADDR:(\d{1,3})/i', $anchor, $m)) {
        return sprintf('%s.ADDR:%02d', strtoupper($m[1]), (int)$m[2]);
    }
    if (preg_match('/^(CAN\w+)\.?(\d{1,3})\.CH/i', $anchor, $m)) {
        return sprintf('%s.ADDR:%02d', strtoupper($m[1]), (int)$m[2]);
    }
    return null;
}
