<?php

header('Content-Type: application/json; charset=utf-8');

$sandboxDir = __DIR__ . '/config_sandbox/';
$logConfig  = $sandboxDir . 'LOG_config.mock.xml';
$ramdisk    = '/mnt/ramdisk/';
$maxComponents = 16; // legacy limit (Morfeas_comp_amount_max)

// ------------------------------------------------------------
// Helpers
// ------------------------------------------------------------
function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid JSON body');
    }
    return $data;
}

function dom_text_or_empty(DOMElement $parent, string $tag): string
{
    $node = $parent->getElementsByTagName($tag)->item(0);
    return $node ? trim($node->nodeValue) : '';
}

function load_sdaq_devices(string $ramdisk, string $sandboxDir): array
{
    // 同时扫描 ramdisk 与 sandbox 的 logstat，ramdisk 数据优先覆盖 sandbox mock
    $paths = array_merge(
        glob($sandboxDir . 'logstat*.json') ?: [],
        glob($ramdisk . 'logstat*.json') ?: []
    );

    $devices = [];

    foreach ($paths as $path) {
        if (!is_file($path)) continue;

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') continue;

        $data = json_decode($raw, true);
        if (!is_array($data)) continue;

        // 只处理包含 SDAQs_data 的 logstat
        $list = $data['SDAQs_data'] ?? null;
        if (empty($list) || !is_array($list)) continue;

        $busRaw = $data['CANBus_interface'] ?? '';

        // 如果 JSON 里没写 CANBus_interface，则从文件名提取（示例：logstat_SDAQs_can0.json）
        if ($busRaw === '') {
            $basename = basename($path);
            if (preg_match('/logstat[^_]*_SDAQs_([^\.]+)\.json$/i', $basename, $m)) {
                $busRaw = $m[1];
            }
        }

        if ($busRaw === '') {
            $busRaw = 'can0';
        }
        $busKey = strtolower((string)$busRaw);
        $busDisplay = $busRaw === '' ? '-' : $busRaw;

        foreach ($list as $sdaq) {
            if (!is_array($sdaq)) continue;

            $addr = $sdaq['Address'] ?? null;
            if ($addr === null || $addr === '') continue;

            $statusArr = $sdaq['SDAQ_Status'] ?? [];
            $status = !empty($statusArr['Error']) ? 'Error' : 'Okay';

            $type = $sdaq['SDAQ_type'] ?? 'SDAQ';
            $name = (string)$addr;

            // 每台 SDAQ 只出现一次：按 bus+address 去重
            $id = sprintf('SDAQ:%s:%s', $busKey, $addr);
            $devices[$id] = [
                'id'     => $id,
                'type'   => $type,
                'bus'    => $busDisplay,
                'ip'     => '',
                'name'   => $name,
                'status' => $status,
                'origin' => 'auto',
            ];
        }
    }

    return array_values($devices);
}

function build_manual_id(string $type, string $bus, string $name, string $ip): string
{
    $type = strtoupper($type);
    $bus  = $bus !== '' ? $bus : '-';
    $label = $name !== '' ? $name : ($ip !== '' ? $ip : uniqid('DEV'));

    return implode(':', [$type, $bus, $label]);
}

