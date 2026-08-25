<?php
/* Local JSON Restore reports all rows, revalidates under lock, then commits all or none. */

require __DIR__ . '/../../backend/services/channel_restore_service.php';

$g_checks = 0;
$g_failures = 0;

function check(bool $cond, string $msg): void
{
    global $g_checks, $g_failures;
    $g_checks++;
    if ($cond) {
        echo "PASS: $msg\n";
    } else {
        $g_failures++;
        echo "FAIL: $msg\n";
    }
}

function make_tmp_dir(string $prefix): string
{
    $dir = sys_get_temp_dir() . '/' . $prefix . '_' . uniqid();
    mkdir($dir, 0700, true);
    return $dir;
}

function write_xml(string $dir, string $channelsXml): string
{
    $path = $dir . '/OPC_UA_Config.xml';
    file_put_contents($path, "<?xml version=\"1.0\"?>\n<NODESet>\n$channelsXml\n</NODESet>\n");
    return $path;
}

function write_log_config(string $dir, array $handlers): string
{
    // handlers: [['type'=>'IOBOX'|'MTI', 'name'=>..., 'ip'=>...], ...]
    $comps = '';
    foreach ($handlers as $h) {
        $tag = strtoupper($h['type']) . '_HANDLER';
        $comps .= "<$tag><DEV_NAME>{$h['name']}</DEV_NAME><IPv4_ADDR>{$h['ip']}</IPv4_ADDR></$tag>\n";
    }
    $path = $dir . '/Morfeas_config.xml';
    file_put_contents($path, "<?xml version=\"1.0\"?>\n<NODESet><COMPONENTS>\n$comps</COMPONENTS></NODESet>\n");
    return $path;
}

function channel_xml(string $iso, string $type, string $anchor, string $desc = 'd', string $min = '0', string $max = '1', ?string $unit = null): string
{
    $unitTag = $unit !== null ? "<UNIT>$unit</UNIT>" : '';
    return "<CHANNEL><ISO_CHANNEL>$iso</ISO_CHANNEL><INTERFACE_TYPE>$type</INTERFACE_TYPE><ANCHOR>$anchor</ANCHOR><DESCRIPTION>$desc</DESCRIPTION><MIN>$min</MIN><MAX>$max</MAX>$unitTag</CHANNEL>";
}

function legacy_entry(string $iso, string $type, string $anchor, array $extra = []): array
{
    return array_merge([
        'ISO_CHANNEL' => $iso,
        'INTERFACE_TYPE' => $type,
        'ANCHOR' => $anchor,
        'DESCRIPTION' => 'd',
        'MIN' => '0',
        'MAX' => '1',
    ], $extra);
}

function written_channels(string $xmlPath): array
{
    $xml = simplexml_load_file($xmlPath);
    $out = [];
    foreach ($xml->CHANNEL as $ch) {
        $out[(string)$ch->ISO_CHANNEL] = [
            'anchor' => (string)$ch->ANCHOR,
            'description' => (string)$ch->DESCRIPTION,
            'min' => (string)$ch->MIN,
            'max' => (string)$ch->MAX,
            'unit' => (string)$ch->UNIT,
        ];
    }
    return $out;
}

// ============================================================
// 1) Container-level parsing
// ============================================================
try {
    restore_parse_legacy_json('not json');
    check(false, 'Invalid JSON text must throw');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'invalid_json', 'Invalid JSON text rejects with invalid_json (got ' . $e->apiCode() . ')');
}
try {
    restore_parse_legacy_json('{"not":"an array"}');
    check(false, 'A JSON object (not array) must throw');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'invalid_container', 'Non-array JSON rejects with invalid_container (got ' . $e->apiCode() . ')');
}
check(restore_parse_legacy_json('[]') === [], 'An empty JSON array parses to an empty list');

// 2) IPv4 -> Core identifier byte order.
check(restore_ipv4_to_core_identifier('192.168.234.141') === 2380966080, 'IPv4->identifier matches Core byte order (192.168.234.141 -> 2380966080)');
check(restore_ipv4_to_core_identifier('not-an-ip') === null, 'IPv4->identifier returns null for a malformed IP');

// ============================================================
// 3) Grammar / required-field / interface validation (via restore_classify_entries)
// ============================================================
$dir3 = make_tmp_dir('restore_test');
$xmlPath3 = write_xml($dir3, '');
$logCfg3 = write_log_config($dir3, []);

