<?php
// backend/api_channels.php  //li@vmvm:~/LOG_project/LOG-web/Morfeas_WEB_v2$ php -S 0.0.0.0:8080 -t .
//http://localhost:8080/LOG_WEB_v2/index.html

require __DIR__ . '/core/opcua_config.php';
require __DIR__ . '/core/logstat_sdaq.php';
require __DIR__ . '/core/logstat_iobox.php';
require __DIR__ . '/core/logstat_mti.php';
require __DIR__ . '/core/logstat_nox.php';
require __DIR__ . '/core/system_info.php';

header('Content-Type: application/json; charset=utf-8');

// === 1) 使用 sandbox 的 OPC UA mock XML（保持与老版一致） ==============

$sandboxDir = __DIR__ . '/config_sandbox/';

function collect_iso_files(string $sandboxDir): array
{
    $paths = [];
    $locations = [
        ['pi', '/home/morfeas/configuration/iso_standards/', '*.xml'],
        ['sandbox', $sandboxDir . 'iso_standards/', '*.xml'],
    ];

    foreach ($locations as [$source, $dir, $pattern]) {
        $dir = rtrim($dir, '/') . '/';
        foreach (glob($dir . $pattern) ?: [] as $path) {
            $paths[$path] = $source;
        }
    }

    $items = [];
    $index = 0;
    foreach ($paths as $path => $source) {
        $items[] = [
            'id'         => base64_encode($path),
            'name'       => basename($path),
            'path'       => $path,
            'source'     => $source,
            'is_default' => $index === 0,
        ];
        $index++;
    }

    return $items;
}

// 前端期望总是读取 sandbox 的 mock 配置；如需真实路径请后续再扩展
$xmlPath = $sandboxDir.'OPC_UA_Config.mock.xml';

// === ISOstandard.xml（legacy: /home/pi/Morfeas_config/ISOstandard.xml） ===
if (isset($_GET['include']) && $_GET['include'] === 'iso_standard_list') {
    $items = collect_iso_files($sandboxDir);

    echo json_encode(['files' => $items], JSON_PRETTY_PRINT);
    exit;
}

