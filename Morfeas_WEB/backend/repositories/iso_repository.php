<?php

function iso_collect_files(string $isoStandardDir): array
{
    $paths = [];
    $locations = [
        ['live', $isoStandardDir, '*.xml'],
        // Sandbox mode (kept for future use):
        // ['sandbox', $sandboxDir . 'iso_standards/', '*.xml'],
    ];

    foreach ($locations as [$source, $dir, $pattern]) {
        $dir = rtrim($dir, '/') . '/';
        foreach (glob($dir . $pattern) ?: [] as $path) {
            $paths[$path] = $source;
        }
    }

    $items = [];
    $index = 0;
    foreach ($paths as $path => $source) {
        $items[] = [
            'id'         => base64_encode($path),
            'name'       => basename($path),
            'path'       => $path,
            'source'     => $source,
            'is_default' => $index === 0,
        ];
        $index++;
    }

    return $items;
}

function iso_resolve_upload_dir(string $isoStandardDir): string
{
    $piDir = rtrim($isoStandardDir, '/') . '/';
    return $piDir;
}

function iso_ensure_upload_dir(string $dir): void
{
    if (is_dir($dir)) {
        return;
    }
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Failed to create ISO upload directory: {$dir}");
    }
}

function iso_sanitize_filename(string $name): string
{
    $base = basename($name);
    $base = preg_replace('/[^A-Za-z0-9._-]/', '_', $base);
    if ($base === '' || $base === '.' || $base === '..') {
        $base = 'ISOstandard.xml';
    }
    if (!str_ends_with(strtolower($base), '.xml')) {
        $base .= '.xml';
    }
    return $base;
}

function iso_unique_filename(string $dir, string $filename): string
{
    $dir = rtrim($dir, '/') . '/';
    if (!file_exists($dir . $filename)) {
        return $filename;
    }

    $base = pathinfo($filename, PATHINFO_FILENAME);
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    $ext = $ext !== '' ? '.' . $ext : '';
    $stamp = date('Y_m_d_H_i_s');

    $candidate = "{$base}_{$stamp}{$ext}";
    if (!file_exists($dir . $candidate)) {
        return $candidate;
    }

    for ($i = 1; $i <= 9999; $i++) {
        $candidate = "{$base}_{$stamp}_{$i}{$ext}";
        if (!file_exists($dir . $candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException('Unable to generate unique ISO file name');
}

function iso_find_file_path(array $items, ?string $targetId): array
{
    $pathLookup = [];
    foreach ($items as $item) {
        $pathLookup[$item['id']] = $item['path'];
    }

    $paths = array_values($pathLookup);
    if ($targetId && isset($pathLookup[$targetId])) {
        $paths = [$pathLookup[$targetId]];
    }

    return $paths;
}