function load_manual_devices_from_xml(string $xmlPath): array
{
    if (!is_file($xmlPath)) {
        return [];
    }

    $xml = simplexml_load_file($xmlPath);
    if ($xml === false) {
        throw new RuntimeException('Failed to parse LOG config XML');
    }

    $components = $xml->COMPONENTS ?? null;
    if ($components === null) {
        return [];
    }

    $out = [];
    foreach ($components->children() as $comp) {
        $tag = strtoupper($comp->getName());
        $disable = strtolower((string)$comp['Disable']) === 'true';
        $status = $disable ? 'Disabled' : 'Okay';

        switch ($tag) {
            case 'IOBOX_HANDLER':
            case 'MDAQ_HANDLER':
            case 'MTI_HANDLER':
                $type = str_replace('_HANDLER', '', $tag);
                $name = trim((string)$comp->DEV_NAME);
                $ip   = trim((string)$comp->IPv4_ADDR);
                $bus  = '-';
                $out[] = [
                    'id'     => build_manual_id($type, $bus, $name, $ip),
                    'type'   => $type,
                    'bus'    => $bus,
                    'ip'     => $ip,
                    'name'   => $name,
                    'status' => $status,
                    'origin' => 'xml',
                ];
                break;
            case 'NOX_HANDLER':
                $type = 'NOX';
                $bus  = trim((string)$comp->CANBUS_IF);
                $name = $bus;
                $ip   = '';
                $out[] = [
                    'id'     => build_manual_id($type, $bus, $name, $ip),
                    'type'   => $type,
                    'bus'    => $bus,
                    'ip'     => $ip,
                    'name'   => $name,
                    'status' => $status,
                    'origin' => 'xml',
                ];
                break;
            default:
                // 跳过 SDAQ 与其他配置节点
                break;
        }
    }

    return $out;
}

function count_components_in_xml(string $xmlPath): int
{
    if (!is_file($xmlPath)) {
        return 0;
    }

    $xml = simplexml_load_file($xmlPath);
    if ($xml === false) {
        return 0;
    }

    $components = $xml->COMPONENTS ?? null;
    if ($components === null) {
        return 0;
    }

    $count = 0;
    foreach ($components->children() as $_) {
        $count++;
    }

    return $count;
}

function append_device_to_xml(string $xmlPath, array $data): array
{
    if (!is_file($xmlPath)) {
        throw new RuntimeException("XML not found: $xmlPath");
    }

    $doc = new DOMDocument();
    $doc->preserveWhiteSpace = false;
    $doc->formatOutput = true;
    if (!$doc->load($xmlPath)) {
        throw new RuntimeException('Failed to parse LOG config XML');
    }

    $components = $doc->getElementsByTagName('COMPONENTS')->item(0);
    if ($components === null) {
        throw new RuntimeException('Invalid LOG config XML');
    }

    $type = strtoupper(str_replace('-', '', $data['type']));
    $busForId = ($type === 'NOX') ? $data['bus'] : '-';
    $id   = build_manual_id($type, $busForId, $data['name'], $data['ip']);

    // 防重复
    $existing = load_manual_devices_from_xml($xmlPath);
    foreach ($existing as $dev) {
        if ($dev['id'] === $id) {
            throw new RuntimeException("Device already exists: $id");
        }
    }

    switch ($type) {
        case 'IOBOX':
        case 'MDAQ':
        case 'MTI':
            $node = $doc->createElement($type . '_HANDLER');
            $node->setAttribute('Disable', 'false');
            $node->appendChild($doc->createElement('DEV_NAME', $data['name']));
            $node->appendChild($doc->createElement('IPv4_ADDR', $data['ip']));
            break;
        case 'NOX':
            $node = $doc->createElement('NOX_HANDLER');
            $node->setAttribute('Disable', 'false');
            $node->appendChild($doc->createElement('CANBUS_IF', $data['bus']));
            break;
        default:
            throw new RuntimeException('Unsupported type: ' . $type);
    }

    $components->appendChild($node);

    if ($doc->save($xmlPath) === false) {
        throw new RuntimeException('Failed to save LOG config XML');
    }

    return [
        'id'     => $id,
        'type'   => $type,
        'bus'    => $type === 'NOX' ? $data['bus'] : '-',
        'ip'     => $type === 'NOX' ? '' : $data['ip'],
        'name'   => $type === 'NOX' ? $data['bus'] : $data['name'],
        'status' => 'Okay',
        'origin' => 'xml',
    ];
}

