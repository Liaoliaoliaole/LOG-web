<?php
/*
 * tests/php/tc16ReplaceAllTest.php
 *
 * Regression test for channel_replace_tc16_from_pool() (TC16 Replace All).
 * Per the fix plan section 5.5, this closes:
 *   - the canX.address.CHn anchor fallback when a target has no serial yet
 *     (deleted -- a target without a stable serial can never be a valid
 *     TC16 target);
 *   - missing "target registration must be Done" / "all CH1..16 must be
 *     currently detected, not just declared 16-channel capable" checks;
 *   - the TOCTOU where target selection/validation ran outside the XML
 *     lock and only the final anchor write was locked (source resolution,
 *     target lookup/validation, canonical anchor generation and the final
 *     duplicate/grammar check + write must all happen inside one lock,
 *     against state re-read fresh once the lock is held);
 *   - iso_batch_update_anchors() writing anchors with no grammar or
 *     semantic-source duplicate validation at all.
 *
 * Run: php tests/php/tc16ReplaceAllTest.php   (from Morfeas_WEB/)
 */

require __DIR__ . '/../../backend/services/channel_service.php';

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

// A full offline CH1..CH16 SDAQ-TC16 source group, using serial $sourceSerial
// (never present in any logstat fixture below, so it reports OFF-Line).
function write_source_xml(string $dir, string $sourceSerial, array $extraChannelsXml = []): string
{
    $channels = '';
    for ($ch = 1; $ch <= 16; $ch++) {
        $channels .= "<CHANNEL>
    <ISO_CHANNEL>_Src$ch</ISO_CHANNEL>
    <INTERFACE_TYPE>SDAQ</INTERFACE_TYPE>
    <ANCHOR>$sourceSerial.CH$ch</ANCHOR>
    <DESCRIPTION>d</DESCRIPTION>
    <MIN>0</MIN>
    <MAX>1</MAX>
</CHANNEL>\n";
    }
    $channels .= implode("\n", $extraChannelsXml);

    $path = $dir . '/OPC_UA_Config.xml';
    file_put_contents($path, "<?xml version=\"1.0\"?>\n<NODESet>\n$channels\n</NODESet>\n");
    return $path;
}

// A registered, fully-detected SDAQ-TC16 target device.
function write_target_fixture(string $dir, string $bus, int $addr, string $serial, array $opts = []): string
{
    $registration = $opts['registration'] ?? 'Done';
    $detectedChannels = $opts['detected_channels'] ?? range(1, 16);
    $type = $opts['sdaq_type'] ?? 'SDAQ-TC16';
    $numChannels = $opts['number_of_channels'] ?? 16;

    $meas = [];
    foreach ($detectedChannels as $ch) {
        $meas[] = ['Channel' => $ch, 'CNT' => 10];
    }

    $fixture = [
        'CANBus_interface' => $bus,
        'SDAQs_data' => [[
            'Address' => $addr,
            'SDAQ_type' => $type,
            'Serial_number' => $serial,
            'SDAQ_Status' => ['Registration_status' => $registration],
            'SDAQ_info' => ['Number_of_channels' => $numChannels],
            'Meas' => $meas,
        ]],
    ];
    $path = $dir . '/logstat_SDAQ_' . strtolower($bus) . '_target.json';
    file_put_contents($path, json_encode($fixture));
    return $path;
}

function written_channels(string $xmlPath): array
{
    $xml = simplexml_load_file($xmlPath);
    $out = [];
    foreach ($xml->CHANNEL as $ch) {
        $out[(string)$ch->ISO_CHANNEL] = (string)$ch->ANCHOR;
    }
    return $out;
}

// --- 1) Happy path: valid target, all 16 channels re-anchored atomically. ---
$dir1 = make_tmp_dir('tc16_test');
$xmlPath1 = write_source_xml($dir1, '700000001');
$targetJson1 = write_target_fixture($dir1, 'CAN2', 4, '800000002');
$result = channel_replace_tc16_from_pool(
    $xmlPath1, '_Src1', 'CAN2.ADDR:04',
    [$targetJson1], [], [], [], []
);
check($result['replaced_count'] === 16, 'Happy-path replace reports replaced_count=16 (got ' . var_export($result['replaced_count'] ?? null, true) . ')');
$written1 = written_channels($xmlPath1);
$allCorrect = true;
for ($ch = 1; $ch <= 16; $ch++) {
    if (($written1["_Src$ch"] ?? null) !== "800000002.CH$ch") {
        $allCorrect = false;
    }
}
check($allCorrect, 'All 16 source channels are re-anchored to the target\'s canonical serial.CHn form');