function classify_one(string $xmlPath, string $logCfg, array $entry): array
{
    $rows = restore_classify_entries([$entry], iso_load_channels($xmlPath), restore_load_device_identifiers($logCfg));
    return $rows[0];
}

$r = classify_one($xmlPath3, $logCfg3, ['ISO_CHANNEL' => '_A', 'INTERFACE_TYPE' => 'SDAQ']); // missing ANCHOR/DESCRIPTION/MIN/MAX
check($r['result'] === 'Invalid entry' && $r['code'] === 'missing_field', 'Missing required fields -> Invalid entry / missing_field (got ' . $r['result'] . '/' . $r['code'] . ')');

$r = classify_one($xmlPath3, $logCfg3, legacy_entry('_A', 'CPAD', '1.CH1'));
check($r['result'] === 'Invalid entry' && $r['code'] === 'unsupported_interface', 'Unsupported interface -> unsupported_interface (got ' . $r['code'] . ')');

$r = classify_one($xmlPath3, $logCfg3, legacy_entry('_A', 'SDAQ', 'CAN1.ADDR:05.CH:01'));
check($r['result'] === 'Invalid entry' && $r['code'] === 'invalid_anchor', 'Address-style SDAQ anchor (the incident anchor) -> invalid_anchor (got ' . $r['code'] . ')');

$r = classify_one($xmlPath3, $logCfg3, legacy_entry('_A', 'IOBOX', '117440522.RX1.CH1')); // no UNIT
check($r['result'] === 'Invalid entry' && $r['code'] === 'missing_required_unit', 'IOBOX with no UNIT -> missing_required_unit (got ' . $r['code'] . ')');

$r = classify_one($xmlPath3, $logCfg3, legacy_entry('_A', 'SDAQ', '117440522.CH1')); // SDAQ never needs UNIT
check($r['result'] === 'Ready to restore', 'SDAQ entry with no UNIT is fine (Unit is runtime-owned, not restorable) (got ' . $r['result'] . ')');

// Preflight and Commit must agree on required metadata.
$r = classify_one($xmlPath3, $logCfg3, legacy_entry('_A', 'SDAQ', '117440522.CH1', ['DESCRIPTION' => '']));
check($r['result'] === 'Invalid entry' && $r['code'] === 'missing_field', 'Empty DESCRIPTION -> Invalid entry / missing_field at preflight, not just at commit (got ' . $r['result'] . '/' . $r['code'] . ')');

$r = classify_one($xmlPath3, $logCfg3, legacy_entry('_A', 'SDAQ', '117440522.CH1', ['MIN' => '']));
check($r['result'] === 'Invalid entry' && $r['code'] === 'missing_field', 'Empty MIN -> Invalid entry / missing_field at preflight (got ' . $r['result'] . '/' . $r['code'] . ')');

$r = classify_one($xmlPath3, $logCfg3, legacy_entry('_A', 'SDAQ', '117440522.CH1', ['MAX' => '']));
check($r['result'] === 'Invalid entry' && $r['code'] === 'missing_field', 'Empty MAX -> Invalid entry / missing_field at preflight (got ' . $r['result'] . '/' . $r['code'] . ')');

$r = classify_one($xmlPath3, $logCfg3, legacy_entry('_AAAAAAAAAAAAAAAAAAA', 'SDAQ', '117440522.CH1')); // 20 bytes, at ISO_channel_name_size
check($r['result'] === 'Invalid entry' && $r['code'] === 'invalid_iso_channel', 'ISO_CHANNEL >= 20 bytes -> Invalid entry / invalid_iso_channel at preflight (got ' . $r['result'] . '/' . $r['code'] . ')');

$r = classify_one($xmlPath3, $logCfg3, legacy_entry('_Bad.Name', 'SDAQ', '117440522.CH1'));
check($r['result'] === 'Invalid entry' && $r['code'] === 'invalid_iso_channel', 'ISO_CHANNEL containing "." -> Invalid entry / invalid_iso_channel at preflight (got ' . $r['result'] . '/' . $r['code'] . ')');

// ============================================================
// 4) IOBOX/MTI cross-file device-handler matching
// ============================================================
$dir4 = make_tmp_dir('restore_test');
$xmlPath4 = write_xml($dir4, '');
$logCfg4 = write_log_config($dir4, [
    ['type' => 'IOBOX', 'name' => 'IOBOX1', 'ip' => '192.168.234.141'], // -> identifier 2380966080
]);

