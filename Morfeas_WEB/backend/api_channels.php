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

class ChannelRuleException extends RuntimeException
{
    private int $status;
    private string $apiCode;

    public function __construct(string $message, int $status = 409, string $apiCode = 'channel_rule_violation')
    {
        parent::__construct($message);
        $this->status = $status;
        $this->apiCode = $apiCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function apiCode(): string
    {
        return $this->apiCode;
    }
}

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

function channel_collect_rows_and_extras(
    string $xmlPath,
    array $sdaqLogFiles,
    array $ioboxLogFiles,
    array $mtiLogFiles,
    array $noxLogFiles,
    array $sdaqDeviceTypes
): array {
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

    if (!is_array($extras)) {
        $extras = [];
    }


    return [$rows, $extras];
}

function channel_normalize_family(?string $raw): string
{
    return strtoupper(trim((string)$raw));
}

function channel_normalize_subtype(?string $raw): string
{
    return strtoupper(trim((string)$raw));
}

function channel_anchor_tokens(?string $anchor): array
{
    $raw = trim((string)$anchor);
    if ($raw === '') {
        return [];
    }

    $tokens = [];
    $tokens[] = strtoupper($raw);
    $tokens[] = strtoupper(preg_replace('/\s+/', '', $raw) ?? $raw);

    if (preg_match('/^(CAN\w+)\.(\d+)\.CH(\d+)$/i', $raw, $m)) {
        $tokens[] = sprintf('%s.ADDR:%02d.CH:%02d', strtoupper($m[1]), (int)$m[2], (int)$m[3]);
        $tokens[] = sprintf('%s.ADDR:%d.CH:%d', strtoupper($m[1]), (int)$m[2], (int)$m[3]);
    }

    return array_values(array_unique(array_filter($tokens)));
}

function channel_search_pool_all_candidates(array $searchPool): array
{
    $all = [];
    foreach ($searchPool as $family => $items) {
        if (!is_array($items)) {
            continue;
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (empty($item['interface_type'])) {
                $item['interface_type'] = strtoupper((string)$family);
            }
            $all[] = $item;
        }
    }
    return $all;
}

function channel_candidate_family(array $candidate): string
{
    $family = channel_normalize_family($candidate['interface_type'] ?? '');
    if ($family !== '') {
        return $family;
    }

    $deviceType = channel_normalize_subtype($candidate['device_type'] ?? '');
    if (str_starts_with($deviceType, 'SDAQ')) {
        return 'SDAQ';
    }
    if (str_starts_with($deviceType, 'IOBOX')) {
        return 'IOBOX';
    }
    if (str_starts_with($deviceType, 'MTI')) {
        return 'MTI';
    }
    if (str_starts_with($deviceType, 'NOX')) {
        return 'NOX';
    }
    return '';
}

function channel_find_candidate_by_anchor(array $searchPool, string $anchor): ?array
{
    $tokens = channel_anchor_tokens($anchor);
    if (empty($tokens)) {
        return null;
    }

    $candidates = channel_search_pool_all_candidates($searchPool);
    foreach ($candidates as $candidate) {
        $keys = [];
        foreach (['anchor', 'display_anchor', 'address_anchor', 'serial_anchor'] as $field) {
            if (!empty($candidate[$field])) {
                $keys = array_merge($keys, channel_anchor_tokens((string)$candidate[$field]));
            }
        }

        if (!empty($candidate['aliases']) && is_array($candidate['aliases'])) {
            foreach ($candidate['aliases'] as $alias) {
                $keys = array_merge($keys, channel_anchor_tokens((string)$alias));
            }
        }

        if (empty($keys)) {
            continue;
        }

        $keys = array_values(array_unique($keys));
        foreach ($tokens as $needle) {
            if (in_array($needle, $keys, true)) {
                return $candidate;
            }
        }
    }

    return null;
}

function channel_enforce_replace_rules(array $rows, array $extras, string $iso, array $data): void
{
    $source = channel_find_by_iso($rows, $iso);
    if ($source === null) {
        throw new ChannelRuleException('Source channel not found for replace', 404, 'replace_source_not_found');
    }

    $sourceFamily = channel_normalize_family($source['interface_type'] ?? ($source['dev_type'] ?? ''));
    $sourceSubtype = trim((string)($source['dev_type'] ?? ''));
    $sourceKnown = !empty($source['dev_type_known']);

    $targetAnchor = trim((string)($data['anchor'] ?? ''));
    if ($targetAnchor === '') {
        throw new ChannelRuleException('Missing replacement anchor', 400, 'replace_target_missing');
    }

    $searchPool = is_array($extras['search_pool'] ?? null) ? $extras['search_pool'] : [];
    $candidate = channel_find_candidate_by_anchor($searchPool, $targetAnchor);

    if ($candidate === null) {
        if ($sourceFamily === 'SDAQ' && !$sourceKnown) {
            return;
        }
        throw new ChannelRuleException(
            'Replacement target type cannot be verified from current device pool',
            409,
            'replace_target_not_detected'
        );
    }

    $candidateFamily = channel_candidate_family($candidate);
    if ($candidateFamily !== '' && $sourceFamily !== '' && $candidateFamily !== $sourceFamily) {
        throw new ChannelRuleException(
            sprintf('Replace type mismatch: expected %s, got %s', $sourceFamily, $candidateFamily),
            409,
            'replace_type_mismatch'
        );
    }

    if ($sourceFamily === 'SDAQ' && $sourceKnown) {
        $sourceSubtypeNorm = channel_normalize_subtype($sourceSubtype);
        $candidateSubtypeRaw = trim((string)($candidate['device_type'] ?? ''));
        $candidateSubtypeNorm = channel_normalize_subtype($candidateSubtypeRaw);

        if ($sourceSubtypeNorm === '') {
            throw new ChannelRuleException(
                'Source SDAQ subtype is unknown; cannot enforce compatibility',
                409,
                'replace_sdaq_subtype_unknown'
            );
        }

        if ($candidateSubtypeNorm === '') {
            throw new ChannelRuleException(
                'Replacement SDAQ subtype is unknown; choose a detected SDAQ device',
                409,
                'replace_sdaq_subtype_unknown'
            );
        }

        if ($sourceSubtypeNorm !== $candidateSubtypeNorm) {
            throw new ChannelRuleException(
                sprintf('Replace SDAQ subtype mismatch: expected %s, got %s', $sourceSubtype, $candidateSubtypeRaw),
                409,
                'replace_sdaq_subtype_mismatch'
            );
        }
    }
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
            iso_add_channel($xmlPath, $data);
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

            if (!empty($data['replace_mode'])) {
                [$rows, $extras] = channel_collect_rows_and_extras(
                    $xmlPath,
                    $sdaqLogFiles,
                    $ioboxLogFiles,
                    $mtiLogFiles,
                    $noxLogFiles,
                    $sdaqDeviceTypes
                );
                channel_enforce_replace_rules($rows, $extras, $iso, $data);
            }

            iso_update_channel($xmlPath, $iso, $data);
            echo json_encode(['ok' => true], JSON_PRETTY_PRINT);
            break;

        case 'DELETE':
            $iso = $_GET['iso'] ?? null;
            if ($iso === null || $iso === '') {
                channels_fail('Missing ?iso=... in query', 400, 'missing_iso');
            }
            iso_delete_channel($xmlPath, $iso);
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