// --- 2) Target without a serial: no canX.address.CHn fallback. Whole
//        operation rejected, zero writes. ---
$dir2 = make_tmp_dir('tc16_test');
$xmlPath2 = write_source_xml($dir2, '700000010');
$targetJson2 = write_target_fixture($dir2, 'CAN3', 5, ''); // empty serial
$beforeHash2 = sha1_file($xmlPath2);
try {
    channel_replace_tc16_from_pool($xmlPath2, '_Src1', 'CAN3.ADDR:05', [$targetJson2], [], [], [], []);
    check(false, 'A target with no serial must throw');
} catch (ChannelRuleException $e) {
    check($e->apiCode() === 'tc16_target_serial_missing', 'Target with no serial rejects with tc16_target_serial_missing (got ' . $e->apiCode() . ')');
}
check(sha1_file($xmlPath2) === $beforeHash2, 'XML unchanged after rejecting a serial-less target');

// --- 3) Target registration not Done. ---
$dir3 = make_tmp_dir('tc16_test');
$xmlPath3 = write_source_xml($dir3, '700000020');
$targetJson3 = write_target_fixture($dir3, 'CAN4', 6, '800000030', ['registration' => 'Registering']);
try {
    channel_replace_tc16_from_pool($xmlPath3, '_Src1', 'CAN4.ADDR:06', [$targetJson3], [], [], [], []);
    check(false, 'A target with registration not Done must throw');
} catch (ChannelRuleException $e) {
    check($e->apiCode() === 'tc16_target_not_registered', 'Target with registration not Done rejects with tc16_target_not_registered (got ' . $e->apiCode() . ')');
}

// --- 4) Target declares 16-channel capable but one channel isn't currently
//        detected (e.g. CH9 absent from this reading). Declared capability
//        is not the same as "all 16 are real right now". ---
$dir4 = make_tmp_dir('tc16_test');
$xmlPath4 = write_source_xml($dir4, '700000040');
$targetJson4 = write_target_fixture($dir4, 'CAN5', 7, '800000050', ['detected_channels' => array_diff(range(1, 16), [9])]);
try {
    channel_replace_tc16_from_pool($xmlPath4, '_Src1', 'CAN5.ADDR:07', [$targetJson4], [], [], [], []);
    check(false, 'A target missing one of CH1..16 in this reading must throw');
} catch (ChannelRuleException $e) {
    check($e->apiCode() === 'tc16_target_channel_not_detected', 'Target with an undetected channel rejects with tc16_target_channel_not_detected (got ' . $e->apiCode() . ')');
}

// --- 5a) A target whose generated anchor collides with an unrelated
//         existing channel is already caught earlier, by
//         channel_validate_tc16_target()'s "target channel already linked"
//         check (channel_target_channel_is_unlinked() enumerates the
//         target's own serial.CHn among its alias forms) -- confirming that
//         pre-existing check still works is worth pinning down explicitly. ---
$dir5a = make_tmp_dir('tc16_test');
$xmlPath5a = write_source_xml($dir5a, '700000060', [
    "<CHANNEL><ISO_CHANNEL>_Unrelated</ISO_CHANNEL><INTERFACE_TYPE>SDAQ</INTERFACE_TYPE><ANCHOR>800000070.CH1</ANCHOR><DESCRIPTION>d</DESCRIPTION><MIN>0</MIN><MAX>1</MAX></CHANNEL>",
]);
$targetJson5a = write_target_fixture($dir5a, 'CAN6', 8, '800000070'); // target's CH1 == the pre-existing unrelated channel's anchor
$beforeHash5a = sha1_file($xmlPath5a);
try {
    channel_replace_tc16_from_pool($xmlPath5a, '_Src1', 'CAN6.ADDR:08', [$targetJson5a], [], [], [], []);
    check(false, 'A target whose generated anchor collides with an unrelated existing channel must throw');
} catch (ChannelRuleException $e) {
    check($e->apiCode() === 'tc16_target_not_unlinked', 'Target/existing-channel collision is rejected by the pre-existing unlinked check (got ' . $e->apiCode() . ')');
}
check(sha1_file($xmlPath5a) === $beforeHash5a, 'XML is unchanged after rejecting a batch with one colliding channel (all-or-nothing, not 15-of-16)');

