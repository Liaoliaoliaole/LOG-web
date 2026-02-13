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

function backend_sandbox_dir(): string
{
    // Legacy-style hardcoded path: keep sandbox as an empty local dir.
    return '/var/lib/morfeas_web_empty/';

    // Sandbox/env-based mode (kept for future use):
    // $baseDir = dirname(__DIR__);
    // return backend_env_dir('LOG_WEB_SANDBOX_DIR', $baseDir . '/config_sandbox', $baseDir);
}

function backend_ramdisk_dir(): string
{
    // Legacy-style hardcoded live path.
    return '/mnt/ramdisk/';

    // Env-based mode (kept for future use):
    // return backend_env_dir('LOG_WEB_RAMDISK_DIR', '/mnt/ramdisk', dirname(__DIR__));
}

function backend_opcua_config_path(): string
{
    // Legacy-style hardcoded live path.
    return '/home/morfeas/configuration/OPC_UA_Config.xml';

    // Env/sandbox-based mode (kept for future use):
    // return backend_env_file(
    //     'LOG_WEB_OPCUA_CONFIG_PATH',
    //     rtrim(backend_sandbox_dir(), '/') . '/OPC_UA_Config.mock.xml',
    //     dirname(__DIR__)
    // );
}

function backend_log_config_path(): string
{
    // Legacy-style hardcoded live path.
    return '/home/morfeas/configuration/Morfeas_config.xml';

    // Env/sandbox-based mode (kept for future use):
    // return backend_env_file(
    //     'LOG_WEB_LOG_CONFIG_PATH',
    //     rtrim(backend_sandbox_dir(), '/') . '/LOG_config.mock.xml',
    //     dirname(__DIR__)
    // );
}

function backend_iso_standard_dir(): string
{
    // Legacy-style hardcoded live path.
    return '/home/morfeas/configuration/';

    // Env-based mode (kept for future use):
    // return backend_env_dir(
    //     'LOG_WEB_ISO_STANDARD_DIR',
    //     '/home/pi/Morfeas_config/iso_standards',
    //     dirname(__DIR__)
    // );
}
