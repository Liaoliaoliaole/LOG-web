<?php

function iso_collect_files(string $sandboxDir, string $isoStandardDir): array
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

function iso_resolve_upload_dir(string $sandboxDir, string $isoStandardDir): string
{
    $piDir = rtrim($isoStandardDir, '/') . '/';
    return $piDir;

    // Sandbox fallback mode (kept for future use):
    // if (is_dir($piDir) && is_writable($piDir)) {
    //     return $piDir;
    // }
    //
    // $sandboxIso = $sandboxDir . 'iso_standards/';
    // if (!is_dir($sandboxIso)) {
    //     @mkdir($sandboxIso, 0775, true);
    // }
    //
    // return rtrim($sandboxIso, '/') . '/';
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
