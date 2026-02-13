<?php
// backend/api_channels.php  //li@vmvm:~/LOG_project/LOG-web/Morfeas_WEB_v2$ php -S 0.0.0.0:8080 -t .
//http://localhost:8080/LOG_WEB_v2/index.html

require __DIR__ . '/core/paths.php';
require __DIR__ . '/core/request.php';
require __DIR__ . '/core/system_info.php';
require __DIR__ . '/repositories/iso_repository.php';
require __DIR__ . '/repositories/logstat_repository.php';
require __DIR__ . '/services/channel_service.php';

header('Content-Type: application/json; charset=utf-8');

$sandboxDir = backend_sandbox_dir();
$isoStandardDir = backend_iso_standard_dir();
$ramdisk = backend_ramdisk_dir();
$xmlPath = backend_opcua_config_path();

if (isset($_GET['include']) && $_GET['include'] === 'iso_standard_upload') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_PRETTY_PRINT);
        exit;
    }

    if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing upload file'], JSON_PRETTY_PRINT);
        exit;
    }

    $targetDir = iso_resolve_upload_dir($sandboxDir, $isoStandardDir);
    $filename = iso_sanitize_filename($_FILES['file']['name'] ?? 'ISOstandard.xml');
    $dest = $targetDir . $filename;

    if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to save uploaded XML'], JSON_PRETTY_PRINT);
        exit;
    }

    echo json_encode(['ok' => true, 'path' => $dest], JSON_PRETTY_PRINT);
    exit;
}

if (isset($_GET['include']) && $_GET['include'] === 'iso_standard_list') {
    $items = iso_collect_files($sandboxDir, $isoStandardDir);

    echo json_encode(['files' => $items], JSON_PRETTY_PRINT);
    exit;
}

if (isset($_GET['include']) && $_GET['include'] === 'iso_standard') {
    $items = iso_collect_files($sandboxDir, $isoStandardDir);
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

    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'ISOstandard.xml not found'], JSON_PRETTY_PRINT);
    exit;
}

if (isset($_GET['include']) && $_GET['include'] === 'machine_info') {
    $mac    = primary_mac_address();
    $canMap = system_can_bitrates();

    echo json_encode([
        'mac'  => $mac,
        'can'  => $canMap,
    ], JSON_PRETTY_PRINT);
    exit;
}

$sdaqLogFiles      = logstat_collect_paths('logstat_SDAQ*.json', $sandboxDir, $ramdisk);
$noxLogFiles       = logstat_collect_paths('logstat_NOX*.json', $sandboxDir, $ramdisk);
$ioboxLogFiles     = logstat_collect_paths('logstat_IOBOX*.json', $sandboxDir, $ramdisk);
$mtiLogFiles       = logstat_collect_paths('logstat_MTI*.json', $sandboxDir, $ramdisk);
$sdaqDeviceTypes   = sdaq_collect_device_types($sdaqLogFiles);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if (!is_file($xmlPath)) {
        echo json_encode([
            'ok'    => false,
            'error' => "OPC UA config not found: $xmlPath"
        ], JSON_PRETTY_PRINT);
        return;
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
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => "ISO_CHANNEL not found: $iso"], JSON_PRETTY_PRINT);
            } else {
                echo json_encode(['ok' => true, 'data' => $found], JSON_PRETTY_PRINT);
            }
            break;

        case 'POST':
            $data = read_json_body();
            foreach (['iso_channel', 'interface_type', 'anchor'] as $field) {
                if (empty($data[$field])) {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => "Missing field: $field"], JSON_PRETTY_PRINT);
                    exit;
                }
            }
            iso_add_channel($xmlPath, $data);
            echo json_encode(['ok' => true], JSON_PRETTY_PRINT);
            break;

        case 'PATCH':
            $iso = $_GET['iso'] ?? null;
            if ($iso === null || $iso === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Missing ?iso=... in query'], JSON_PRETTY_PRINT);
                exit;
            }
            $data = read_json_body();
            if (!$data) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Empty PATCH body'], JSON_PRETTY_PRINT);
                exit;
            }
            iso_update_channel($xmlPath, $iso, $data);
            echo json_encode(['ok' => true], JSON_PRETTY_PRINT);
            break;

        case 'DELETE':
            $iso = $_GET['iso'] ?? null;
            if ($iso === null || $iso === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Missing ?iso=... in query'], JSON_PRETTY_PRINT);
                exit;
            }
            iso_delete_channel($xmlPath, $iso);
            echo json_encode(['ok' => true], JSON_PRETTY_PRINT);
            break;

        default:
            http_response_code(405);
            header('Allow: GET, POST, PATCH, DELETE');
            echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_PRETTY_PRINT);
    }
} catch (Throwable $e) {
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
