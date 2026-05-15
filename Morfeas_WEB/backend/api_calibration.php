<?php

require __DIR__ . '/core/paths.php';
require __DIR__ . '/core/request.php';
require_once __DIR__ . '/repositories/logstat_repository.php';

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

const CAL_EDIT_LOCK_TYPE = 'sdaq_edit';
const CAL_EDIT_LOCK_TTL_SEC = 30;

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

function cal_fail_with_lock(string $error, ?array $lockRecord, string $sessionId, int $code = 409): void
{
    cal_json([
        'ok' => false,
        'error' => $error,
        'lock' => backend_session_public_record($lockRecord, $sessionId),
    ], $code);
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

function cal_resource_id(string $bus, int $addr): string
{
    return strtolower($bus) . ':' . $addr;
}

function cal_lock_meta(string $tool, string $bus, int $addr): array
{
    return [
        'tool' => $tool,
        'bus' => strtolower($bus),
        'addr' => $addr,
        'label' => sprintf('SDAQ %s addr %d', strtolower($bus), $addr),
    ];
}

function cal_lock_status(string $bus, int $addr, string $sessionId): array
{
    $record = backend_session_registry_get_lock(CAL_EDIT_LOCK_TYPE, cal_resource_id($bus, $addr));
    return [
        'resource_id' => cal_resource_id($bus, $addr),
        'locked' => is_array($record),
        'lock' => backend_session_public_record($record, $sessionId),
    ];
}

function cal_require_owned_lock(string $bus, int $addr, string $sessionId): array
{
    $resourceId = cal_resource_id($bus, $addr);
    $record = backend_session_registry_get_lock(CAL_EDIT_LOCK_TYPE, $resourceId);
    if (!is_array($record)) {
        throw new RuntimeException('Start editing before saving calibration or scale changes.', 409);
    }

    $owner = trim((string)($record['session_id'] ?? ''));
    if ($owner !== $sessionId) {
        throw new RuntimeException('This device is currently locked for calibration editing by another session.', 409);
    }

    return $record;
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

function cal_get_live_measurement(string $bus, int $addr, int $ch): array
{
    $path = backend_ramdisk_dir() . 'logstat_SDAQs_' . $bus . '.json';
    $data = logstat_load_json($path);
    if (!is_array($data)) {
        throw new RuntimeException(sprintf('Live measurement logstat not found for %s', strtoupper($bus)), 404);
    }

    $devices = $data['SDAQs_data'] ?? null;
    if (!is_array($devices)) {
        throw new RuntimeException(sprintf('Invalid live measurement logstat for %s', strtoupper($bus)), 502);
    }

    foreach ($devices as $device) {
        if ((int)($device['Address'] ?? -1) !== $addr) {
            continue;
        }

        $measItems = $device['Meas'] ?? null;
        if (!is_array($measItems)) {
            break;
        }

        foreach ($measItems as $meas) {
            if ((int)($meas['Channel'] ?? -1) !== $ch) {
                continue;
            }

            $rawLast = $meas['Raw_Last_Meas'] ?? null;
            $last = $meas['Last_Meas'] ?? ($meas['Meas_avg'] ?? null);
            $unit = $meas['Unit'] ?? null;

            return [
                'raw_last_meas' => is_numeric($rawLast) ? (float)$rawLast : null,
                'last_meas' => is_numeric($last) ? (float)$last : null,
                'unit' => is_string($unit) ? $unit : null,
                'channel_status' => is_array($meas['Channel_Status'] ?? null) ? $meas['Channel_Status'] : null,
            ];
        }

        break;
    }

    throw new RuntimeException(sprintf('Live measurement not found for %s addr %d ch %d', strtoupper($bus), $addr, $ch), 404);
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

function cal_set_point_field(DOMDocument $doc, DOMElement $pointNode, string $field, string $value): void
{
    $target = null;
    foreach ($pointNode->childNodes as $child) {
        if ($child instanceof DOMElement && $child->tagName === $field) {
            $target = $child;
            break;
        }
    }

    if (!$target) {
        $target = $doc->createElement($field);
        $pointNode->appendChild($target);
    }

    while ($target->firstChild) {
        $target->removeChild($target->firstChild);
    }
    $target->appendChild($doc->createTextNode($value));
}

function cal_compute_linear_coeff(float $x0, float $y0, float $x1, float $y1): array
{
    $dx = $x1 - $x0;
    if (!is_finite($dx) || abs($dx) < 1e-12) {
        throw new RuntimeException('Invalid calibration points: Measure values must be strictly increasing');
    }

    $gain = ($y1 - $y0) / $dx;
    $offset = $y0 - $gain * $x0;
    if (!is_finite($gain) || !is_finite($offset)) {
        throw new RuntimeException('Invalid calibration coefficients: non-finite Offset/Gain');
    }

    return [$offset, $gain];
}

function cal_point_float(DOMXPath $xpath, DOMElement $pointNode, string $field): ?float
{
    $raw = trim((string)$xpath->evaluate(sprintf('string(./%s)', $field), $pointNode));
    if ($raw === '' || preg_match('/^-?nan$/i', $raw) === 1) {
        return null;
    }

    $value = filter_var($raw, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
    if ($value === null || !is_finite((float)$value)) {
        return null;
    }
    return (float)$value;
}

function cal_previous_gain_for_single_point(?string $previousXmlContent, string $channelTag): float
{
    if ($previousXmlContent === null || trim($previousXmlContent) === '') {
        return 1.0;
    }

    $doc = new DOMDocument('1.0', 'utf-8');
    if (!$doc->loadXML($previousXmlContent)) {
        return 1.0;
    }

    $xpath = new DOMXPath($doc);
    $chNode = $xpath->query('/SDAQ/Calibration_Data/' . $channelTag)->item(0);
    if (!$chNode instanceof DOMElement) {
        return 1.0;
    }

    $usedRaw = trim((string)$xpath->evaluate('string(./Used_Points)', $chNode));
    $used = preg_match('/^\d+$/', $usedRaw) === 1 ? (int)$usedRaw : 0;
    if ($used < 1) {
        return 1.0;
    }

    $hasPolynomial = false;
    for ($i = 0; $i < $used; $i++) {
        $point = $xpath->query(sprintf('./Points/Point_%d', $i), $chNode)->item(0);
        if (!$point instanceof DOMElement) {
            continue;
        }
        $c2 = cal_point_float($xpath, $point, 'C2');
        $c3 = cal_point_float($xpath, $point, 'C3');
        if (($c2 !== null && abs($c2) > 1e-12) || ($c3 !== null && abs($c3) > 1e-12)) {
            $hasPolynomial = true;
            break;
        }
    }
    if ($hasPolynomial) {
        return 1.0;
    }

    $point0 = $xpath->query('./Points/Point_0', $chNode)->item(0);
    if (!$point0 instanceof DOMElement) {
        return 1.0;
    }

    $gain = cal_point_float($xpath, $point0, 'Gain');
    return $gain === null ? 1.0 : $gain;
}

function cal_rebuild_linear_coeffs_for_auto_mode(string $xmlContent, ?string $previousXmlContent = null): string
{
    $doc = new DOMDocument('1.0', 'utf-8');
    if (!$doc->loadXML($xmlContent)) {
        throw new RuntimeException('Failed to parse calibration XML');
    }

    $xpath = new DOMXPath($doc);
    $channels = $xpath->query('/SDAQ/Calibration_Data/*');
    if (!$channels) {
        return $xmlContent;
    }

    foreach ($channels as $chNode) {
        if (!$chNode instanceof DOMElement) {
            continue;
        }

        $usedRaw = trim((string)$xpath->evaluate('string(./Used_Points)', $chNode));
        if ($usedRaw === '' || preg_match('/^\d+$/', $usedRaw) !== 1) {
            continue;
        }
        $used = (int)$usedRaw;
        if ($used === 0) {
            continue;
        }

        $pointsNode = $xpath->query('./Points', $chNode)->item(0);
        if (!$pointsNode instanceof DOMElement) {
            throw new RuntimeException(sprintf('Invalid calibration XML: %s has no Points node', $chNode->tagName));
        }

        $points = [];
        for ($i = 0; $i < $used; $i++) {
            $p = $xpath->query(sprintf('./Point_%d', $i), $pointsNode)->item(0);
            if (!$p instanceof DOMElement) {
                throw new RuntimeException(sprintf('Invalid calibration XML: %s->Point_%d is missing', $chNode->tagName, $i));
            }

            $measureRaw = trim((string)$xpath->evaluate('string(./Measure)', $p));
            $refRaw = trim((string)$xpath->evaluate('string(./Reference)', $p));
            if ($measureRaw === '' || $refRaw === '') {
                throw new RuntimeException(sprintf('Invalid calibration XML: %s->Point_%d Measure/Reference is empty', $chNode->tagName, $i));
            }

            $measure = filter_var($measureRaw, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
            $reference = filter_var($refRaw, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
            if ($measure === null || $reference === null || !is_finite((float)$measure) || !is_finite((float)$reference)) {
                throw new RuntimeException(sprintf('Invalid calibration XML: %s->Point_%d Measure/Reference is not finite', $chNode->tagName, $i));
            }

            $points[] = [
                'node' => $p,
                'measure' => (float)$measure,
                'reference' => (float)$reference,
            ];
        }

        for ($i = 1; $i < $used; $i++) {
            if ($points[$i]['measure'] <= $points[$i - 1]['measure']) {
                throw new RuntimeException(sprintf(
                    'Invalid calibration points in %s: Point_%d.Measure must be greater than Point_%d.Measure',
                    $chNode->tagName,
                    $i,
                    $i - 1
                ));
            }
        }

        if ($used === 1) {
            $gain = cal_previous_gain_for_single_point($previousXmlContent, $chNode->tagName);
            $offset = $points[0]['reference'] - ($gain * $points[0]['measure']);
            if (!is_finite($gain) || !is_finite($offset)) {
                throw new RuntimeException('Invalid calibration coefficients: non-finite Offset/Gain');
            }
            cal_set_point_field($doc, $points[0]['node'], 'Offset', cal_num_to_string($offset));
            cal_set_point_field($doc, $points[0]['node'], 'Gain', cal_num_to_string($gain));
            cal_set_point_field($doc, $points[0]['node'], 'C2', '0');
            cal_set_point_field($doc, $points[0]['node'], 'C3', '0');
            continue;
        }

        if ($used === 2) {
            [$offset, $gain] = cal_compute_linear_coeff(
                $points[0]['measure'],
                $points[0]['reference'],
                $points[1]['measure'],
                $points[1]['reference']
            );
            for ($i = 0; $i < 2; $i++) {
                cal_set_point_field($doc, $points[$i]['node'], 'Offset', cal_num_to_string($offset));
                cal_set_point_field($doc, $points[$i]['node'], 'Gain', cal_num_to_string($gain));
                cal_set_point_field($doc, $points[$i]['node'], 'C2', '0');
                cal_set_point_field($doc, $points[$i]['node'], 'C3', '0');
            }
            continue;
        }

        for ($i = 0; $i < $used; $i++) {
            if ($i < $used - 1) {
                $x0 = $points[$i]['measure'];
                $y0 = $points[$i]['reference'];
                $x1 = $points[$i + 1]['measure'];
                $y1 = $points[$i + 1]['reference'];
            } else {
                $x0 = $points[$i - 1]['measure'];
                $y0 = $points[$i - 1]['reference'];
                $x1 = $points[$i]['measure'];
                $y1 = $points[$i]['reference'];
            }

            [$offset, $gain] = cal_compute_linear_coeff($x0, $y0, $x1, $y1);
            cal_set_point_field($doc, $points[$i]['node'], 'Offset', cal_num_to_string($offset));
            cal_set_point_field($doc, $points[$i]['node'], 'Gain', cal_num_to_string($gain));
            cal_set_point_field($doc, $points[$i]['node'], 'C2', '0');
            cal_set_point_field($doc, $points[$i]['node'], 'C3', '0');
        }
    }

    $out = $doc->saveXML();
    if (!is_string($out) || trim($out) === '') {
        throw new RuntimeException('Failed to normalize calibration XML');
    }
    return $out;
}

function cal_validate_calibration_xml(string $xmlContent, array $runtimeUnits): void
{
    $doc = new DOMDocument('1.0', 'utf-8');
    if (!$doc->loadXML($xmlContent)) {
        throw new RuntimeException('Failed to parse calibration XML');
    }

    $xpath = new DOMXPath($doc);
    $channels = $xpath->query('/SDAQ/Calibration_Data/*');
    if (!$channels || $channels->length === 0) {
        throw new RuntimeException('Calibration_Data has no channel nodes');
    }

    $unitSet = array_fill_keys(array_map('strval', $runtimeUnits), true);

    foreach ($channels as $chNode) {
        if (!$chNode instanceof DOMElement) {
            continue;
        }

        $usedRaw = trim((string)$xpath->evaluate('string(./Used_Points)', $chNode));
        if ($usedRaw === '' || preg_match('/^\d+$/', $usedRaw) !== 1) {
            throw new RuntimeException(sprintf('Invalid calibration XML: %s Used_Points is not a non-negative integer', $chNode->tagName));
        }

        $used = (int)$usedRaw;
        $unit = trim((string)$xpath->evaluate('string(./Unit)', $chNode));
        if ($unit === '') {
            throw new RuntimeException(sprintf('Invalid calibration XML: %s Unit is empty', $chNode->tagName));
        }
        if (!isset($unitSet[$unit])) {
            throw new RuntimeException(sprintf('Invalid calibration XML: %s Unit "%s" is not supported by SDAQ runtime unit dictionary', $chNode->tagName, $unit));
        }

        if ($used === 0) {
            continue;
        }

        $pointsNode = $xpath->query('./Points', $chNode)->item(0);
        if (!$pointsNode instanceof DOMElement) {
            throw new RuntimeException(sprintf('Invalid calibration XML: %s has no Points node', $chNode->tagName));
        }

        $prevMeasure = null;
        for ($i = 0; $i < $used; $i++) {
            $pointNode = $xpath->query(sprintf('./Point_%d', $i), $pointsNode)->item(0);
            if (!$pointNode instanceof DOMElement) {
                throw new RuntimeException(sprintf('Invalid calibration XML: %s->Point_%d is missing', $chNode->tagName, $i));
            }

            $finiteValues = [];
            foreach (['Measure', 'Reference', 'Offset', 'Gain', 'C2', 'C3'] as $field) {
                $raw = trim((string)$xpath->evaluate(sprintf('string(./%s)', $field), $pointNode));
                if ($raw === '') {
                    throw new RuntimeException(sprintf('Invalid calibration XML: %s->Point_%d->%s is empty', $chNode->tagName, $i, $field));
                }
                if (preg_match('/^-?nan$/i', $raw) === 1) {
                    throw new RuntimeException(sprintf('Invalid calibration XML: %s->Point_%d->%s is non-finite', $chNode->tagName, $i, $field));
                }

                $value = filter_var($raw, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
                if ($value === null || !is_finite((float)$value)) {
                    throw new RuntimeException(sprintf('Invalid calibration XML: %s->Point_%d->%s is non-finite', $chNode->tagName, $i, $field));
                }
                $finiteValues[$field] = (float)$value;
            }

            if ($prevMeasure !== null && $finiteValues['Measure'] <= $prevMeasure) {
                throw new RuntimeException(sprintf(
                    'Invalid calibration points in %s: Point_%d.Measure must be greater than Point_%d.Measure',
                    $chNode->tagName,
                    $i,
                    $i - 1
                ));
            }

            $prevMeasure = $finiteValues['Measure'];
        }
    }
}

function cal_parse_xml_doc(string $xmlContent, string $label = 'calibration XML'): DOMDocument
{
    $doc = new DOMDocument('1.0', 'utf-8');
    if (!$doc->loadXML($xmlContent)) {
        throw new RuntimeException('Failed to parse ' . $label);
    }
    return $doc;
}

function cal_single_payload_channel(DOMDocument $doc): DOMElement
{
    $xpath = new DOMXPath($doc);
    $channels = $xpath->query('/SDAQ/Calibration_Data/*');
    if (!$channels || $channels->length !== 1) {
        throw new RuntimeException('Metadata-only save must contain exactly one channel');
    }

    $node = $channels->item(0);
    if (!$node instanceof DOMElement) {
        throw new RuntimeException('Metadata-only save contains an invalid channel node');
    }
    return $node;
}

function cal_point_text(DOMXPath $xpath, DOMElement $channelNode, int $pointIdx, string $field): string
{
    return trim((string)$xpath->evaluate(sprintf('string(./Points/Point_%d/%s)', $pointIdx, $field), $channelNode));
}

function cal_assert_legacy_metadata_only(string $postedXml, string $currentXml): void
{
    $postedDoc = cal_parse_xml_doc($postedXml, 'posted calibration XML');
    $currentDoc = cal_parse_xml_doc($currentXml, 'current device calibration XML');
    $postedXpath = new DOMXPath($postedDoc);
    $currentXpath = new DOMXPath($currentDoc);

    $postedCh = cal_single_payload_channel($postedDoc);
    $currentCh = $currentXpath->query('/SDAQ/Calibration_Data/' . $postedCh->tagName)->item(0);
    if (!$currentCh instanceof DOMElement) {
        throw new RuntimeException(sprintf('Metadata-only save cannot find %s in current device XML', $postedCh->tagName));
    }

    foreach (['Used_Points', 'Unit'] as $field) {
        $posted = trim((string)$postedXpath->evaluate(sprintf('string(./%s)', $field), $postedCh));
        $current = trim((string)$currentXpath->evaluate(sprintf('string(./%s)', $field), $currentCh));
        if ($posted !== $current) {
            throw new RuntimeException(sprintf('Metadata-only save cannot change %s', $field));
        }
    }

    $usedRaw = trim((string)$currentXpath->evaluate('string(./Used_Points)', $currentCh));
    if ($usedRaw === '' || preg_match('/^\d+$/', $usedRaw) !== 1) {
        throw new RuntimeException(sprintf('Current device XML has invalid %s Used_Points', $postedCh->tagName));
    }

    $used = (int)$usedRaw;
    foreach (['Measure', 'Reference', 'Offset', 'Gain', 'C2', 'C3'] as $field) {
        for ($i = 0; $i < $used; $i++) {
            $posted = cal_point_text($postedXpath, $postedCh, $i, $field);
            $current = cal_point_text($currentXpath, $currentCh, $i, $field);
            if ($posted !== $current) {
                throw new RuntimeException(sprintf(
                    'Metadata-only save cannot change %s Point_%d %s',
                    $postedCh->tagName,
                    $i,
                    $field
                ));
            }
        }
    }
}

function cal_validate_legacy_metadata_xml(string $xmlContent): void
{
    $doc = cal_parse_xml_doc($xmlContent);
    $chNode = cal_single_payload_channel($doc);
    $period = cal_read_child_text($chNode, 'Calibration_Period');
    if ($period === '' || preg_match('/^\d+$/', $period) !== 1) {
        throw new RuntimeException(sprintf('Invalid calibration XML: %s Calibration_Period is not a non-negative integer', $chNode->tagName));
    }

    $date = cal_read_child_text($chNode, 'Calibration_date');
    if (preg_match('/^(\d{4})\/(\d{2})\/(\d{2})$/', $date, $m) !== 1 || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        throw new RuntimeException(sprintf('Invalid calibration XML: %s Calibration_date must be YYYY/MM/DD', $chNode->tagName));
    }
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
        [0, $rawLow, $engLow],
        [1, $rawHigh, $engHigh],
    ];
    [$linearOffset, $linearGain] = cal_compute_linear_coeff($rawLow, $engLow, $rawHigh, $engHigh);

    foreach ($pointDefs as [$idx, $measure, $reference]) {
        $name = 'Point_' . $idx;
        $pointNode = $doc->createElement($name);
        $points->appendChild($pointNode);

        cal_append_text_node($doc, $pointNode, 'Measure', cal_num_to_string((float)$measure));
        cal_append_text_node($doc, $pointNode, 'Reference', cal_num_to_string((float)$reference));
        cal_append_text_node($doc, $pointNode, 'Offset', cal_num_to_string($linearOffset));
        cal_append_text_node($doc, $pointNode, 'Gain', cal_num_to_string($linearGain));
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

        if ($action === 'live_measurement') {
            $bus = cal_normalize_bus($_GET['bus'] ?? '');
            $addr = cal_normalize_addr($_GET['addr'] ?? null);
            $ch = cal_normalize_ch($_GET['ch'] ?? null);

            if ($bus === '' || $addr === null || $ch === null) {
                cal_fail('Missing or invalid bus/addr/ch for action=live_measurement', 400);
            }

            $live = cal_get_live_measurement($bus, $addr, $ch);
            cal_json([
                'ok' => true,
                'mode' => $mode,
                'action' => 'live_measurement',
                'data' => $live,
            ]);
            exit;
        }

        if ($action === 'edit_status') {
            $bus = cal_normalize_bus($_GET['bus'] ?? '');
            $addr = cal_normalize_addr($_GET['addr'] ?? null);
            if ($bus === '' || $addr === null) {
                cal_fail('Missing or invalid bus/addr for action=edit_status', 400);
            }

            $sessionId = backend_session_token();
            cal_json([
                'ok' => true,
                'mode' => $mode,
                'action' => 'edit_status',
                'data' => cal_lock_status($bus, $addr, $sessionId),
            ]);
            exit;
        }

        cal_fail('Unsupported GET action. Use action=units, action=xml, action=live_measurement, or action=edit_status', 400);
    }

    if ($method === 'POST') {
        $body = read_json_body();
        $mode = 'live';
        $action = strtolower(trim((string)($body['action'] ?? '')));

        if ($action === 'edit_start') {
            $sessionId = backend_require_session_token('Missing session token for action=edit_start');
            $bus = cal_normalize_bus($body['bus'] ?? '');
            $addr = cal_normalize_addr($body['addr'] ?? null);
            $tool = strtolower(trim((string)($body['tool'] ?? 'calibration')));
            if ($tool !== 'scale') {
                $tool = 'calibration';
            }
            if ($bus === '' || $addr === null) {
                cal_fail('Missing or invalid bus/addr for action=edit_start', 400);
            }

            $acquire = backend_session_registry_acquire_lock(
                CAL_EDIT_LOCK_TYPE,
                cal_resource_id($bus, $addr),
                $sessionId,
                CAL_EDIT_LOCK_TTL_SEC,
                'edit',
                cal_lock_meta($tool, $bus, $addr)
            );

            if (!$acquire['acquired']) {
                cal_fail_with_lock(
                    'This device is currently locked for calibration editing by another session.',
                    $acquire['record'] ?? null,
                    $sessionId,
                    409
                );
            }

            cal_json([
                'ok' => true,
                'mode' => $mode,
                'action' => 'edit_start',
                'data' => cal_lock_status($bus, $addr, $sessionId),
            ]);
            exit;
        }

        if ($action === 'edit_renew') {
            $sessionId = backend_require_session_token('Missing session token for action=edit_renew');
            $bus = cal_normalize_bus($body['bus'] ?? '');
            $addr = cal_normalize_addr($body['addr'] ?? null);
            $tool = strtolower(trim((string)($body['tool'] ?? 'calibration')));
            if ($tool !== 'scale') {
                $tool = 'calibration';
            }
            if ($bus === '' || $addr === null) {
                cal_fail('Missing or invalid bus/addr for action=edit_renew', 400);
            }

            $renew = backend_session_registry_renew_lock(
                CAL_EDIT_LOCK_TYPE,
                cal_resource_id($bus, $addr),
                $sessionId,
                CAL_EDIT_LOCK_TTL_SEC,
                cal_lock_meta($tool, $bus, $addr)
            );

            if (!$renew['renewed']) {
                cal_fail_with_lock(
                    'Editing lock expired or was taken by another session.',
                    $renew['record'] ?? null,
                    $sessionId,
                    409
                );
            }

            cal_json([
                'ok' => true,
                'mode' => $mode,
                'action' => 'edit_renew',
                'data' => cal_lock_status($bus, $addr, $sessionId),
            ]);
            exit;
        }

        if ($action === 'edit_end') {
            $sessionId = backend_require_session_token('Missing session token for action=edit_end');
            $bus = cal_normalize_bus($body['bus'] ?? '');
            $addr = cal_normalize_addr($body['addr'] ?? null);
            if ($bus === '' || $addr === null) {
                cal_fail('Missing or invalid bus/addr for action=edit_end', 400);
            }

            $release = backend_session_registry_release_lock(
                CAL_EDIT_LOCK_TYPE,
                cal_resource_id($bus, $addr),
                $sessionId
            );

            if (!$release['released']) {
                cal_fail_with_lock(
                    'This device is currently locked for calibration editing by another session.',
                    $release['record'] ?? null,
                    $sessionId,
                    409
                );
            }

            cal_json([
                'ok' => true,
                'mode' => $mode,
                'action' => 'edit_end',
                'data' => cal_lock_status($bus, $addr, $sessionId),
            ]);
            exit;
        }

        if ($action === 'scale') {
            $sessionId = backend_require_session_token('Missing session token for action=scale');
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
            if ((float)$rawHigh <= (float)$rawLow) {
                cal_fail('Invalid raw range: RawHigh must be greater than RawLow', 400);
            }
            if ($engUnit === '') {
                cal_fail('engUnit is required for action=scale', 400);
            }

            cal_require_owned_lock($bus, $addr, $sessionId);

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
            $xml = cal_rebuild_linear_coeffs_for_auto_mode($xml);

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

        if ($action === '' || $action === 'calibration_save') {
            $sessionId = backend_require_session_token('Missing session token for action=calibration_save');
            $bus = cal_normalize_bus($body['bus'] ?? ($body['SDAQnet'] ?? ''));
            $addr = cal_normalize_addr($body['addr'] ?? ($body['SDAQaddr'] ?? null));
            $xml = (string)($body['xmlContent'] ?? ($body['XMLcontent'] ?? ''));
            $saveMode = strtolower(trim((string)($body['mode'] ?? 'legacy')));
            if ($saveMode === '') {
                $saveMode = 'legacy';
            }

            if ($bus === '' || $addr === null) {
                cal_fail('Missing or invalid bus/addr in request body', 400);
            }

            if (trim($xml) === '') {
                cal_fail('xmlContent is required', 400);
            }

            if ($saveMode !== 'legacy' && $saveMode !== 'auto-linear') {
                cal_fail('Invalid calibration save mode. Use legacy or auto-linear', 400);
            }

            cal_require_owned_lock($bus, $addr, $sessionId);

            $previousXml = null;
            if ($saveMode === 'auto-linear') {
                $units = cal_get_units_live();
                $unitList = $units['SDAQ_UNITs'] ?? [];
                if (!is_array($unitList)) {
                    cal_fail('Failed to load runtime SDAQ unit dictionary', 500);
                }

                $previousXml = cal_get_xml_live($bus, $addr);
                $xml = cal_rebuild_linear_coeffs_for_auto_mode($xml, $previousXml);
                cal_validate_calibration_xml($xml, $unitList);
                cal_validate_legacy_metadata_xml($xml);
            } else {
                $previousXml = cal_get_xml_live($bus, $addr);
                cal_assert_legacy_metadata_only($xml, $previousXml);
                cal_validate_legacy_metadata_xml($xml);
            }

            $result = cal_save_xml_live($bus, $addr, $xml);

            cal_json([
                'ok' => true,
                'mode' => $mode,
                'action' => 'calibration_save',
                'save_mode' => $saveMode,
                'bus' => strtoupper($bus),
                'addr' => $addr,
                'message' => $result['message'] ?? 'Calibration saved',
                'path' => $result['path'] ?? null,
                'output' => $result['output'] ?? null,
            ]);
            exit;
        }

        cal_fail('Unsupported POST action. Use action=edit_start, action=edit_renew, action=edit_end, action=calibration_save, or action=scale', 400);
    }

    http_response_code(405);
    header('Allow: GET, POST');
    cal_json(['ok' => false, 'error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    $statusCode = (int)$e->getCode();
    if ($statusCode < 400 || $statusCode > 599) {
        $statusCode = 500;
    }
    if ($statusCode >= 500) {
        api_fail_response(
            'Calibration operation failed',
            $statusCode,
            'api_calibration',
            $e,
            ['status_code' => $statusCode]
        );
    }

    cal_json([
        'ok' => false,
        'error' => $e->getMessage(),
    ], $statusCode);
}
