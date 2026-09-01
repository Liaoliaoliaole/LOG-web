<?php

function backend_is_absolute_path(string $path): bool
{
    return $path !== '' && ($path[0] === '/' || preg_match('/^[A-Za-z]:\\\\/', $path) === 1);
}

function backend_resolve_path(string $path, string $baseDir): string
{
    if (backend_is_absolute_path($path)) {
        return $path;
    }
    return rtrim($baseDir, '/') . '/' . ltrim($path, '/');
}

function backend_env_dir(string $env, string $default, string $baseDir): string
{
    $value = getenv($env);
    if ($value === false || trim($value) === '') {
        $value = $default;
    }
    $value = trim($value);
    $value = backend_resolve_path($value, $baseDir);
    return rtrim($value, '/') . '/';
}

function backend_env_file(string $env, string $default, string $baseDir): string
{
    $value = getenv($env);
    if ($value === false || trim($value) === '') {
        $value = $default;
    }
    $value = trim($value);
    return backend_resolve_path($value, $baseDir);
}

function backend_ramdisk_dir(): string
{
    return '/mnt/ramdisk/';
}

function backend_opcua_config_path(): string
{
    return '/home/morfeas/configuration/OPC_UA_Config.xml';
}

function backend_log_config_path(): string
{
    return '/home/morfeas/configuration/Morfeas_config.xml';
}

function backend_iso_standard_dir(): string
{
    return '/home/morfeas/configuration/iso_standards/';
}

/*
 * Discover a Core checkout by content, independent of its directory name.
 * Exactly one canonical sibling containing configuration/Morfeas.dtd must
 * exist; zero or multiple distinct candidates are unresolved.
 */
function backend_core_src_dir_in(string $searchRoot): ?string
{
    $candidates = [];
    $pattern = rtrim($searchRoot, '/') . '/*/configuration/Morfeas.dtd';
    foreach (glob($pattern) ?: [] as $dtd) {
        $candidate = realpath(dirname($dtd, 2));
        if ($candidate !== false) {
            $candidates[$candidate] = true;
        }
    }

    return count($candidates) === 1 ? (string)key($candidates) : null;
}

/* Explicit MORFEAS_CORE_SRC_DIR wins; otherwise discover one sibling Core. */
function backend_core_src_dir(): ?string
{
    $override = getenv('MORFEAS_CORE_SRC_DIR');
    if (is_string($override) && trim($override) !== '') {
        $resolved = realpath(trim($override));
        return ($resolved !== false && is_dir($resolved)) ? $resolved : null;
    }

    // __DIR__ is <web checkout>/Morfeas_WEB/backend/core; four levels up is
    // the directory containing the sibling Web and Core checkouts.
    return backend_core_src_dir_in(dirname(__DIR__, 4));
}

/*
 * The configuration/ directory of the Core checkout -- i.e. the directory
 * holding Morfeas.dtd -- or null when no Core checkout is reachable.
 */
function backend_core_dtd_dir(): ?string
{
    $coreDir = backend_core_src_dir();
    if ($coreDir === null) {
        return null;
    }
    $dtdDir = realpath($coreDir . '/configuration');
    return ($dtdDir !== false && is_file($dtdDir . '/Morfeas.dtd')) ? $dtdDir : null;
}
