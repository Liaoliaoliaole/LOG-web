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

function channels_fail_from_runtime(RuntimeException $e): void
{
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

function channel_status_offline(string $status): bool
{
    $s = strtolower(trim($status));
    return in_array($s, ['off-line', 'offline', 'disconnected'], true);
}

function channel_normalize_tc16_subtype(?string $raw): string
{
    $text = strtoupper(trim((string)$raw));
    if ($text === '') {
        return '';
    }
    $text = preg_replace('/\s*\(.*$/', '', $text) ?? $text;
    return trim($text);
}

function channel_is_tc16_compatible(?string $raw): bool
{
    return channel_normalize_tc16_subtype($raw) === 'SDAQ-TC16';
}

function channel_parse_addr_channel(?string $anchor): ?array
{
    $raw = strtoupper(trim((string)$anchor));
    if ($raw === '') {
        return null;
    }

    if (preg_match('/^(CAN\w+)\.ADDR:(\d{1,3})\.CH:?(\d{1,3})$/i', $raw, $m)) {
        $bus = strtoupper($m[1]);
        $addr = (int)$m[2];
        $ch = (int)$m[3];
        return [
            'key' => sprintf('%s.ADDR:%02d', $bus, $addr),
            'bus' => $bus,
            'address' => $addr,
            'ch' => $ch,
        ];
    }

    if (preg_match('/^(CAN\w+)\.(\d{1,3})\.CH:?(\d{1,3})$/i', $raw, $m)) {
        $bus = strtoupper($m[1]);
        $addr = (int)$m[2];
        $ch = (int)$m[3];
        return [
            'key' => sprintf('%s.ADDR:%02d', $bus, $addr),
            'bus' => $bus,
            'address' => $addr,
            'ch' => $ch,
        ];
    }

    return null;
}

function channel_parse_sn_channel(?string $anchor): ?array
{
    $raw = trim((string)$anchor);
    if ($raw === '') {
        return null;
    }

    if (preg_match('/^(\d+)\.CH:?(\d{1,3})$/i', $raw, $m)) {
        return [
            'sn' => (string)$m[1],
            'ch' => (int)$m[2],
        ];
    }

    return null;
}

function channel_row_is_sdaq(array $row): bool
{
    $family = channel_normalize_family($row['interface_type'] ?? ($row['dev_type'] ?? ''));
    if ($family === 'SDAQ') {
        return true;
    }
    return str_starts_with(channel_normalize_subtype($row['dev_type'] ?? ''), 'SDAQ');
}

function channel_row_addr_info(array $row): ?array
{
    $display = channel_parse_addr_channel((string)($row['display_anchor'] ?? ''));
    if ($display !== null) {
        return $display;
    }
    return channel_parse_addr_channel((string)($row['anchor'] ?? ''));
}

function channel_row_sn_info(array $row): ?array
{
    $raw = channel_parse_sn_channel((string)($row['anchor'] ?? ''));
    if ($raw !== null) {
        return $raw;
    }
    return channel_parse_sn_channel((string)($row['display_anchor'] ?? ''));
}

function channel_group_is_full16(array $group): bool
{
    for ($ch = 1; $ch <= 16; $ch++) {
        if (!isset($group[$ch])) {
            return false;
        }
    }
    return true;
}

function channel_group_by_addr(array $rows, string $addrKey): array
{
    $group = [];
    foreach ($rows as $row) {
        if (!channel_row_is_sdaq($row)) {
            continue;
        }
        $addr = channel_row_addr_info($row);
        if ($addr === null || strtoupper($addr['key']) !== strtoupper($addrKey)) {
            continue;
        }
        $ch = (int)($addr['ch'] ?? 0);
        if ($ch < 1 || $ch > 16) {
            continue;
        }
        if (!isset($group[$ch])) {
            $group[$ch] = $row;
        }
    }
    return $group;
}

function channel_group_by_sn(array $rows, string $sn): array
{
    $group = [];
    foreach ($rows as $row) {
        if (!channel_row_is_sdaq($row)) {
            continue;
        }
        $snInfo = channel_row_sn_info($row);
        if ($snInfo === null || (string)$snInfo['sn'] !== (string)$sn) {
            continue;
        }
        $ch = (int)($snInfo['ch'] ?? 0);
        if ($ch < 1 || $ch > 16) {
            continue;
        }
        if (!isset($group[$ch])) {
            $group[$ch] = $row;
        }
    }
    return $group;
}

function channel_group_to_source_map(array $group, string $mode, string $sourceKey): array
{
    ksort($group, SORT_NUMERIC);
    $items = [];
    foreach ($group as $ch => $row) {
        $items[] = [
            'ch_no' => (int)$ch,
            'iso_channel' => (string)($row['iso_channel'] ?? ''),
            'anchor' => (string)($row['anchor'] ?? ''),
            'display_anchor' => (string)($row['display_anchor'] ?? ($row['anchor'] ?? '')),
            'source_mode' => $mode,
            'source_key' => $sourceKey,
        ];
    }
    return $items;
}

function channel_collect_sdaq_capabilities(array $sdaqLogFiles): array
{
    $devices = [];

    foreach ($sdaqLogFiles as $path) {
        if (!is_file($path)) {
            continue;
        }
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            continue;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            continue;
        }

        $bus = strtoupper(sdaq_detect_bus($data, $path));
        $sdaqs = $data['SDAQs_data'] ?? null;
        if (!is_array($sdaqs)) {
            continue;
        }

        foreach ($sdaqs as $dev) {
            if (!is_array($dev)) {
                continue;
            }
            $addr = $dev['Address'] ?? null;
            if ($addr === null) {
                continue;
            }

            $addrInt = (int)$addr;
            $type = trim((string)($dev['SDAQ_type'] ?? ''));
            $serial = trim((string)($dev['Serial_number'] ?? ''));
            $numChannels = (int)($dev['SDAQ_info']['Number_of_channels'] ?? 0);
            $deviceKey = sprintf('%s.ADDR:%02d', $bus, $addrInt);

            $devices[$deviceKey] = [
                'device_key' => $deviceKey,
                'bus' => $bus,
                'address' => $addrInt,
                'serial' => $serial,
                'sdaq_type' => $type,
                'number_of_channels' => $numChannels,
            ];
        }
    }

    ksort($devices, SORT_STRING);
    return $devices;
}

