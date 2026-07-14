<?php

require_once __DIR__ . '/concurrency.php';

function backend_session_registry_record_path(string $resourceType, string $resourceId): string
{
    backend_runtime_ensure_dirs();
    return backend_runtime_locks_dir() . '/' . sha1($resourceType . '|' . $resourceId) . '.json';
}

function backend_undo_registry_record_path(string $sessionId): string
{
    backend_runtime_ensure_dirs();
    return backend_runtime_undos_dir() . '/' . sha1($sessionId) . '.json';
}

function backend_operator_ip(): string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return $ip !== '' ? $ip : 'unknown';
}

function backend_session_token(): string
{
    $token = trim((string)($_SERVER['HTTP_X_MORFEAS_SESSION'] ?? ''));
    if ($token === '') {
        return '';
    }

    if (preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $token) !== 1) {
        return '';
    }

    return $token;
}

function backend_session_public_owner(array $record): array
{
    $sessionId = trim((string)($record['session_id'] ?? ''));
    return [
        'operator_ip' => trim((string)($record['operator_ip'] ?? 'unknown')),
        'session_hint' => $sessionId !== '' ? substr($sessionId, 0, 8) : '',
    ];
}

function backend_session_public_record(?array $record, ?string $currentSessionId = null): ?array
{
    if (!is_array($record)) {
        return null;
    }

    $sessionId = trim((string)($record['session_id'] ?? ''));
    return [
        'resource_type' => (string)($record['resource_type'] ?? ''),
        'resource_id' => (string)($record['resource_id'] ?? ''),
        'mode' => (string)($record['mode'] ?? ''),
        'created_at' => (int)($record['created_at'] ?? 0),
        'expires_at' => (int)($record['expires_at'] ?? 0),
        'owned_by_current_session' => $currentSessionId !== null && $currentSessionId !== '' && $sessionId === $currentSessionId,
        'owner' => backend_session_public_owner($record),
        'meta' => is_array($record['meta'] ?? null) ? $record['meta'] : [],
    ];
}

function backend_session_registry_read_json_file(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function backend_session_registry_write_json_file(string $path, array $record): void
{
    $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Unable to encode session registry record');
    }

    backend_atomic_write_file($path, $json);
}

function backend_session_registry_is_expired(?array $record, ?int $now = null): bool
{
    if (!is_array($record)) {
        return true;
    }

    $expiresAt = (int)($record['expires_at'] ?? 0);
    $now ??= time();
    return $expiresAt > 0 && $expiresAt < $now;
}

function backend_session_registry_cleanup_expired_locked(): void
{
    $now = time();
    $dirs = [backend_runtime_locks_dir(), backend_runtime_undos_dir()];
    foreach ($dirs as $dir) {
        $files = @glob($dir . '/*.json') ?: [];
        foreach ($files as $file) {
            $record = backend_session_registry_read_json_file($file);
            if (backend_session_registry_is_expired($record, $now)) {
                @unlink($file);
            }
        }
    }
}

function backend_session_registry_with_lock(callable $fn)
{
    return backend_with_named_lock('session_registry', function () use ($fn) {
        backend_session_registry_cleanup_expired_locked();
        return $fn();
    });
}

function backend_session_registry_get_lock(string $resourceType, string $resourceId): ?array
{
    return backend_session_registry_with_lock(function () use ($resourceType, $resourceId) {
        $path = backend_session_registry_record_path($resourceType, $resourceId);
        $record = backend_session_registry_read_json_file($path);
        if (backend_session_registry_is_expired($record)) {
            @unlink($path);
            return null;
        }
        return $record;
    });
}

function backend_session_registry_acquire_lock(
    string $resourceType,
    string $resourceId,
    string $sessionId,
    int $ttlSec,
    string $mode = 'edit',
    array $meta = []
): array {
    $ttlSec = max(5, $ttlSec);
    return backend_session_registry_with_lock(function () use ($resourceType, $resourceId, $sessionId, $ttlSec, $mode, $meta) {
        $path = backend_session_registry_record_path($resourceType, $resourceId);
        $existing = backend_session_registry_read_json_file($path);
        $now = time();

        if (!backend_session_registry_is_expired($existing, $now)) {
            $existingSession = trim((string)($existing['session_id'] ?? ''));
            if ($existingSession !== '' && $existingSession !== $sessionId) {
                return [
                    'acquired' => false,
                    'record' => $existing,
                ];
            }
        }

        $record = [
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'session_id' => $sessionId,
            'operator_ip' => backend_operator_ip(),
            'mode' => $mode,
            'created_at' => isset($existing['created_at']) ? (int)$existing['created_at'] : $now,
            'expires_at' => $now + $ttlSec,
            'meta' => $meta,
        ];
        backend_session_registry_write_json_file($path, $record);

        return [
            'acquired' => true,
            'record' => $record,
        ];
    });
}

