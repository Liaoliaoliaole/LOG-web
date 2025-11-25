<?php
// backend/api_iso.php  //li@vmvm:~/LOG_project/LOG-web/Morfeas_WEB_v2$ php -S 0.0.0.0:8080 -t .
//http://localhost:8080/LOG_WEB_v2/index.html

require __DIR__ . '/core/iso_channel_config.php';
require __DIR__ . '/core/logstat_sdaq.php';
require __DIR__ . '/core/logstat_iobox.php';
require __DIR__ . '/core/logstat_mti.php';
require __DIR__ . '/core/logstat_nox.php';

header('Content-Type: application/json; charset=utf-8');

// === 1) 配置 XML 路径 ===============================================

// 如果你的 mock XML 在 backend/config_sandbox/OPC_UA_Config.mock.xml：
//   Morfeas_WEB_v2/
//     backend/
//       api_iso.php  (这里)
//       config_sandbox/OPC_UA_Config.mock.xml
//
// 就用这一行；否则改成真实路径，比如 __DIR__.'/OPC_UA_Config.xml'
$xmlPath = __DIR__ . '/config_sandbox/OPC_UA_Config.mock.xml';

// === 2) logstat mock 路径 ===========================================
$sdaqLogPath =  __DIR__ . '/config_sandbox/logstat_SDAQs_can1.json';
$ioboxLogFiles = [__DIR__ . '/config_sandbox/logstat_IOBOX_IOBOX_A.json'];
$mtiLogFiles   = [__DIR__ . '/config_sandbox/logstat_MTI_MTI_A.json'];
$noxLogPath    = __DIR__ . '/config_sandbox/logstat_NOX_can2.json';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// === 小工具：读 JSON body ===========================================

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON body'], JSON_PRETTY_PRINT);
        exit;
    }
    return $data;
}

/**
 * XML + 各类 logstat 合并，生成前端需要的行
 *
 * 返回每行都包含：
 *  - iso_channel, interface_type, anchor, description, min, max, unit,
 *  - cal_date, cal_period（用于 Next Calibration）
 *  - status, meas         （用于 Status / Color / Value）
 */
function build_rows_with_logstat(
    string $xmlPath,
    string $sdaqLogPath,
    array  $ioboxLogFiles,
    array  $mtiLogFiles,
    string $noxLogPath
): array {
    $channels = iso_load_channels($xmlPath);

    $sdaqMap  = sdaq_load_anchor_map($sdaqLogPath);
    $ioboxMap = iobox_load_anchor_map($ioboxLogFiles);
    $mtiMap   = mti_load_anchor_map($mtiLogFiles);
    $noxMap   = nox_load_anchor_map($noxLogPath);

    $rows = [];

    foreach ($channels as $ch) {
        $row    = $ch;
        $anchor = $ch['anchor'] ?? '';
        $type   = strtoupper($ch['interface_type'] ?? '');

        $status = 'OFF-Line';
        $meas   = '—';

        if ($type === 'SDAQ') {
            if ($anchor && isset($sdaqMap[$anchor])) {
                $ls = $sdaqMap[$anchor];
                $status = $ls['status'] ?? 'Unknown';

                if (!empty($ls['is_meas_valid']) && $ls['meas_value'] !== null) {
                    $value = $ls['meas_value'];
                    $meas  = sprintf('%.3f', $value);
                    if (!empty($ls['meas_unit'])) {
                        $meas .= ' ' . $ls['meas_unit'];
                    }
                }
            }

        } elseif ($type === 'IOBOX') {
            if ($anchor && isset($ioboxMap[$anchor])) {
                $ls = $ioboxMap[$anchor];
                $status = $ls['status'] ?? 'Unknown';

                if (!empty($ls['is_meas_valid']) && $ls['meas_value'] !== null) {
                    $value = $ls['meas_value'];
                    $meas  = sprintf('%.3f', $value);
                    if (!empty($ls['meas_unit'])) {
                        $meas .= ' ' . $ls['meas_unit'];
                    }
                }
            }

        } elseif ($type === 'MTI') {
            if ($anchor && isset($mtiMap[$anchor])) {
                $ls = $mtiMap[$anchor];
                $status = $ls['status'] ?? 'Unknown';

                if (!empty($ls['is_meas_valid']) && $ls['meas_value'] !== null) {
                    $value = $ls['meas_value'];
                    $meas  = sprintf('%.3f', $value);
                    if (!empty($ls['meas_unit'])) {
                        $meas .= ' ' . $ls['meas_unit'];
                    }
                }
            }

        } elseif ($type === 'NOX' || $type === 'NOx') {
            if ($anchor && isset($noxMap[$anchor])) {
                $ls = $noxMap[$anchor];
                $status = $ls['status'] ?? 'Unknown';

                if (!empty($ls['is_meas_valid']) && $ls['meas_value'] !== null) {
                    $value = $ls['meas_value'];
                    $meas  = sprintf('%.3f', $value);
                    if (!empty($ls['meas_unit'])) {
                        $meas .= ' ' . $ls['meas_unit'];
                    }
                }
            }

        } else {
            // 其他类型暂时默认 Okay，以后再扩展
            $status = 'Okay';
        }

        $row['status'] = $status;
        $row['meas']   = $meas;

        $rows[] = $row;
    }

    return $rows;
}

try {
    switch ($method) {
        case 'GET':
            $iso = $_GET['iso'] ?? null;

            $rows = build_rows_with_logstat(
                $xmlPath,
                $sdaqLogPath,
                $ioboxLogFiles,
                $mtiLogFiles,
                $noxLogPath
            );

            if ($iso === null) {
                echo json_encode(['ok' => true, 'data' => $rows], JSON_PRETTY_PRINT);
                break;
            }

            $found = null;
            foreach ($rows as $r) {
                if (($r['iso_channel'] ?? '') === $iso) {
                    $found = $r;
                    break;
                }
            }

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
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
