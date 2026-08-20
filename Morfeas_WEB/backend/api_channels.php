<?php
require __DIR__ . '/core/paths.php';
require __DIR__ . '/core/request.php';
require __DIR__ . '/core/system_info.php';
require __DIR__ . '/repositories/iso_repository.php';
require __DIR__ . '/repositories/logstat_repository.php';
require __DIR__ . '/services/channel_service.php';
require __DIR__ . '/services/channel_restore_service.php';

header('Content-Type: application/json; charset=utf-8');

$isoStandardDir = backend_iso_standard_dir();
$ramdisk = backend_ramdisk_dir();
$xmlPath = backend_opcua_config_path();
$logConfigPath = backend_log_config_path();

// ChannelRuleException lives in channel_service.php alongside the TC16
// business logic that throws it, so that logic can be unit-tested without
// pulling in this file's top-level HTTP request dispatch.

function channels_fail(string $error, int $status = 400, ?string $code = null): void
{
    http_response_code($status);
    $payload = ['ok' => false, 'error' => $error];
    if ($code !== null && $code !== '') {
        $payload['code'] = $code;
    }
    echo json_encode($payload, JSON_PRETTY_PRINT);
    exit;
}

function channels_fail_from_runtime(RuntimeException $e): void
{
    if ($e instanceof ChannelConfigException || $e instanceof ChannelRuleException) {
        channels_fail($e->getMessage(), $e->status(), $e->apiCode());
    }

    $message = $e->getMessage();
    $lower = strtolower($message);

    if (str_contains($lower, 'already exists')) {
        channels_fail($message, 409, 'channel_conflict');
    }

    if (str_contains($lower, 'not found')) {
        channels_fail($message, 404, 'channel_not_found');
    }

    channels_fail($message, 400, 'channel_config_error');
}

// TC16 Replace All's business logic (source resolution, target
// lookup/validation, canonical anchor generation, atomic write) lives in
// channel_service.php's channel_replace_tc16_from_pool(), alongside every
// other write path.

if (isset($_GET['include']) && $_GET['include'] === 'iso_standard_upload') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        channels_fail('Method not allowed', 405);
    }

    if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        channels_fail('Missing upload file', 400);
    }

    $targetDir = iso_resolve_upload_dir($isoStandardDir);
    try {
        iso_ensure_upload_dir($targetDir);
    } catch (Throwable $e) {
        api_fail_response('Failed to prepare upload directory', 500, 'api_channels.upload_dir', $e);
    }

    $originalName = iso_sanitize_filename($_FILES['file']['name'] ?? 'ISOstandard.xml');
    try {
        $filename = iso_unique_filename($targetDir, $originalName);
    } catch (Throwable $e) {
        api_fail_response('Failed to allocate upload file name', 500, 'api_channels.upload_name', $e);
    }

    $dest = $targetDir . $filename;

    if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
        channels_fail('Failed to save uploaded XML', 500);
    }

    echo json_encode([
        'ok' => true,
        'path' => $dest,
        'name' => $filename,
        'renamed' => $filename !== $originalName,
        'original_name' => $originalName,
    ], JSON_PRETTY_PRINT);
    exit;
}

if (isset($_GET['include']) && $_GET['include'] === 'iso_standard_list') {
    $items = iso_collect_files($isoStandardDir);

    echo json_encode(['ok' => true, 'files' => $items], JSON_PRETTY_PRINT);
    exit;
}

if (isset($_GET['include']) && $_GET['include'] === 'iso_standard') {
    $items = iso_collect_files($isoStandardDir);
    $target = $_GET['file'] ?? null;

    $paths = iso_find_file_path($items, $target);
    foreach ($paths as $path) {
        if (is_file($path)) {
            $xml = file_get_contents($path);
            if ($xml !== false) {
                header_remove('Content-Type');
                header('Content-Type: application/xml; charset=utf-8');
                echo $xml;
                exit;
            }
        }
    }

    channels_fail('ISOstandard.xml not found', 404);
}

if (isset($_GET['include']) && $_GET['include'] === 'machine_info') {
    $mac    = primary_mac_address();
    $canMap = system_can_bitrates();
    $ntp    = read_timesyncd_ntp_server() ?? '—';

    $payload = [
        'mac'  => $mac,
        'can'  => $canMap,
        'ntp'  => [
            'server' => $ntp,
            'readonly' => true,
        ],
    ];

    // Keep backward-compatible top-level fields and add standardized status.
    echo json_encode(['ok' => true, 'data' => $payload] + $payload, JSON_PRETTY_PRINT);
    exit;
}

$sdaqLogFiles      = logstat_collect_paths('logstat_SDAQ*.json', $ramdisk);
$noxLogFiles       = logstat_collect_paths('logstat_NOX*.json', $ramdisk);
$ioboxLogFiles     = logstat_collect_paths('logstat_IOBOX*.json', $ramdisk);
$mtiLogFiles       = logstat_collect_paths('logstat_MTI*.json', $ramdisk);
$sdaqDeviceTypes   = sdaq_collect_device_types($sdaqLogFiles);

