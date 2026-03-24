<?php

require_once __DIR__ . '/paths.php';

function channel_fixture_enabled(): bool
{
    $raw = getenv('MORFEAS_CHANNEL_FIXTURE');
    if ($raw === false) {
        return false;
    }

    $v = strtolower(trim((string)$raw));
    return in_array($v, ['1', 'true', 'yes', 'on'], true);
}

function channel_fixture_path(): string
{
    return backend_env_file(
        'MORFEAS_CHANNEL_FIXTURE_PATH',
        '/tmp/morfeas_channel_fixture.json',
        dirname(__DIR__, 2)
    );
}

function channel_fixture_load(): array
{
    $path = channel_fixture_path();
    if (!is_file($path)) {
        return ['enabled' => false, 'rows' => [], 'search_pool' => []];
    }

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return ['enabled' => false, 'rows' => [], 'search_pool' => []];
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return ['enabled' => false, 'rows' => [], 'search_pool' => []];
    }

    return [
        'enabled' => !empty($json['enabled']),
        'rows' => is_array($json['rows'] ?? null) ? $json['rows'] : [],
        'search_pool' => is_array($json['search_pool'] ?? null) ? $json['search_pool'] : [],
        'updated_at' => $json['updated_at'] ?? null,
    ];
}

function channel_fixture_save(array $payload): array
{
    $data = [
        'enabled' => !empty($payload['enabled']),
        'rows' => is_array($payload['rows'] ?? null) ? $payload['rows'] : [],
        'search_pool' => is_array($payload['search_pool'] ?? null) ? $payload['search_pool'] : [],
        'updated_at' => date('c'),
    ];

    @file_put_contents(
        channel_fixture_path(),
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        LOCK_EX
    );

    return $data;
}

function channel_fixture_clear(): void
{
    $path = channel_fixture_path();
    if (is_file($path)) {
        @unlink($path);
    }
}

function channel_fixture_apply(array &$rows, array &$extras): void
{
    if (!channel_fixture_enabled()) {
        return;
    }

    $fx = channel_fixture_load();
    if (empty($fx['enabled'])) {
        return;
    }

    if (!empty($fx['rows']) && is_array($fx['rows'])) {
        $rowOverrides = [];
        foreach ($fx['rows'] as $iso => $override) {
            if (!is_array($override)) {
                continue;
            }
            $rowOverrides[strtoupper(trim((string)$iso))] = $override;
        }

        if (!empty($rowOverrides)) {
            foreach ($rows as &$row) {
                $iso = strtoupper(trim((string)($row['iso_channel'] ?? '')));
                if ($iso === '' || !isset($rowOverrides[$iso])) {
                    continue;
                }
                $ov = $rowOverrides[$iso];
                foreach ([
                    'status',
                    'dev_type',
                    'dev_type_display',
                    'dev_type_stale',
                    'dev_type_known',
                    'display_anchor',
                    'anchor',
                ] as $field) {
                    if (array_key_exists($field, $ov)) {
                        $row[$field] = $ov[$field];
                    }
                }
            }
            unset($row);
        }
    }

    if (!empty($fx['search_pool']) && is_array($fx['search_pool'])) {
        $extras['search_pool'] = $fx['search_pool'];
    }
}
