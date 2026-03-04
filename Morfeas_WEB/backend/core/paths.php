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