function channel_target_aliases_for_channel(array $device, int $ch): array
{
    $bus = strtoupper((string)($device['bus'] ?? ''));
    $addr = (int)($device['address'] ?? 0);
    $serial = trim((string)($device['serial'] ?? ''));

    $aliases = [
        sprintf('%s.ADDR:%02d.CH:%02d', $bus, $addr, $ch),
        sprintf('%s.ADDR:%d.CH:%d', $bus, $addr, $ch),
        sprintf('%s.ADDR:%d.CH%d', $bus, $addr, $ch),
        sprintf('%s.%d.CH%d', $bus, $addr, $ch),
        sprintf('%s.%d.CH:%d', $bus, $addr, $ch),
    ];

    if ($serial !== '') {
        $aliases[] = sprintf('%s.CH%d', $serial, $ch);
    }

    return array_values(array_unique(array_filter($aliases)));
}

function channel_target_anchor_for_channel(array $device, int $ch): string
{
    $serial = trim((string)($device['serial'] ?? ''));
    if ($serial !== '') {
        return sprintf('%s.CH%d', $serial, $ch);
    }

    $bus = strtolower((string)($device['bus'] ?? 'can0'));
    $addr = (int)($device['address'] ?? 0);
    return sprintf('%s.%d.CH%d', $bus, $addr, $ch);
}

