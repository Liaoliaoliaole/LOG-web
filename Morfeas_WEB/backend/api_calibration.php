<?php

require __DIR__ . '/core/paths.php';
require __DIR__ . '/core/request.php';

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function cal_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function cal_fail(string $error, int $code = 400): void
{
    cal_json(['ok' => false, 'error' => $error], $code);
    exit;
}

function cal_normalize_bus($bus): string
{
    $bus = strtolower(trim((string)$bus));
    if ($bus === '') {
        return '';
    }

    if (preg_match('/^can\d+$/', $bus) !== 1) {
        if (ctype_digit($bus)) {
            $bus = 'can' . $bus;
        } else {
            return '';
        }
    }

    return $bus;
}

function cal_normalize_addr($addr): ?int
{
    if ($addr === null || $addr === '') {
        return null;
    }

    if (is_string($addr) && preg_match('/^\d+$/', $addr) !== 1) {
        return null;
    }

    $value = (int)$addr;
    if ($value < 0 || $value > 255) {
        return null;
    }

    return $value;
}

function cal_normalize_ch($ch): ?int
{
    if ($ch === null || $ch === '') {
        return null;
    }

    if (is_string($ch) && preg_match('/^\d+$/', $ch) !== 1) {
        return null;
    }

    $value = (int)$ch;
    if ($value < 1 || $value > 255) {
        return null;
    }

    return $value;
}