$r = classify_one($xmlPath4, $logCfg4, legacy_entry('_A', 'IOBOX', '2380966080.RX1.CH1', ['UNIT' => 'C']));
check($r['result'] === 'Ready to restore', 'IOBOX row whose identifier matches a configured IOBOX handler is restorable (got ' . $r['result'] . '/' . ($r['code'] ?? '') . ')');

$r = classify_one($xmlPath4, $logCfg4, legacy_entry('_A', 'IOBOX', '999999999.RX1.CH1', ['UNIT' => 'C'])); // no handler with this identifier
check($r['result'] === 'Invalid entry' && $r['code'] === 'orphan_device_source', 'IOBOX row with no matching handler -> orphan_device_source (got ' . $r['code'] . ')');

$r = classify_one($xmlPath4, $logCfg4, legacy_entry('_A', 'MTI', '2380966080.TC16.CH1', ['UNIT' => 'C'])); // identifier belongs to an IOBOX handler, not MTI
check($r['result'] === 'Invalid entry' && $r['code'] === 'device_source_type_mismatch', 'MTI row whose identifier matches an IOBOX (not MTI) handler -> device_source_type_mismatch (got ' . $r['code'] . ')');

// ============================================================
// 5) Six-way duplicate/conflict matrix against the existing config
// ============================================================
$dir5 = make_tmp_dir('restore_test');
$xmlPath5 = write_xml($dir5, implode("\n", [
    channel_xml('_Existing_A', 'SDAQ', '111111111.CH1', 'orig desc', '0', '100'),
    channel_xml('_Existing_B', 'SDAQ', '222222222.CH1'),
]));
$logCfg5 = write_log_config($dir5, []);

// 5a) Neither ISO nor source exists -> Ready to restore.
$r = classify_one($xmlPath5, $logCfg5, legacy_entry('_Brand_New', 'SDAQ', '333333333.CH1'));
check($r['result'] === 'Ready to restore', '5a: new ISO + new source -> Ready to restore (got ' . $r['result'] . ')');

// 5b) Same ISO + same source + all recoverable fields identical -> No change.
$r = classify_one($xmlPath5, $logCfg5, legacy_entry('_Existing_A', 'SDAQ', '111111111.CH1', ['DESCRIPTION' => 'orig desc', 'MIN' => '0', 'MAX' => '100']));
check($r['result'] === 'No change', '5b: same ISO + same source + identical fields -> No change (got ' . $r['result'] . ')');

// 5c) Same ISO + same source + a mutable field differs -> Update metadata.
$r = classify_one($xmlPath5, $logCfg5, legacy_entry('_Existing_A', 'SDAQ', '111111111.CH1', ['DESCRIPTION' => 'NEW DESC', 'MIN' => '0', 'MAX' => '100']));
check($r['result'] === 'Update metadata', '5c: same ISO + same source + different description -> Update metadata (got ' . $r['result'] . ')');

// 5d) Same ISO, different source -> Conflict.
$r = classify_one($xmlPath5, $logCfg5, legacy_entry('_Existing_A', 'SDAQ', '999999999.CH1'));
check($r['result'] === 'Conflict with current config', '5d: same ISO, different source -> Conflict (got ' . $r['result'] . ')');

// 5e) Different ISO, same source -> Conflict.
$r = classify_one($xmlPath5, $logCfg5, legacy_entry('_Different_Name', 'SDAQ', '111111111.CH1'));
check($r['result'] === 'Conflict with current config', '5e: different ISO, same source -> Conflict (got ' . $r['result'] . ')');

// 5f) Within-file duplicates.
$rows = restore_classify_entries(
    [legacy_entry('_Dup', 'SDAQ', '444444444.CH1'), legacy_entry('_Dup', 'SDAQ', '555555555.CH1')],
    iso_load_channels($xmlPath5),
    restore_load_device_identifiers($logCfg5)
);
check($rows[0]['result'] === 'Ready to restore', '5f: first of a within-file ISO duplicate pair is fine on its own');
check($rows[1]['result'] === 'Invalid entry' && $rows[1]['code'] === 'channel_conflict', '5f: second row with duplicate ISO_CHANNEL within the file -> channel_conflict (got ' . $rows[1]['code'] . ')');

