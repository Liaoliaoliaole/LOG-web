<?php

function backend_runtime_root_dir(): string
{
    return '/tmp/morfeas_web_sessions';
}

function backend_runtime_locks_dir(): string
{
    return backend_runtime_root_dir() . '/locks';
}

function backend_runtime_undos_dir(): string
{
    return backend_runtime_root_dir() . '/undos';
}

function backend_runtime_ensure_dirs(): void
{
    $dirs = [
        backend_runtime_root_dir(),
        backend_runtime_locks_dir(),
        backend_runtime_undos_dir(),
    ];

    foreach ($dirs as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create runtime directory: $dir");
        }
    }
}

function backend_named_lock_file(string $name): string
{
    backend_runtime_ensure_dirs();
    return backend_runtime_locks_dir() . '/.internal_' . sha1($name) . '.lck';
}

function backend_with_named_lock(string $name, callable $fn)
{
    $path = backend_named_lock_file($name);
    $fp = @fopen($path, 'c+');
    if (!is_resource($fp)) {
        throw new RuntimeException("Unable to open lock file: $path");
    }

    if (!@flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException("Unable to acquire lock: $name");
    }

    try {
        return $fn();
    } finally {
        @flock($fp, LOCK_UN);
        @fclose($fp);
    }
}

function backend_resource_lock_name(string $kind, string $resourceId): string
{
    return 'resource:' . trim($kind) . ':' . trim($resourceId);
}

function backend_resource_lock_id_from_path(string $path): string
{
    return sha1(strtolower(trim($path)));
}

function backend_with_resource_file_lock(string $kind, string $resourcePath, callable $fn)
{
    $resourceId = backend_resource_lock_id_from_path($resourcePath);
    return backend_with_named_lock(backend_resource_lock_name($kind, $resourceId), $fn);
}

function backend_atomic_write_file(string $path, string $contents, ?int $mode = null): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Unable to create directory for file write: $dir");
    }

    $tmp = @tempnam($dir, basename($path) . '.tmp.');
    if (!is_string($tmp) || $tmp === '') {
        throw new RuntimeException("Unable to allocate temporary file for: $path");
    }

    $ok = false;
    try {
        if (@file_put_contents($tmp, $contents) === false) {
            throw new RuntimeException("Unable to write temporary file for: $path");
        }
        if ($mode !== null) {
            @chmod($tmp, $mode);
        }
        if (!@rename($tmp, $path)) {
            throw new RuntimeException("Unable to replace target file: $path");
        }
        $ok = true;
        clearstatcache(true, $path);
    } finally {
        if (!$ok && is_file($tmp)) {
            @unlink($tmp);
        }
    }
}