function channel_anchor_usage_from_rows(array $rows): array
{
    $usage = [];
    foreach ($rows as $row) {
        $iso = strtoupper(trim((string)($row['iso_channel'] ?? '')));
        if ($iso === '') {
            continue;
        }

        $tokens = channel_anchor_tokens((string)($row['anchor'] ?? ''));
        foreach ($tokens as $token) {
            if (!isset($usage[$token])) {
                $usage[$token] = [];
            }
            $usage[$token][$iso] = true;
        }
    }

    return $usage;
}

function channel_target_channel_is_unlinked(array $usage, array $device, int $ch, array $ignoreIsoSet = []): bool
{
    $aliases = channel_target_aliases_for_channel($device, $ch);
    foreach ($aliases as $alias) {
        $tokens = channel_anchor_tokens($alias);
        foreach ($tokens as $token) {
            if (empty($usage[$token])) {
                continue;
            }

            foreach (array_keys($usage[$token]) as $iso) {
                if (!isset($ignoreIsoSet[$iso])) {
                    return false;
                }
            }
        }
    }

    return true;
}

function channel_resolve_tc16_source_group(array $rows, string $sourceIso): array
{
    $sourceIsoNorm = iso_normalize_iso_channel($sourceIso);
    if ($sourceIsoNorm === null || $sourceIsoNorm === '') {
        throw new ChannelRuleException('Missing source ISO', 400, 'missing_source_iso');
    }

    $source = channel_find_by_iso($rows, $sourceIsoNorm);
    if ($source === null) {
        throw new ChannelRuleException('Source channel not found', 404, 'tc16_source_unresolvable');
    }

    if (!channel_status_offline((string)($source['status'] ?? ''))) {
        throw new ChannelRuleException('Source channel must be offline for Replace TC16', 409, 'tc16_source_not_offline');
    }

    $sourceSubtype = (string)($source['dev_type_display'] ?? ($source['dev_type'] ?? ''));
    if (!channel_is_tc16_compatible($sourceSubtype)) {
        throw new ChannelRuleException('Source channel subtype is not SDAQ-TC16', 409, 'tc16_subtype_mismatch');
    }

    $addrInfo = channel_row_addr_info($source);
    if ($addrInfo !== null) {
        $addrGroup = channel_group_by_addr($rows, (string)$addrInfo['key']);
        if (channel_group_is_full16($addrGroup)) {
            foreach ($addrGroup as $row) {
                $subtype = (string)($row['dev_type_display'] ?? ($row['dev_type'] ?? ''));
                if (!channel_is_tc16_compatible($subtype)) {
                    throw new ChannelRuleException('ADDR group contains non TC16-compatible channels', 409, 'tc16_subtype_mismatch');
                }
            }

            return [
                'source' => $source,
                'mode' => 'addr',
                'source_key' => (string)$addrInfo['key'],
                'channels' => $addrGroup,
            ];
        }
    }

    $snInfo = channel_row_sn_info($source);
    if ($snInfo !== null) {
        $snGroup = channel_group_by_sn($rows, (string)$snInfo['sn']);
        if (channel_group_is_full16($snGroup)) {
            foreach ($snGroup as $row) {
                $subtype = (string)($row['dev_type_display'] ?? ($row['dev_type'] ?? ''));
                if (!channel_is_tc16_compatible($subtype)) {
                    throw new ChannelRuleException('SN group contains non TC16-compatible channels', 409, 'tc16_subtype_mismatch');
                }
            }

            return [
                'source' => $source,
                'mode' => 'sn',
                'source_key' => 'SN:' . (string)$snInfo['sn'],
                'channels' => $snGroup,
            ];
        }
    }

    throw new ChannelRuleException('Source TC16 group is not full CH1..CH16', 409, 'tc16_source_not_full');
}