function cal_run_command(string $cmd, ?string $stdin = null): array
{
    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = proc_open($cmd, $desc, $pipes);
    if (!is_resource($proc)) {
        return ['code' => 127, 'stdout' => '', 'stderr' => 'Failed to start process'];
    }

    if ($stdin !== null) {
        fwrite($pipes[0], $stdin);
    }
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $code = proc_close($proc);

    return [
        'code' => (int)$code,
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

function cal_get_units_live(): array
{
    $res = cal_run_command('SDAQ_psim -u');
    if ($res['code'] !== 0) {
        $msg = trim($res['stderr']) !== '' ? trim($res['stderr']) : trim($res['stdout']);
        throw new RuntimeException($msg !== '' ? $msg : 'SDAQ_psim -u failed');
    }

    $decoded = json_decode($res['stdout'], true);
    if (!is_array($decoded) || !isset($decoded['SDAQ_UNITs']) || !is_array($decoded['SDAQ_UNITs'])) {
        throw new RuntimeException('Unexpected output from SDAQ_psim -u');
    }

    return $decoded;
}

function cal_get_xml_live(string $bus, int $addr): string
{
    $cmd = sprintf(
        'SDAQ_worker %s getinfo %d -s',
        escapeshellarg($bus),
        $addr
    );

    $res = cal_run_command($cmd);
    if ($res['code'] !== 0) {
        $msg = trim($res['stderr']) !== '' ? trim($res['stderr']) : trim($res['stdout']);
        throw new RuntimeException($msg !== '' ? $msg : 'SDAQ_worker getinfo failed', 502);
    }

    $xml = trim($res['stdout']);
    if ($xml === '' || strpos($xml, '<SDAQ') === false) {
        throw new RuntimeException('SDAQ_worker returned no calibration XML', 502);
    }

    return $xml;
}

function cal_save_xml_live(string $bus, int $addr, string $xml): array
{
    $cmd = sprintf(
        'SDAQ_worker %s setinfo %d -vsf-.xml',
        escapeshellarg($bus),
        $addr
    );

    $res = cal_run_command($cmd, $xml);
    if ($res['code'] !== 0) {
        $msg = trim($res['stderr']) !== '' ? trim($res['stderr']) : trim($res['stdout']);
        throw new RuntimeException($msg !== '' ? $msg : 'SDAQ_worker setinfo failed', 502);
    }

    return [
        'message' => sprintf('Server: Calibration table written with success at SDAQ with ADDR:%d', $addr),
        'output' => trim($res['stdout']),
    ];
}

function cal_num_to_string(float $value): string
{
    if (!is_finite($value)) {
        throw new RuntimeException('Scale value is not finite');
    }

    $text = sprintf('%.12F', $value);
    $text = rtrim(rtrim($text, '0'), '.');
    if ($text === '' || $text === '-0') {
        return '0';
    }
    return $text;
}

function cal_read_child_text(?DOMElement $parent, string $tag): string
{
    if (!$parent) {
        return '';
    }

    foreach ($parent->childNodes as $child) {
        if ($child instanceof DOMElement && $child->tagName === $tag) {
            return trim($child->textContent ?? '');
        }
    }

    return '';
}

function cal_append_text_node(DOMDocument $doc, DOMElement $parent, string $tag, string $value): void
{
    $node = $doc->createElement($tag);
    $node->appendChild($doc->createTextNode($value));
    $parent->appendChild($node);
}

function cal_build_scale_xml_payload(
    string $sourceXml,
    int $ch,
    float $rawLow,
    float $rawHigh,
    float $engLow,
    float $engHigh,
    string $engUnit
): string {
    $sourceDoc = new DOMDocument('1.0', 'utf-8');
    if (!$sourceDoc->loadXML($sourceXml)) {
        throw new RuntimeException('Failed to parse device XML');
    }

    $xpath = new DOMXPath($sourceDoc);
    $infoNode = $xpath->query('/SDAQ/SDAQ_info')->item(0);
    if (!$infoNode instanceof DOMElement) {
        throw new RuntimeException('SDAQ_info node not found in source XML');
    }

    $chTag = 'CH' . $ch;
    $sourceChNode = $xpath->query('/SDAQ/Calibration_Data/' . $chTag)->item(0);

    $calDate = cal_read_child_text($sourceChNode instanceof DOMElement ? $sourceChNode : null, 'Calibration_date');
    $calPeriod = cal_read_child_text($sourceChNode instanceof DOMElement ? $sourceChNode : null, 'Calibration_Period');

    if ($calDate === '' || $calDate === '2000/00/00') {
        $calDate = date('Y/m/d');
    }
    if ($calPeriod === '' || !preg_match('/^\d+$/', $calPeriod)) {
        $calPeriod = '0';
    }

    $doc = new DOMDocument('1.0', 'utf-8');
    $doc->formatOutput = false;

    $root = $doc->createElement('SDAQ');
    $doc->appendChild($root);
    $root->appendChild($doc->importNode($infoNode, true));

    $calData = $doc->createElement('Calibration_Data');
    $root->appendChild($calData);

    $dstCh = $doc->createElement($chTag);
    $calData->appendChild($dstCh);
    cal_append_text_node($doc, $dstCh, 'Calibration_date', $calDate);
    cal_append_text_node($doc, $dstCh, 'Calibration_Period', $calPeriod);
    cal_append_text_node($doc, $dstCh, 'Used_Points', '2');
    cal_append_text_node($doc, $dstCh, 'Unit', $engUnit);

    $points = $doc->createElement('Points');
    $dstCh->appendChild($points);

    $pointDefs = [
        ['Point_0', $rawLow, $engLow],
        ['Point_1', $rawHigh, $engHigh],
    ];

    foreach ($pointDefs as [$name, $measure, $reference]) {
        $pointNode = $doc->createElement($name);
        $points->appendChild($pointNode);

        cal_append_text_node($doc, $pointNode, 'Measure', cal_num_to_string((float)$measure));
        cal_append_text_node($doc, $pointNode, 'Reference', cal_num_to_string((float)$reference));
        cal_append_text_node($doc, $pointNode, 'Offset', '0');
        cal_append_text_node($doc, $pointNode, 'Gain', '1');
        cal_append_text_node($doc, $pointNode, 'C2', '0');
        cal_append_text_node($doc, $pointNode, 'C3', '0');
    }

    $xml = $doc->saveXML();
    if (!is_string($xml) || trim($xml) === '') {
        throw new RuntimeException('Failed to build scale XML');
    }

    return $xml;
}

try {
    if ($method === 'GET') {
        $action = strtolower(trim((string)($_GET['action'] ?? '')));
        if ($action === '') {
            if (array_key_exists('UNITs', $_GET)) {
                $action = 'units';
            } elseif (array_key_exists('SDAQnet', $_GET) && array_key_exists('SDAQaddr', $_GET)) {
                $action = 'xml';
            }
        }
        $mode = 'live';

        if ($action === 'units') {
            $units = cal_get_units_live();
            cal_json([
                'ok' => true,
                'mode' => $mode,
                'data' => $units,
            ]);
            exit;
        }

        if ($action === 'xml') {
            $bus = cal_normalize_bus($_GET['bus'] ?? ($_GET['SDAQnet'] ?? ''));
            $addr = cal_normalize_addr($_GET['addr'] ?? ($_GET['SDAQaddr'] ?? null));

            if ($bus === '' || $addr === null) {
                cal_fail('Missing or invalid bus/addr for action=xml', 400);
            }

            $xml = cal_get_xml_live($bus, $addr);

            header('Content-Type: application/xml; charset=utf-8');
            header('X-Calibration-Mode: ' . $mode);
            echo $xml;
            exit;
        }

        cal_fail('Unsupported GET action. Use action=units or action=xml', 400);
    }

    if ($method === 'POST') {
        $body = read_json_body();
        $mode = 'live';
        $action = strtolower(trim((string)($body['action'] ?? '')));

        if ($action === 'scale') {
            $bus = cal_normalize_bus($body['bus'] ?? '');
            $addr = cal_normalize_addr($body['addr'] ?? null);
            $ch = cal_normalize_ch($body['ch'] ?? null);
            $engUnit = trim((string)($body['engUnit'] ?? ''));

            $rawLow = filter_var($body['rawLow'] ?? null, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
            $rawHigh = filter_var($body['rawHigh'] ?? null, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
            $engLow = filter_var($body['engLow'] ?? null, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
            $engHigh = filter_var($body['engHigh'] ?? null, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);

            if ($bus === '' || $addr === null || $ch === null) {
                cal_fail('Missing or invalid bus/addr/ch for action=scale', 400);
            }
            if ($rawLow === null || $rawHigh === null || $engLow === null || $engHigh === null) {
                cal_fail('Scale values must be valid numbers', 400);
            }
            if ((float)$rawHigh === (float)$rawLow) {
                cal_fail('Invalid raw range: RawHigh equals RawLow', 400);
            }
            if ($engUnit === '') {
                cal_fail('engUnit is required for action=scale', 400);
            }

            $units = cal_get_units_live();
            $unitList = $units['SDAQ_UNITs'] ?? [];
            if (!is_array($unitList) || !in_array($engUnit, $unitList, true)) {
                cal_fail('engUnit is not supported by SDAQ runtime unit dictionary', 400);
            }

            $sourceXml = cal_get_xml_live($bus, $addr);
            $xml = cal_build_scale_xml_payload(
                $sourceXml,
                $ch,
                (float)$rawLow,
                (float)$rawHigh,
                (float)$engLow,
                (float)$engHigh,
                $engUnit
            );

            $result = cal_save_xml_live($bus, $addr, $xml);
            cal_json([
                'ok' => true,
                'mode' => $mode,
                'action' => 'scale',
                'bus' => strtoupper($bus),
                'addr' => $addr,
                'ch' => $ch,
                'message' => $result['message'] ?? 'Scale saved',
                'output' => $result['output'] ?? null,
            ]);
            exit;
        }

        $bus = cal_normalize_bus($body['bus'] ?? ($body['SDAQnet'] ?? ''));
        $addr = cal_normalize_addr($body['addr'] ?? ($body['SDAQaddr'] ?? null));
        $xml = (string)($body['xmlContent'] ?? ($body['XMLcontent'] ?? ''));

        if ($bus === '' || $addr === null) {
            cal_fail('Missing or invalid bus/addr in request body', 400);
        }

        if (trim($xml) === '') {
            cal_fail('xmlContent is required', 400);
        }

        $result = cal_save_xml_live($bus, $addr, $xml);

        cal_json([
            'ok' => true,
            'mode' => $mode,
            'bus' => strtoupper($bus),
            'addr' => $addr,
            'message' => $result['message'] ?? 'Calibration saved',
            'path' => $result['path'] ?? null,
            'output' => $result['output'] ?? null,
        ]);
        exit;
    }

    http_response_code(405);
    header('Allow: GET, POST');
    cal_json(['ok' => false, 'error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    $statusCode = (int)$e->getCode();
    if ($statusCode < 400 || $statusCode > 599) {
        $statusCode = 500;
    }
    cal_json([
        'ok' => false,
        'error' => $e->getMessage(),
    ], $statusCode);
}