$rows = restore_classify_entries(
    [legacy_entry('_Src_A', 'SDAQ', '666666666.CH1'), legacy_entry('_Src_B', 'SDAQ', '666666666.CH1')],
    iso_load_channels($xmlPath5),
    restore_load_device_identifiers($logCfg5)
);
check($rows[1]['result'] === 'Invalid entry' && $rows[1]['code'] === 'duplicate_source', '5f: second row with duplicate source (different ISO) within the file -> duplicate_source (got ' . $rows[1]['code'] . ')');

// ============================================================
// 6) Full preflight + commit round trip
// ============================================================
$dir6 = make_tmp_dir('restore_test');
$xmlPath6 = write_xml($dir6, channel_xml('_Existing', 'SDAQ', '700000001.CH1', 'keep me', '0', '1'));
$logCfg6 = write_log_config($dir6, []);

$fileContent6 = json_encode([
    legacy_entry('_New_1', 'SDAQ', '700000002.CH1'),
    legacy_entry('_New_2', 'SDAQ', '700000003.CH1'),
    legacy_entry('_Existing', 'SDAQ', '700000001.CH1', ['DESCRIPTION' => 'keep me']), // No change
]);

$preflight = restore_preflight($xmlPath6, $logCfg6, $fileContent6);
check($preflight['can_commit'] === true, '6: happy-path preflight reports can_commit=true');
check($preflight['summary']['ready_to_restore'] === 2, '6: preflight summary counts 2 Ready to restore (got ' . $preflight['summary']['ready_to_restore'] . ')');
check($preflight['summary']['no_change'] === 1, '6: preflight summary counts 1 No change (got ' . $preflight['summary']['no_change'] . ')');

$result = restore_commit($xmlPath6, $logCfg6, $fileContent6, $preflight['digest']);
check($result['added'] === 2, '6: commit reports added=2 (got ' . $result['added'] . ')');
check($result['unchanged'] === 1, '6: commit reports unchanged=1 (got ' . $result['unchanged'] . ')');
$written = written_channels($xmlPath6);
check(count($written) === 3, '6: file now has exactly 3 channels (1 pre-existing + 2 restored) (got ' . count($written) . ')');
check(($written['_New_1']['anchor'] ?? null) === '700000002.CH1', '6: _New_1 persisted with its canonical anchor');
check(($written['_Existing']['description'] ?? null) === 'keep me', '6: No-change row left untouched');

// Legacy JSON and existing Legacy XML may both carry SDAQ UNIT. Restore
// accepts those historical files, but canonicalises every SDAQ row it
// creates or updates by omitting UNIT because Core reads runtime metadata.
$dir6b = make_tmp_dir('restore_sdaq_unit_test');
$legacyExistingRow = channel_xml('_Legacy_Existing', 'SDAQ', '710000001.CH1', 'old', '0', '1', 'legacy-C');
$legacyExistingRow = str_replace(
    '<UNIT>legacy-C</UNIT>',
    '<UNIT>legacy-C</UNIT><CAL_DATE>2020/01/01</CAL_DATE><CAL_PERIOD>12</CAL_PERIOD>',
    $legacyExistingRow
);
$xmlPath6b = write_xml($dir6b, $legacyExistingRow);
$logCfg6b = write_log_config($dir6b, []);
$fileContent6b = json_encode([
    legacy_entry('_Legacy_New', 'SDAQ', '710000002.CH1', [
        'UNIT' => 'legacy-F',
        'CAL_DATE' => '2021/02/03',
        'CAL_PERIOD' => '24',
    ]),
    legacy_entry('_Legacy_Existing', 'SDAQ', '710000001.CH1', ['DESCRIPTION' => 'updated', 'UNIT' => 'legacy-K']),
]);
$preflight6b = restore_preflight($xmlPath6b, $logCfg6b, $fileContent6b);
check($preflight6b['can_commit'] === true, '6b: Legacy SDAQ UNIT does not make Local JSON Restore invalid');
check(
    ($preflight6b['rows'][0]['ignored_fields'] ?? []) === ['UNIT', 'CAL_DATE', 'CAL_PERIOD'],
    '6b: preflight explicitly reports Legacy SDAQ Unit/calibration as ignored runtime-owned metadata'
);
restore_commit($xmlPath6b, $logCfg6b, $fileContent6b, $preflight6b['digest']);
$xml6b = simplexml_load_file($xmlPath6b);
$units6b = [];
foreach ($xml6b->CHANNEL as $ch) {
    $units6b[(string)$ch->ISO_CHANNEL] = isset($ch->UNIT);
}
check(($units6b['_Legacy_New'] ?? true) === false, '6b: new restored SDAQ omits historical UNIT');
check(($units6b['_Legacy_Existing'] ?? true) === false, '6b: updated existing SDAQ removes historical XML UNIT');