if (isset($_GET['include']) && $_GET['include'] === 'restore_preflight') {
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            channels_fail('Method not allowed', 405, 'restore_method_not_allowed');
        }

        $data = read_json_body();
        $fileContent = (string)($data['file_content'] ?? '');
        if (trim($fileContent) === '') {
            channels_fail('Missing file_content', 400, 'missing_field');
        }

        try {
            $result = restore_preflight($xmlPath, $logConfigPath, $fileContent);
        } catch (RuntimeException $e) {
            channels_fail_from_runtime($e);
        }

        echo json_encode(['ok' => true, 'data' => $result], JSON_PRETTY_PRINT);
        exit;
    } catch (Throwable $e) {
        api_fail_response('Failed to process channel request', 500, 'api_channels.restore_preflight', $e);
    }
}

if (isset($_GET['include']) && $_GET['include'] === 'restore_commit') {
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            channels_fail('Method not allowed', 405, 'restore_method_not_allowed');
        }

        $data = read_json_body();
        $fileContent = (string)($data['file_content'] ?? '');
        $digest = (string)($data['digest'] ?? '');
        if (trim($fileContent) === '' || $digest === '') {
            channels_fail('Missing file_content or digest', 400, 'missing_field');
        }

        try {
            $result = restore_commit($xmlPath, $logConfigPath, $fileContent, $digest);
        } catch (RuntimeException $e) {
            channels_fail_from_runtime($e);
        }

        echo json_encode(['ok' => true, 'data' => $result], JSON_PRETTY_PRINT);
        exit;
    } catch (Throwable $e) {
        api_fail_response('Failed to process channel request', 500, 'api_channels.restore_commit', $e);
    }
}

if (isset($_GET['include']) && $_GET['include'] === 'range_add') {
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            channels_fail('Method not allowed', 405, 'range_add_method_not_allowed');
        }

        $data = read_json_body();
        $items = is_array($data['items'] ?? null) ? $data['items'] : null;
        if ($items === null || count($items) === 0) {
            channels_fail('Missing items array', 400, 'missing_field');
        }

        try {
            channel_add_sdaq_range_from_pool(
                $xmlPath,
                $items,
                $sdaqLogFiles,
                $ioboxLogFiles,
                $mtiLogFiles,
                $noxLogFiles,
                $sdaqDeviceTypes
            );
        } catch (RuntimeException $e) {
            channels_fail_from_runtime($e);
        }

        echo json_encode(['ok' => true, 'data' => ['added_count' => count($items)]], JSON_PRETTY_PRINT);
        exit;
    } catch (ChannelRuleException $e) {
        channels_fail($e->getMessage(), $e->status(), $e->apiCode());
    } catch (Throwable $e) {
        api_fail_response('Failed to process channel request', 500, 'api_channels.range_add', $e);
    }
}

if (isset($_GET['include']) && $_GET['include'] === 'tc16_candidates') {
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            channels_fail('Method not allowed', 405, 'tc16_method_not_allowed');
        }

        $sourceIso = trim((string)($_GET['source_iso'] ?? ''));
        if ($sourceIso === '') {
            channels_fail('Missing source_iso', 400, 'missing_source_iso');
        }

        [$rows, $extras] = channel_collect_rows_and_extras(
            $xmlPath,
            $sdaqLogFiles,
            $ioboxLogFiles,
            $mtiLogFiles,
            $noxLogFiles,
            $sdaqDeviceTypes
        );

        $sourceGroup = channel_resolve_tc16_source_group($rows, $sourceIso);
        $devices = channel_collect_sdaq_capabilities($sdaqLogFiles);
        $targets = channel_collect_tc16_target_candidates($rows, $devices, $sourceGroup);

        echo json_encode([
            'ok' => true,
            'data' => [
                'source' => [
                    'iso_channel' => (string)($sourceGroup['source']['iso_channel'] ?? ''),
                    'mode' => (string)$sourceGroup['mode'],
                    'source_key' => (string)$sourceGroup['source_key'],
                    'channels' => channel_group_to_source_map($sourceGroup['channels'], (string)$sourceGroup['mode'], (string)$sourceGroup['source_key']),
                ],
                'targets' => $targets,
            ],
        ], JSON_PRETTY_PRINT);
        exit;
    } catch (ChannelRuleException $e) {
        channels_fail($e->getMessage(), $e->status(), $e->apiCode());
    } catch (Throwable $e) {
        api_fail_response('Failed to process channel request', 500, 'api_channels.tc16_candidates', $e);
    }
}