// --- 5b) The NEW write-layer grammar gate (iso_require_valid_source_identity(),
//         added this round) is the one that catches a class of problem
//         channel_validate_tc16_target() structurally cannot: a target whose
//         serial isn't even valid SDAQ grammar (e.g. non-numeric). Every
//         structural check (non-empty, not matching source, TC16-compatible,
//         16 channels registered+detected, not already linked) passes --
//         only the strict grammar check on the actually-generated canonical
//         anchor catches it, and it must reject the whole 16-channel batch
//         with zero writes, not produce 16 malformed ANCHOR values. ---
$dir5b = make_tmp_dir('tc16_test');
$xmlPath5b = write_source_xml($dir5b, '700000075');
$targetJson5b = write_target_fixture($dir5b, 'CAN9', 11, 'NOT-A-VALID-SERIAL');
$beforeHash5b = sha1_file($xmlPath5b);
try {
    channel_replace_tc16_from_pool($xmlPath5b, '_Src1', 'CAN9.ADDR:11', [$targetJson5b], [], [], [], []);
    check(false, 'A target with a non-numeric serial must throw at the write-layer grammar gate');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'invalid_anchor', 'Target with a non-numeric serial rejects the whole batch with invalid_anchor (got ' . $e->apiCode() . ')');
}
check(sha1_file($xmlPath5b) === $beforeHash5b, 'XML is byte-for-byte unchanged after rejecting a batch with an invalid generated anchor (all-or-nothing, not 16 malformed writes)');

// --- 6) Source device matches target serial (nonsensical self-replace).
//        Note: putting a logstat fixture with the source's own serial into
//        $sdaqLogFiles makes that serial appear online for BOTH row-status
//        computation (channel_build_rows_with_logstat(), used to resolve
//        the source's OFF-Line status) and target-capability collection
//        (channel_collect_sdaq_capabilities()) -- they read the same files.
//        So this specific combination is structurally caught by the
//        "source must be offline" check first, before
//        channel_validate_tc16_target()'s "target matches source serial"
//        check is ever reached; that dedicated check exists as
//        defense-in-depth for a source/target-derivation edge case, not one
//        reachable through self-consistent fixtures. Either way, the
//        self-replace must be rejected, which is what this asserts. ---
$dir6 = make_tmp_dir('tc16_test');
$xmlPath6 = write_source_xml($dir6, '700000080');
$targetJson6 = write_target_fixture($dir6, 'CAN1', 1, '700000080'); // same serial as source
try {
    channel_replace_tc16_from_pool($xmlPath6, '_Src1', 'CAN1.ADDR:01', [$targetJson6], [], [], [], []);
    check(false, 'A target matching the source serial must throw');
} catch (ChannelRuleException $e) {
    check(
        in_array($e->apiCode(), ['tc16_target_is_source', 'tc16_source_not_offline'], true),
        'Target matching source serial is rejected, one way or another (got ' . $e->apiCode() . ')'
    );
}

// --- 7) Non-existent source ISO. ---
$dir7 = make_tmp_dir('tc16_test');
$xmlPath7 = write_source_xml($dir7, '700000090');
$targetJson7 = write_target_fixture($dir7, 'CAN7', 9, '800000100');
try {
    channel_replace_tc16_from_pool($xmlPath7, '_Does_Not_Exist', 'CAN7.ADDR:09', [$targetJson7], [], [], [], []);
    check(false, 'A non-existent source ISO must throw');
} catch (ChannelRuleException $e) {
    check($e->apiCode() === 'tc16_source_unresolvable', 'Non-existent source ISO rejects with tc16_source_unresolvable (got ' . $e->apiCode() . ')');
}

echo "\n{$g_checks} checks, " . ($g_checks - $g_failures) . " passed, {$g_failures} failed\n";
exit($g_failures === 0 ? 0 : 1);