function channel_collect_tc16_target_candidates(array $rows, array $devices, array $sourceGroup): array
{
    $usage = channel_anchor_usage_from_rows($rows);
    $sourceKey = strtoupper((string)($sourceGroup['source_key'] ?? ''));

    $items = [];
    foreach ($devices as $deviceKey => $device) {
        $key = strtoupper((string)$deviceKey);
        if ($key === $sourceKey) {
            continue;
        }

        if (!channel_is_tc16_compatible((string)($device['sdaq_type'] ?? ''))) {
            continue;
        }

        if ((int)($device['number_of_channels'] ?? 0) !== 16) {
            continue;
        }

        $allFree = true;
        for ($ch = 1; $ch <= 16; $ch++) {
            if (!channel_target_channel_is_unlinked($usage, $device, $ch)) {
                $allFree = false;
                break;
            }
        }

        if (!$allFree) {
            continue;
        }

        $items[] = [
            'device_key' => (string)$device['device_key'],
            'bus' => (string)$device['bus'],
            'address' => (int)$device['address'],
            'serial' => (string)($device['serial'] ?? ''),
            'sdaq_type' => (string)$device['sdaq_type'],
            'number_of_channels' => (int)$device['number_of_channels'],
            'available_channels' => range(1, 16),
        ];
    }

    usort($items, static function ($a, $b) {
        return strcmp((string)$a['device_key'], (string)$b['device_key']);
    });

    return $items;
}

function channel_validate_tc16_target(array $rows, array $device, array $sourceGroup): void
{
    if (!channel_is_tc16_compatible((string)($device['sdaq_type'] ?? ''))) {
        throw new ChannelRuleException('Target device subtype is not SDAQ-TC16', 409, 'tc16_subtype_mismatch');
    }

    if ((int)($device['number_of_channels'] ?? 0) !== 16) {
        throw new ChannelRuleException('Target device is not full 16-channel capable', 409, 'tc16_target_not_full');
    }

    $usage = channel_anchor_usage_from_rows($rows);
    $ignoreIsoSet = [];
    foreach ($sourceGroup['channels'] as $row) {
        $iso = strtoupper(trim((string)($row['iso_channel'] ?? '')));
        if ($iso !== '') {
            $ignoreIsoSet[$iso] = true;
        }
    }

    for ($ch = 1; $ch <= 16; $ch++) {
        if (!channel_target_channel_is_unlinked($usage, $device, $ch, $ignoreIsoSet)) {
            throw new ChannelRuleException('Target device has linked channels in CH1..CH16', 409, 'tc16_target_not_unlinked');
        }
    }
}

function channel_build_tc16_anchor_updates(array $sourceGroup, array $targetDevice): array
{
    if (!channel_group_is_full16($sourceGroup['channels'] ?? [])) {
        throw new ChannelRuleException('Source group is incomplete', 409, 'tc16_source_not_full');
    }

    $updates = [];
    for ($ch = 1; $ch <= 16; $ch++) {
        $row = $sourceGroup['channels'][$ch] ?? null;
        if (!is_array($row)) {
            throw new ChannelRuleException('Source group is incomplete', 409, 'tc16_source_not_full');
        }
        $iso = (string)($row['iso_channel'] ?? '');
        if ($iso === '') {
            throw new ChannelRuleException('Invalid source ISO in TC16 group', 409, 'tc16_source_unresolvable');
        }
        $updates[$iso] = channel_target_anchor_for_channel($targetDevice, $ch);
    }

    return $updates;
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

        if (!isset($devices[$targetKey])) {
            throw new ChannelRuleException('Target device not found', 409, 'tc16_target_not_found');
        }

        $targetDevice = $devices[$targetKey];
        channel_validate_tc16_target($rows, $targetDevice, $sourceGroup);

        $updates = channel_build_tc16_anchor_updates($sourceGroup, $targetDevice);
        if (count($updates) !== 16) {
            throw new ChannelRuleException('TC16 replace payload must contain 16 channels', 409, 'tc16_apply_conflict');
        }

        iso_batch_update_anchors($xmlPath, $updates);

        echo json_encode([
            'ok' => true,
            'data' => [
                'replaced_count' => count($updates),
                'source_key' => (string)$sourceGroup['source_key'],
                'target_key' => (string)$targetDevice['device_key'],
            ],
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
                iso_add_channel($xmlPath, $data);
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

            try {
                iso_update_channel($xmlPath, $iso, $data);
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