if (isset($_GET['include']) && $_GET['include'] === 'tc16_replace') {
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            channels_fail('Method not allowed', 405, 'tc16_method_not_allowed');
        }

        $data = read_json_body();
        $sourceIso = trim((string)($data['source_iso'] ?? ''));
        $targetKey = strtoupper(trim((string)($data['target_key'] ?? '')));

        if ($sourceIso === '') {
            channels_fail('Missing source_iso', 400, 'missing_source_iso');
        }
        if ($targetKey === '') {
            channels_fail('Missing target_key', 400, 'missing_target_key');
        }

        try {
            $result = channel_replace_tc16_from_pool(
                $xmlPath,
                $sourceIso,
                $targetKey,
                $sdaqLogFiles,
                $ioboxLogFiles,
                $mtiLogFiles,
                $noxLogFiles,
                $sdaqDeviceTypes
            );
        } catch (RuntimeException $e) {
            channels_fail_from_runtime($e);
        }

        echo json_encode([
            'ok' => true,
            'data' => $result,
        ], JSON_PRETTY_PRINT);
        exit;
    } catch (ChannelRuleException $e) {
        channels_fail($e->getMessage(), $e->status(), $e->apiCode());
    } catch (Throwable $e) {
        api_fail_response('Failed to process channel request', 500, 'api_channels.tc16_replace', $e);
    }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if (!is_file($xmlPath)) {
        http_response_code(500);
        echo json_encode([
            'ok'    => false,
            'error' => 'OPC UA config not found'
        ], JSON_PRETTY_PRINT);
        exit;
    }

    switch ($method) {
        case 'GET':
            $iso = $_GET['iso'] ?? null;

            $includeExtras = isset($_GET['include']) && $_GET['include'] === 'pool';
            [$rows, $extras] = channel_collect_rows_and_extras(
                $xmlPath,
                $sdaqLogFiles,
                $ioboxLogFiles,
                $mtiLogFiles,
                $noxLogFiles,
                $sdaqDeviceTypes
            );

            if ($iso === null) {
                $payload = ['ok' => true, 'data' => $rows];
                if ($includeExtras && !empty($extras)) {
                    $payload['extras'] = $extras;
                }
                echo json_encode($payload, JSON_PRETTY_PRINT);
                break;
            }

            $found = channel_find_by_iso($rows, $iso);

            if ($found === null) {
                channels_fail("ISO_CHANNEL not found: $iso", 404, 'iso_not_found');
            } else {
                echo json_encode(['ok' => true, 'data' => $found], JSON_PRETTY_PRINT);
            }
            break;

        case 'POST':
            $data = read_json_body();
            foreach (['iso_channel', 'interface_type', 'anchor'] as $field) {
                if (empty($data[$field])) {
                    channels_fail("Missing field: $field", 400, 'missing_field');
                }
            }
            try {
                // Add never trusts the client-submitted anchor directly, for any
                // interface family; it is always re-derived from a freshly
                // rebuilt, lock-protected candidate pool (Phase B1: Manual Add
                // is not a Web-only-SDAQ carve-out, it is closed for every
                // interface reachable through this endpoint).
                channel_add_channel_from_pool(
                    $xmlPath,
                    $logConfigPath,
                    $data,
                    $sdaqLogFiles,
                    $ioboxLogFiles,
                    $mtiLogFiles,
                    $noxLogFiles,
                    $sdaqDeviceTypes
                );
            } catch (RuntimeException $e) {
                channels_fail_from_runtime($e);
            }
            echo json_encode(['ok' => true], JSON_PRETTY_PRINT);
            break;

        case 'PATCH':
            $iso = $_GET['iso'] ?? null;
            if ($iso === null || $iso === '') {
                channels_fail('Missing ?iso=... in query', 400, 'missing_iso');
            }
            $data = read_json_body();
            if (!$data) {
                channels_fail('Empty PATCH body', 400, 'empty_body');
            }

            try {
                if (!empty($data['replace_mode'])) {
                    // Replace re-derives the target anchor server-side inside the
                    // XML lock, exactly like Add; the client-submitted anchor is
                    // only used to locate a candidate, never persisted as-is.
                    channel_replace_channel_from_pool(
                        $xmlPath,
                        $iso,
                        $data,
                        $sdaqLogFiles,
                        $ioboxLogFiles,
                        $mtiLogFiles,
                        $noxLogFiles,
                        $sdaqDeviceTypes
                    );
                } else {
                    iso_update_channel($xmlPath, $iso, $data);
                }
            } catch (RuntimeException $e) {
                channels_fail_from_runtime($e);
            }
            echo json_encode(['ok' => true], JSON_PRETTY_PRINT);
            break;

        case 'DELETE':
            $iso = $_GET['iso'] ?? null;
            if ($iso === null || $iso === '') {
                channels_fail('Missing ?iso=... in query', 400, 'missing_iso');
            }
            try {
                iso_delete_channel($xmlPath, $iso);
            } catch (RuntimeException $e) {
                channels_fail_from_runtime($e);
            }
            echo json_encode(['ok' => true], JSON_PRETTY_PRINT);
            break;

        default:
            header('Allow: GET, POST, PATCH, DELETE');
            channels_fail('Method not allowed', 405);
    }
} catch (ChannelRuleException $e) {
    channels_fail($e->getMessage(), $e->status(), $e->apiCode());
} catch (Throwable $e) {
    api_fail_response('Failed to process channel request', 500, 'api_channels', $e);
}