if (isset($_GET['include']) && $_GET['include'] === 'iso_standard_list') {
    $items = collect_iso_files($sandboxDir);
    $target = $_GET['file'] ?? null;

    $pathLookup = [];
    foreach ($items as $item) {
        $pathLookup[$item['id']] = $item['path'];
    }

    $paths = array_values($pathLookup);
    if ($target && isset($pathLookup[$target])) {
        $paths = [$pathLookup[$target]];
    }

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

// === 2) logstat 路径（真实 ramdisk -> sandbox） =====================

$ramdisk = '/mnt/ramdisk/';
$collectLogstatPaths = function (string $pattern) use ($ramdisk, $sandboxDir): array {
    $sandbox = glob($sandboxDir . $pattern) ?: [];
    $ram     = glob($ramdisk . $pattern) ?: [];

    // sandbox 先，ramdisk 后，确保实时数据覆盖样本
    return array_merge($sandbox, $ram);
};

$sdaqLogFiles      = $collectLogstatPaths('logstat_SDAQ*.json');
$noxLogFiles       = $collectLogstatPaths('logstat_NOX*.json');
$ioboxLogFiles     = $collectLogstatPaths('logstat_IOBOX*.json');
$mtiLogFiles       = $collectLogstatPaths('logstat_MTI*.json');
$sdaqDeviceTypes   = sdaq_collect_device_types($sdaqLogFiles);

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
    array  $sdaqLogFiles,
    array  $ioboxLogFiles,
    array  $mtiLogFiles,
    array  $noxLogFiles,
    array  $sdaqDeviceTypes,
    ?array &$extras = null
): array {
    $channels = iso_load_channels($xmlPath);

    $formatSdaqDisplayAnchor = static function (?string $anchor): string {
        $anchor = trim((string)$anchor);
        if ($anchor === '') return '';

        if (preg_match('/^(CAN\w+)\.ADDR:(\d+)\.CH:?([\d]+)/i', $anchor, $m)) {
            return sprintf('%s.ADDR:%02d.CH:%02d', strtoupper($m[1]), (int)$m[2], (int)$m[3]);
        }
        if (preg_match('/^(CAN\w+)\.?(\d+)\.CH:?([\d]+)/i', $anchor, $m)) {
            return sprintf('%s.ADDR:%02d.CH:%02d', strtoupper($m[1]), (int)$m[2], (int)$m[3]);
        }

        return strtoupper($anchor);
    };

    $formatNetworkAnchor = static function (?string $anchor, array $ipv4Map): string {
        $anchor = trim((string)$anchor);
        if ($anchor === '') return '';

        $parts = explode('.', $anchor, 2);
        if (count($parts) !== 2) {
            return strtoupper($anchor);
        }

        [$deviceId, $rest] = $parts;
        $deviceKey = (string)$deviceId;

        $prefix = $ipv4Map[$deviceKey] ?? $deviceId;
        return $prefix . '.' . strtoupper($rest);
    };

    $sdaqAnchorsFromXml = [];
    foreach ($channels as $ch) {
        if (strcasecmp($ch['interface_type'] ?? '', 'SDAQ') === 0 && !empty($ch['anchor'])) {
            $sdaqAnchorsFromXml[strtoupper($ch['anchor'])] = true;
        }
    }

    $sdaqMap = [];
    $sdaqChannels = [];

    foreach ($sdaqLogFiles as $path) {
        $dataset = sdaq_load_anchor_map($path, $sdaqAnchorsFromXml);
        if (is_array($dataset)) {
            $anchors = $dataset['anchors'] ?? [];
            foreach ($anchors as $anchor => $entry) {
                $sdaqMap[$anchor] = $entry;
            }

            if (!empty($dataset['channels']) && is_array($dataset['channels'])) {
                $sdaqChannels = array_merge($sdaqChannels, $dataset['channels']);
            }
        }
    }

    $ioboxData = iobox_load_anchor_map($ioboxLogFiles);
    $ioboxMap  = $ioboxData['anchors'] ?? [];
    $ioboxConn = $ioboxData['connections'] ?? [];
    $ioboxIPv4 = $ioboxData['ipv4'] ?? [];

    $mtiData = mti_load_anchor_map($mtiLogFiles);
    $mtiMap  = $mtiData['anchors'] ?? [];
    $mtiConn = $mtiData['connections'] ?? [];
    $mtiIPv4 = $mtiData['ipv4'] ?? [];

    $noxMap = [];
    foreach ($noxLogFiles as $path) {
        $newMap = nox_load_anchor_map($path);
        if (is_array($newMap)) {
            foreach ($newMap as $anchor => $entry) {
                $noxMap[$anchor] = $entry;
            }
        }
    }

    $rows = [];

    // 真正来自 XML 的 anchor（用于标记 linked_in_xml）
    $anchorsInXmlUpper = [];
    // 所有已插入到 $rows 的 anchor（用于去重，不影响 linked_in_xml 标记）
    $seenAnchorsUpper = [];
    $idx = 0;

    $searchPool = [
        'SDAQ'  => [],
        'IOBOX' => [],
        'MTI'   => [],
        'NOX'   => [],
    ];

    foreach ($channels as $ch) {
        $row    = $ch;
        $anchor = $ch['anchor'] ?? '';
        $type   = strtoupper($ch['interface_type'] ?? '');
        $row['dev_type'] = $type;
        $row['display_anchor'] = $anchor;
        $row['_order'] = $idx++; // 记录 XML 中出现的顺序
        $busAddrKey = null;

        if ($type === 'SDAQ' && $anchor) {
            $anchorUc = strtoupper($anchor);

            if (preg_match('/^(CAN\w+\.ADDR:\d{2})/i', $anchorUc, $m)) {
                $busAddrKey = strtoupper($m[1]);
            } elseif (preg_match('/^(CAN\w+)\.?(\d{1,2})\.CH/',$anchorUc,$m)) {
                $busAddrKey = sprintf('%s.ADDR:%02d', $m[1], (int)$m[2]);
            }

            if ($busAddrKey && isset($sdaqDeviceTypes[$busAddrKey])) {
                $row['dev_type'] = $sdaqDeviceTypes[$busAddrKey];
            }
        }

        $status = 'OFF-Line';
        $meas   = '—';

        if ($type === 'SDAQ') {
            $row['display_anchor'] = $formatSdaqDisplayAnchor($anchor);
            if ($anchor && isset($sdaqMap[$anchor])) {
                $ls = $sdaqMap[$anchor];
                $explain = $ls['error_explanation'] ?? null;
                $status = $ls['status'] ?? 'Unknown';
                if (($explain && strcasecmp($explain, 'Unlinked') === 0) || strcasecmp($status, 'Unlinked') === 0) {
                    $status = 'Unknown';
                }

                if (!empty($ls['device_user_identifier'])) {
                    $row['dev_type'] = $ls['device_user_identifier'];
                }

                if (!empty($ls['cal_date'])) {
                    $row['cal_date'] = $ls['cal_date'];
                }
                if (!empty($ls['cal_period'])) {
                    $row['cal_period'] = $ls['cal_period'];
                }


                if (!empty($ls['is_meas_valid']) && $ls['meas_value'] !== null) {
                    $value = $ls['meas_value'];
                    $meas  = sprintf('%.3f', $value);
                    if (!empty($ls['meas_unit'])) {
                        $meas .= ' ' . $ls['meas_unit'];
                    }
                }
            } elseif ($busAddrKey && isset($sdaqDeviceTypes[$busAddrKey])) {
                // 无对应 anchor，但同一台 SDAQ 的类型仍然可用于 Type 列
                $row['dev_type'] = $sdaqDeviceTypes[$busAddrKey];
            }

        } elseif ($type === 'IOBOX') {
            $row['display_anchor'] = $formatNetworkAnchor($anchor, $ioboxIPv4);

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

            if ($status === 'OFF-Line' && $anchor) {
                $deviceId = explode('.', $anchor, 2)[0] ?? null;
                if ($deviceId !== null && isset($ioboxConn[$deviceId]) && strcasecmp($ioboxConn[$deviceId], 'Okay') === 0) {
                    $status = 'Disconnected';
                }
            }

        } elseif ($type === 'MTI') {
            $row['display_anchor'] = $formatNetworkAnchor($anchor, $mtiIPv4);

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

            if ($status === 'OFF-Line' && $anchor) {
                $deviceId = explode('.', $anchor, 2)[0] ?? null;
                if ($deviceId !== null && isset($mtiConn[$deviceId]) && strcasecmp($mtiConn[$deviceId], 'Okay') === 0) {
                    $status = 'Disconnected';
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
        if ($anchor) {
            $upper = strtoupper($anchor);
            $anchorsInXmlUpper[$upper] = true;
            $seenAnchorsUpper[$upper] = true;
        }
    }

    // 搜索池：包含 logstat 探测到的通道，标注是否已在 XML 中声明
    foreach ($sdaqChannels as $chMeta) {
        $anchor = $chMeta['connection_anchor'] ?? ($chMeta['aliases'][0] ?? null);
        $display = $chMeta['display_anchor'] ?? $anchor;
        if (!$anchor || !$display) {
            continue;
        }

        $upper = strtoupper($anchor);
        $searchPool['SDAQ'][] = [
            'anchor'          => $anchor,
            'display_anchor'  => $formatSdaqDisplayAnchor($display),
            'link_state'      => $chMeta['link_state'] ?? 'Linked',
            'has_sensor'      => !empty($chMeta['has_sensor']),
            'registration'    => $chMeta['registration'] ?? null,
            'unit'            => $chMeta['entry']['meas_unit'] ?? null,
            'device_type'     => $chMeta['entry']['device_user_identifier'] ?? null,
            'status'          => $chMeta['entry']['status'] ?? null,
            'is_meas_valid'   => $chMeta['entry']['is_meas_valid'] ?? null,
            'meas_value'      => $chMeta['entry']['meas_value'] ?? null,
            'linked_in_xml'   => isset($anchorsInXmlUpper[$upper]),
        ];
    }

    foreach ($ioboxMap as $anchor => $entry) {
        $upper = strtoupper($anchor);
        $searchPool['IOBOX'][] = [
            'anchor'         => $anchor,
            'display_anchor' => $formatNetworkAnchor($anchor, $ioboxIPv4),
            'link_state'     => 'Unlinked',
            'status'         => $entry['status'] ?? null,
            'is_meas_valid'  => $entry['is_meas_valid'] ?? null,
            'meas_value'     => $entry['meas_value'] ?? null,
            'meas_unit'      => $entry['meas_unit'] ?? null,
            'linked_in_xml'  => isset($anchorsInXmlUpper[$upper]),
        ];
    }

    foreach ($mtiMap as $anchor => $entry) {
        $upper = strtoupper($anchor);
        $searchPool['MTI'][] = [
            'anchor'         => $anchor,
            'display_anchor' => $formatNetworkAnchor($anchor, $mtiIPv4),
            'status'         => $entry['status'] ?? null,
            'is_meas_valid'  => $entry['is_meas_valid'] ?? null,
            'meas_value'     => $entry['meas_value'] ?? null,
            'meas_unit'      => $entry['meas_unit'] ?? null,
            'linked_in_xml'  => isset($anchorsInXmlUpper[$upper]),
        ];
    }

    foreach ($noxMap as $anchor => $entry) {
        $upper = strtoupper($anchor);
        $searchPool['NOX'][] = [
            'anchor'         => $anchor,
            'display_anchor' => $anchor,
            'status'         => $entry['status'] ?? null,
            'is_meas_valid'  => $entry['is_meas_valid'] ?? null,
            'meas_value'     => $entry['meas_value'] ?? null,
            'meas_unit'      => $entry['meas_unit'] ?? null,
            'linked_in_xml'  => isset($anchorsInXmlUpper[$upper]),
        ];
    }

    // 仿照旧版 Morfeas WEB：先显示所有 SDAQ，再依次显示其他类型
    $priority = [
        'SDAQ'  => 0,
        'NOX'   => 1,
        'NOx'   => 1,
        'MTI'   => 2,
        'IOBOX' => 3,
    ];

    usort($rows, static function ($a, $b) use ($priority) {
        $pa = $priority[$a['interface_type'] ?? $a['dev_type'] ?? ''] ?? 99;
        $pb = $priority[$b['interface_type'] ?? $b['dev_type'] ?? ''] ?? 99;

        if ($pa === $pb) {
            return ($a['_order'] ?? 0) <=> ($b['_order'] ?? 0);
        }
        return $pa <=> $pb;
    });

    // 清理排序辅助字段
    foreach ($rows as &$r) {
        unset($r['_order']);
    }

    if (is_array($extras)) {
        $extras = [
            'search_pool' => $searchPool,
        ];
    }

    return $rows;
}

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

            $rows = build_rows_with_logstat(
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
    // Surface parsing/IO errors to the caller without forcing a 500 that masks the message.
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