function backend_session_registry_renew_lock(
    string $resourceType,
    string $resourceId,
    string $sessionId,
    int $ttlSec,
    array $meta = []
): array {
    $ttlSec = max(5, $ttlSec);
    return backend_session_registry_with_lock(function () use ($resourceType, $resourceId, $sessionId, $ttlSec, $meta) {
        $path = backend_session_registry_record_path($resourceType, $resourceId);
        $existing = backend_session_registry_read_json_file($path);
        $now = time();

        if (backend_session_registry_is_expired($existing, $now)) {
            @unlink($path);
            return [
                'renewed' => false,
                'reason' => 'expired',
                'record' => null,
            ];
        }

        $existingSession = trim((string)($existing['session_id'] ?? ''));
        if ($existingSession === '' || $existingSession !== $sessionId) {
            return [
                'renewed' => false,
                'reason' => 'owned_by_other_session',
                'record' => $existing,
            ];
        }

        $record = $existing;
        $record['expires_at'] = $now + $ttlSec;
        if (!empty($meta)) {
            $record['meta'] = array_merge(is_array($record['meta'] ?? null) ? $record['meta'] : [], $meta);
        }

        backend_session_registry_write_json_file($path, $record);
        return [
            'renewed' => true,
            'record' => $record,
        ];
    });
}

function backend_session_registry_release_lock(
    string $resourceType,
    string $resourceId,
    string $sessionId
): array {
    return backend_session_registry_with_lock(function () use ($resourceType, $resourceId, $sessionId) {
        $path = backend_session_registry_record_path($resourceType, $resourceId);
        $existing = backend_session_registry_read_json_file($path);
        if (!is_array($existing)) {
            return [
                'released' => true,
                'record' => null,
            ];
        }

        $existingSession = trim((string)($existing['session_id'] ?? ''));
        if ($existingSession !== '' && $existingSession !== $sessionId) {
            return [
                'released' => false,
                'reason' => 'owned_by_other_session',
                'record' => $existing,
            ];
        }

        @unlink($path);
        return [
            'released' => true,
            'record' => $existing,
        ];
    });
}

function backend_session_registry_list_active_locks(array $resourceTypes = []): array
{
    return backend_session_registry_with_lock(function () use ($resourceTypes) {
        $paths = @glob(backend_runtime_locks_dir() . '/*.json') ?: [];
        $out = [];
        foreach ($paths as $path) {
            $record = backend_session_registry_read_json_file($path);
            if (backend_session_registry_is_expired($record)) {
                @unlink($path);
                continue;
            }

            $type = (string)($record['resource_type'] ?? '');
            if (!empty($resourceTypes) && !in_array($type, $resourceTypes, true)) {
                continue;
            }
            $out[] = $record;
        }
        return $out;
    });
}

function backend_session_registry_first_active_lock(array $resourceTypes = [], ?callable $predicate = null): ?array
{
    $locks = backend_session_registry_list_active_locks($resourceTypes);
    foreach ($locks as $record) {
        if ($predicate && !$predicate($record)) {
            continue;
        }
        return $record;
    }
    return null;
}

function backend_restore_blocking_lock_message(array $record): string
{
    $type = (string)($record['resource_type'] ?? '');
    $resourceId = (string)($record['resource_id'] ?? '');

    if ($type === 'sdaq_edit') {
        $parts = explode(':', $resourceId, 2);
        $bus = strtoupper($parts[0] ?? '');
        $addr = $parts[1] ?? '';
        if ($bus !== '' && $addr !== '') {
            return sprintf(
                'Restore unavailable: SDAQ %s addr %s is currently locked for calibration editing by another session.',
                strtolower($bus),
                $addr
            );
        }
        return 'Restore unavailable: an SDAQ device is currently locked for calibration editing by another session.';
    }

    if ($type === 'device_config') {
        return 'Restore unavailable: another session is editing manual device configuration.';
    }

    if ($type === 'channel_edit') {
        return 'Restore unavailable: another session is editing channels.';
    }

    return 'Restore unavailable: another session is holding a conflicting edit lock.';
}

function backend_require_session_token(string $error = 'Missing session token'): string
{
    $token = backend_session_token();
    if ($token === '') {
        throw new RuntimeException($error, 400);
    }
    return $token;
}
