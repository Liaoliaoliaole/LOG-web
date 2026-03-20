<?php
require __DIR__ . '/core/paths.php';
require __DIR__ . '/core/request.php';
require __DIR__ . '/core/system_info.php';
require __DIR__ . '/repositories/iso_repository.php';
require __DIR__ . '/repositories/logstat_repository.php';
require __DIR__ . '/services/channel_service.php';

header('Content-Type: application/json; charset=utf-8');

$isoStandardDir = backend_iso_standard_dir();
$ramdisk = backend_ramdisk_dir();
$xmlPath = backend_opcua_config_path();

function channels_fail(string $error, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $error], JSON_PRETTY_PRINT);
    exit;
}

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
            $extras = [];

            $rows = channel_build_rows_with_logstat(
                $xmlPath,
                $sdaqLogFiles,
                $ioboxLogFiles,
                $mtiLogFiles,
                $noxLogFiles,
                $sdaqDeviceTypes,
                $extras
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
                channels_fail("ISO_CHANNEL not found: $iso", 404);
            } else {
                echo json_encode(['ok' => true, 'data' => $found], JSON_PRETTY_PRINT);
            }
            break;

        case 'POST':
            $data = read_json_body();
            foreach (['iso_channel', 'interface_type', 'anchor'] as $field) {
                if (empty($data[$field])) {
                    channels_fail("Missing field: $field", 400);
                }
            }
            iso_add_channel($xmlPath, $data);
            echo json_encode(['ok' => true], JSON_PRETTY_PRINT);
            break;

        case 'PATCH':
            $iso = $_GET['iso'] ?? null;
            if ($iso === null || $iso === '') {
                channels_fail('Missing ?iso=... in query', 400);
            }
            $data = read_json_body();
            if (!$data) {
                channels_fail('Empty PATCH body', 400);
            }
            iso_update_channel($xmlPath, $iso, $data);
            echo json_encode(['ok' => true], JSON_PRETTY_PRINT);
            break;

        case 'DELETE':
            $iso = $_GET['iso'] ?? null;
            if ($iso === null || $iso === '') {
                channels_fail('Missing ?iso=... in query', 400);
            }
            iso_delete_channel($xmlPath, $iso);
            echo json_encode(['ok' => true], JSON_PRETTY_PRINT);
            break;

        default:
            header('Allow: GET, POST, PATCH, DELETE');
            channels_fail('Method not allowed', 405);
    }
} catch (Throwable $e) {
    api_fail_response('Failed to process channel request', 500, 'api_channels', $e);
}
