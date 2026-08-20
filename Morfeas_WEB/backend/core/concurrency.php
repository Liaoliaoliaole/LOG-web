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

/*
 * flock() is bound to the open file description, not the process: a second
 * fopen()+flock() on the same name from deeper in the same call stack would
 * simply block until PHP's max_execution_time kills the request, instead of
 * failing fast. The lock/unlock discipline elsewhere in this codebase (the
 * "_body" vs. locked-wrapper naming convention) is what actually prevents
 * that today, but it only works as long as every caller remembers it; this
 * guard turns a violation into an immediate, diagnosable exception instead
 * of a silent hang (2026-08-19 code review, F-9).
 */
function backend_with_named_lock(string $name, callable $fn)
{
    static $held = [];

    if (isset($held[$name])) {
        throw new RuntimeException("Lock re-entrancy detected: '$name' is already held by this request");
    }

    $path = backend_named_lock_file($name);
    $fp = @fopen($path, 'c+');
    if (!is_resource($fp)) {
        throw new RuntimeException("Unable to open lock file: $path");
    }

    if (!@flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException("Unable to acquire lock: $name");
    }

    $held[$name] = true;
    try {
        return $fn();
    } finally {
        unset($held[$name]);
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

/*
 * Same same-directory-temp-then-rename atomicity as backend_atomic_write_file(),
 * plus an actual fsync() of the temp file's contents before the rename --
 * file_put_contents() only goes through PHP's stream buffering, it never
 * forces the bytes to disk. This is for FTP Restore's ordered dual-file
 * replace (plan §10.0.3): "两份 same-directory temp 完整写入并 fsync". Kept
 * as a separate function from backend_atomic_write_file() rather than
 * adding fsync there, so every other existing caller (Add/Edit/Delete/
 * Replace/TC16/Local JSON Restore) keeps its current, already-tested
 * behavior unchanged; only the one new caller that plan section actually
 * names opts into the extra syscall cost.
 */
function backend_atomic_write_file_synced(string $path, string $contents, ?int $mode = null): void
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
        $fp = @fopen($tmp, 'wb');
        if (!is_resource($fp)) {
            throw new RuntimeException("Unable to open temporary file for: $path");
        }
        try {
            if (@fwrite($fp, $contents) === false) {
                throw new RuntimeException("Unable to write temporary file for: $path");
            }
            if (!@fflush($fp) || !@fsync($fp)) {
                throw new RuntimeException("Unable to fsync temporary file for: $path");
            }
        } finally {
            @fclose($fp);
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