$legacyExisting = null;
foreach ($xml6b->CHANNEL as $ch) {
    if ((string)$ch->ISO_CHANNEL === '_Legacy_Existing') {
        $legacyExisting = $ch;
        break;
    }
}
check(
    $legacyExisting !== null && !isset($legacyExisting->CAL_DATE) && !isset($legacyExisting->CAL_PERIOD),
    '6b: updated existing SDAQ also removes historical XML calibration fields'
);

// ============================================================
// 7) Commit is atomic: any invalid/conflicting row rejects the WHOLE batch,
//    including otherwise-valid rows, with zero writes.
// ============================================================
$dir7 = make_tmp_dir('restore_test');
$xmlPath7 = write_xml($dir7, channel_xml('_Existing', 'SDAQ', '700000010.CH1'));
$logCfg7 = write_log_config($dir7, []);

$fileContent7 = json_encode([
    legacy_entry('_Valid_New', 'SDAQ', '700000011.CH1'), // fine on its own
    legacy_entry('_Existing', 'SDAQ', '700000099.CH1'),  // conflicts: same ISO, different source
]);
$preflight7 = restore_preflight($xmlPath7, $logCfg7, $fileContent7);
check($preflight7['can_commit'] === false, '7: preflight with one conflicting row reports can_commit=false');

$beforeHash7 = sha1_file($xmlPath7);
try {
    restore_commit($xmlPath7, $logCfg7, $fileContent7, $preflight7['digest']);
    check(false, 'Commit with a conflicting row must throw');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'duplicate_source', 'Commit rejects the whole batch with duplicate_source (got ' . $e->apiCode() . ')');
}
check(sha1_file($xmlPath7) === $beforeHash7, 'XML is byte-for-byte unchanged after a rejected commit');
check(!isset(written_channels($xmlPath7)['_Valid_New']), 'The otherwise-valid row was NOT written either (all-or-nothing, not "imported N but some failed")');

// ============================================================
// 8) Digest mismatch: config changed between preflight and commit.
// ============================================================
$dir8 = make_tmp_dir('restore_test');
$xmlPath8 = write_xml($dir8, channel_xml('_Existing', 'SDAQ', '700000020.CH1'));
$logCfg8 = write_log_config($dir8, []);
$fileContent8 = json_encode([legacy_entry('_New', 'SDAQ', '700000021.CH1')]);

$preflight8 = restore_preflight($xmlPath8, $logCfg8, $fileContent8);
// Simulate a concurrent unrelated write between preflight and commit.
file_put_contents($xmlPath8, str_replace('</NODESet>', channel_xml('_Concurrent', 'SDAQ', '700000099.CH1') . "\n</NODESet>", file_get_contents($xmlPath8)));

try {
    restore_commit($xmlPath8, $logCfg8, $fileContent8, $preflight8['digest']);
    check(false, 'Commit with a stale digest must throw');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'restore_candidate_changed', 'Commit with a stale digest rejects with restore_candidate_changed (got ' . $e->apiCode() . ')');
}

// ============================================================
// 9) Empty file / no entries.
// ============================================================
$dir9 = make_tmp_dir('restore_test');
$xmlPath9 = write_xml($dir9, '');
$logCfg9 = write_log_config($dir9, []);
$preflight9 = restore_preflight($xmlPath9, $logCfg9, '[]');
check($preflight9['can_commit'] === false, 'An empty file cannot be committed (nothing to restore)');
try {
    restore_commit($xmlPath9, $logCfg9, '[]', $preflight9['digest']);
    check(false, 'Commit of an empty entry list must throw');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'missing_field', 'Commit of an empty entry list rejects with missing_field (got ' . $e->apiCode() . ')');
}

echo "\n{$g_checks} checks, " . ($g_checks - $g_failures) . " passed, {$g_failures} failed\n";
exit($g_failures === 0 ? 0 : 1);