function delete_devices_from_xml(string $xmlPath, array $ids): void
{
    if (!is_file($xmlPath)) {
        throw new RuntimeException("XML not found: $xmlPath");
    }
    if (empty($ids)) {
        throw new RuntimeException('Missing ids');
    }

    $doc = new DOMDocument();
    $doc->preserveWhiteSpace = false;
    $doc->formatOutput = true;
    if (!$doc->load($xmlPath)) {
        throw new RuntimeException('Failed to parse LOG config XML');
    }

    $components = $doc->getElementsByTagName('COMPONENTS')->item(0);
    if ($components === null) {
        throw new RuntimeException('Invalid LOG config XML');
    }

    $set = array_fill_keys($ids, true);
    $children = $components->childNodes;
    for ($i = $children->length - 1; $i >= 0; $i--) {
        $node = $children->item($i);
        if (!$node instanceof DOMElement) continue;

        $tag = strtoupper($node->tagName);
        $bus = '';
        $name = '';
        $ip = '';

        switch ($tag) {
            case 'IOBOX_HANDLER':
            case 'MDAQ_HANDLER':
            case 'MTI_HANDLER':
                $type = str_replace('_HANDLER', '', $tag);
                $name = dom_text_or_empty($node, 'DEV_NAME');
                $ip   = dom_text_or_empty($node, 'IPv4_ADDR');
                $bus  = '-';
                break;
            case 'NOX_HANDLER':
                $type = 'NOX';
                $bus  = dom_text_or_empty($node, 'CANBUS_IF');
                $name = $bus;
                break;
            default:
                continue 2;
        }

        $currId = build_manual_id($type, $bus, $name, $ip);
        if (isset($set[$currId])) {
            $components->removeChild($node);
        }
    }

    if ($doc->save($xmlPath) === false) {
        throw new RuntimeException('Failed to save LOG config XML');
    }
}

// ------------------------------------------------------------
// Controller
// ------------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($method) {
        case 'GET':
            $auto   = load_sdaq_devices($ramdisk, $sandboxDir);
            $manual = load_manual_devices_from_xml($logConfig);
            $all    = array_merge($manual, $auto);

            echo json_encode([
                'ok'         => true,
                'data'       => $all,
                'components' => [
                    'total' => count_components_in_xml($logConfig),
                    'max'   => $maxComponents,
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            break;

        case 'POST':
            $body = read_json_body();

            $type = strtoupper(str_replace('-', '', trim($body['type'] ?? '')));
            $bus  = trim($body['bus'] ?? '');
            $name = trim($body['name'] ?? '');
            $ip   = trim($body['ip'] ?? '');

            if ($type === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'type is required'], JSON_PRETTY_PRINT);
                break;
            }
            if ($type === 'SDAQ') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'SDAQ is auto-discovered from logstat'], JSON_PRETTY_PRINT);
                break;
            }

            if ($type === 'NOX' && $bus === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'bus is required for NOX'], JSON_PRETTY_PRINT);
                break;
            }

            // 非 NOX 设备使用占位符 bus 以保持旧版格式
            if ($type !== 'NOX' && $bus === '') {
                $bus = '-';
            }

            $device = append_device_to_xml($logConfig, [
                'type' => $type,
                'bus'  => $bus,
                'name' => $name,
                'ip'   => $ip,
            ]);

            echo json_encode(['ok' => true, 'data' => $device], JSON_PRETTY_PRINT);
            break;

        case 'DELETE':
            $body = read_json_body();
            $ids  = $body['ids'] ?? [];
            if (!is_array($ids) || empty($ids)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'ids[] is required'], JSON_PRETTY_PRINT);
                break;
            }

            delete_devices_from_xml($logConfig, array_map('strval', $ids));
            echo json_encode(['ok' => true], JSON_PRETTY_PRINT);
            break;

        default:
            http_response_code(405);
            header('Allow: GET, POST, DELETE');
            echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_PRETTY_PRINT);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}