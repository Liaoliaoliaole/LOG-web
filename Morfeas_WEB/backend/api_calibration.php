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
