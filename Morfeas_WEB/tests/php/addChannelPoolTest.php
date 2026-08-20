<?php
/*
 * tests/php/addChannelPoolTest.php
 *
 * Regression test for the Phase B1 generalization of the incident fix:
 * channel_add_channel_from_pool() (formerly channel_add_sdaq_from_pool(),
 * SDAQ-only) now enforces the same live-Unlinked-pool authorization for
 * IOBOX, MTI and NOX that channel_add_sdaq_from_pool() already enforced for
 * SDAQ. Before this change, api_channels.php routed non-SDAQ Add POSTs
 * through iso_add_channel(), which trusted the client-submitted anchor
 * directly (only checking for a duplicate ISO_CHANNEL/ANCHOR conflict, not
 * whether the target was ever actually detected). Per the fix plan, section
 * 10.0.1 / Phase A1: "所有 interface 都不信任浏览器提交的 raw/display anchor" --
 * this must hold for every family reachable through this endpoint, not just
 * SDAQ.
 *
 * This does not require physical IOBOX/MTI/NOX hardware: it constructs the
 * same shape of logstat_*.json fixtures the real producer processes would
 * write, exactly like replaceCandidateTest.php already does for its IOBOX
 * scenarios.
 *
 * Run: php tests/php/addChannelPoolTest.php   (from Morfeas_WEB/)
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

$dir = make_tmp_dir('add_pool_test');

// --- Fixtures: one detected IOBOX, one detected MTI (TC16), one detected NOX sensor. ---
$ioboxJson = $dir . '/logstat_IOBOX_iobox1.json';
file_put_contents($ioboxJson, json_encode([
    'Identifier' => '555555555',
    'IPv4_address' => '7.2.2.10',
    'Connection_status' => 'Okay',
    'RX1' => ['1' => 23.5, 'Status' => 1, 'Success' => 98],
]));

$mtiJson = $dir . '/logstat_MTI_mti1.json';
file_put_contents($mtiJson, json_encode([
    'Identifier' => '222222',
    'IPv4_address' => '7.2.2.20',
    'Connection_status' => 'Okay',
    'MTI_status' => ['Tele_Device_type' => 'Tele_TC16'],
    'Tele_data' => ['IsValid' => true, 'CHs' => [12.3, 45.6]],
]));

$noxJson = $dir . '/logstat_NOX_nox1.json';
file_put_contents($noxJson, json_encode([
    'CANBus_interface' => 'CAN2',
    'NOx_sensors' => [[
        'addr' => 1, // decode_nox_anchor() in Core only ever accepts address 0 or 1; see rejection test below for address 3
        'NOx_value_avg' => 12.3,
        'O2_value_avg' => 4.5,
        'status' => ['is_NOx_value_valid' => true, 'is_O2_value_valid' => true],
    ]],
]));

// A NOX sensor logstat reporting an address Core's decode_nox_anchor() would
// never accept (only 0/1 are valid). The pool-building code in
// logstat_nox.php/channel_service.php has no such range check itself, so
// before this round's iso_require_valid_source_identity() gate, this could
// be written to XML and only rejected by Core on the next hot reload.
$noxJsonOutOfRange = $dir . '/logstat_NOX_nox2.json';
file_put_contents($noxJsonOutOfRange, json_encode([
    'CANBus_interface' => 'CAN3',
    'NOx_sensors' => [[
        'addr' => 3,
        'NOx_value_avg' => 8.0,
        'O2_value_avg' => 2.0,
        'status' => ['is_NOx_value_valid' => true, 'is_O2_value_valid' => true],
    ]],
]));

$xmlPath = $dir . '/OPC_UA_Config.xml';
file_put_contents($xmlPath, "<?xml version=\"1.0\"?>\n<NODESet>\n</NODESet>\n");

// Since F-16, an IOBOX/MTI Add also has to find a matching handler in
// Morfeas_Config.xml, so the fixture needs the static side of the pair. The
// IPs are the ones that map to the logstat Identifiers above under Core's
// little-endian byte order (restore_ipv4_to_core_identifier()):
// 227.26.29.33 -> 555555555 and 14.100.3.0 -> 222222. They do not match the
// IPv4_address fields in the logstat fixtures, and that is fine -- Core
// derives the channel identifier from the Identifier field, which is what
// the anchor carries and what the handler lookup compares.
$logConfigPath = $dir . '/Morfeas_config.xml';
$logConfigWithBothHandlers = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE CONFIG SYSTEM "Morfeas.dtd">
<CONFIG>
  <CONFIGS_DIR>/home/morfeas/configuration</CONFIGS_DIR>
  <LOGGERS_DIR>/mnt/ramdisk/Morfeas_Loggers/</LOGGERS_DIR>
  <LOGSTAT_DIR>/mnt/ramdisk/</LOGSTAT_DIR>
  <COMPONENTS>
    <OPC_UA_SERVER>
      <APP_NAME>Morfeas_Default_app_32</APP_NAME>
    </OPC_UA_SERVER>
    <IOBOX_HANDLER Disable="false">
      <DEV_NAME>IOBox-A</DEV_NAME>
      <IPv4_ADDR>227.26.29.33</IPv4_ADDR>
    </IOBOX_HANDLER>
    <MTI_HANDLER Disable="false">
      <DEV_NAME>MTI-A</DEV_NAME>
      <IPv4_ADDR>14.100.3.0</IPv4_ADDR>
    </MTI_HANDLER>
  </COMPONENTS>
</CONFIG>
XML;
file_put_contents($logConfigPath, $logConfigWithBothHandlers);

function add_payload(string $type, string $iso, string $anchor): array
{
    $payload = ['iso_channel' => $iso, 'interface_type' => $type, 'anchor' => $anchor, 'description' => 'd', 'min' => '0', 'max' => '1'];
    // IOBOX/MTI/NOX own UNIT statically from the XML (plan §6.0.2, C-7);
    // since Phase A4 (2026-08-19) the write-time whole-document gate
    // enforces this on every Add, so every non-SDAQ fixture here needs one.
    // SDAQ's Unit is runtime-owned and must not be set here.
    if ($type !== 'SDAQ') {
        $payload['unit'] = 'C';
    }
    return $payload;
}

function written_anchor(string $xmlPath, string $iso): ?string
{
    $xml = simplexml_load_file($xmlPath);
    foreach ($xml->CHANNEL as $ch) {
        if ((string)$ch->ISO_CHANNEL === $iso) {
            return (string)$ch->ANCHOR;
        }
    }
    return null;
}

// --- 1) IOBOX Add: client submits a non-canonical (lowercase) text; the
//        persisted ANCHOR must be the pool's own canonical form. ---
channel_add_channel_from_pool(
    $xmlPath,
    $logConfigPath,
    add_payload('IOBOX', '_IOBOX_A', '555555555.rx1.ch1'),
    [], [$ioboxJson], [], [], []
);
check(written_anchor($xmlPath, '_IOBOX_A') === '555555555.RX1.CH1', 'IOBOX Add persists the pool\'s canonical anchor, not the client\'s literal text (got ' . var_export(written_anchor($xmlPath, '_IOBOX_A'), true) . ')');

// --- 2) IOBOX Add: a fabricated anchor that matches no current candidate
//        must be rejected, not written verbatim (this is the same incident
//        entry point as SDAQ's address fallback, just for IOBOX). ---
$beforeHash = sha1_file($xmlPath);
try {
    channel_add_channel_from_pool(
        $xmlPath,
        $logConfigPath,
        add_payload('IOBOX', '_IOBOX_Fake', '999999999.RX1.CH1'),
        [], [$ioboxJson], [], [], []
    );
    check(false, 'IOBOX Add of a fabricated anchor must throw');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'candidate_not_available', 'IOBOX Add rejects a fabricated anchor with candidate_not_available (got ' . $e->apiCode() . ')');
}
check(sha1_file($xmlPath) === $beforeHash, 'XML file is unchanged after rejecting a fabricated IOBOX Add');

// --- 3) IOBOX Add: the now-linked candidate cannot be Add-ed a second time. ---
try {
    channel_add_channel_from_pool(
        $xmlPath,
        $logConfigPath,
        add_payload('IOBOX', '_IOBOX_B', '555555555.RX1.CH1'),
        [], [$ioboxJson], [], [], []
    );
    check(false, 'IOBOX Add of an already-linked candidate must throw');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'candidate_not_available', 'IOBOX Add rejects an already-linked candidate with candidate_not_available (got ' . $e->apiCode() . ')');
}

// --- 4) Cross-family: a real, currently-available IOBOX anchor must not be
//        Add-able by requesting a different interface_type. ---
try {
    channel_add_channel_from_pool(
        $xmlPath,
        $logConfigPath,
        add_payload('MTI', '_Wrong_Family', '555555555.RX1.Status'),
        [], [$ioboxJson], [], [], []
    );
    check(false, 'Add of an IOBOX anchor under interface_type=MTI must throw');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'candidate_not_available', 'Add rejects a real candidate requested under the wrong family (got ' . $e->apiCode() . ')');
}

// --- 5) MTI Add: canonical anchor persisted. ---
channel_add_channel_from_pool(
    $xmlPath,
    $logConfigPath,
    add_payload('MTI', '_MTI_A', '222222.tc16.ch1'),
    [], [], [$mtiJson], [], []
);
check(written_anchor($xmlPath, '_MTI_A') === '222222.TC16.CH1', 'MTI Add persists the pool\'s canonical anchor (got ' . var_export(written_anchor($xmlPath, '_MTI_A'), true) . ')');

// --- 6) NOX Add: client submits the legacy CAN-address-style alias; the
//        persisted ANCHOR must be the canonical addr_ form, and the O2
//        measurement on the same physical sensor must remain a distinct,
//        independently Add-able candidate (not merged into one source). ---
channel_add_channel_from_pool(
    $xmlPath,
    $logConfigPath,
    add_payload('NOX', '_NOX_NOx', 'CAN2.ADDR:1.NOx'),
    [], [], [], [$noxJson], []
);
check(written_anchor($xmlPath, '_NOX_NOx') === 'can2.addr_1.NOx', 'NOX Add persists the canonical addr_ form, not the ADDR: alias (got ' . var_export(written_anchor($xmlPath, '_NOX_NOx'), true) . ')');

channel_add_channel_from_pool(
    $xmlPath,
    $logConfigPath,
    add_payload('NOX', '_NOX_O2', 'can2.addr_1.O2'),
    [], [], [], [$noxJson], []
);
check(written_anchor($xmlPath, '_NOX_O2') === 'can2.addr_1.O2', 'NOX Add of the O2 measurement on the same sensor succeeds as a distinct source (got ' . var_export(written_anchor($xmlPath, '_NOX_O2'), true) . ')');

// --- 7) NOX Add: an address Core's decode_nox_anchor() would never accept
//        (only 0/1 are valid) must be rejected at write time, not silently
//        written and left for Core to reject on the next hot reload. The
//        candidate-pool builder itself has no address-range check (it just
//        formats whatever the logstat producer reports), so this out-of-range
//        candidate is actually found and resolved -- it's only
//        iso_require_valid_source_identity() inside iso_add_channel_body(),
//        this round's new gate, that catches it. That's the point: this is
//        exactly the class of Web/Core drift the unified identity parser
//        exists to close, and the pool layer alone does not close it. ---
$beforeNoxHash = sha1_file($xmlPath);
try {
    channel_add_channel_from_pool(
        $xmlPath,
        $logConfigPath,
        add_payload('NOX', '_NOX_OutOfRange', 'can3.addr_3.NOx'),
        [], [], [], [$noxJsonOutOfRange], []
    );
    check(false, 'NOX Add of an out-of-range address (3) must throw');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'invalid_anchor', 'NOX Add of an out-of-range address is rejected by the write-time grammar gate with invalid_anchor (got ' . $e->apiCode() . ')');
}
check(sha1_file($xmlPath) === $beforeNoxHash, 'XML file is unchanged after rejecting an out-of-range NOX address');

// --- 8) Missing interface_type must be a clean 400, not a crash/notice. ---
try {
    channel_add_channel_from_pool(
        $xmlPath,
        $logConfigPath,
        ['iso_channel' => '_No_Type', 'interface_type' => '', 'anchor' => '555555555.RX1.CH1', 'description' => 'd', 'min' => '0', 'max' => '1'],
        [], [$ioboxJson], [], [], []
    );
    check(false, 'Add with an empty interface_type must throw');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'missing_field', 'Add rejects an empty interface_type with missing_field (got ' . $e->apiCode() . ')');
}

// =====================================================================
// F-16 (plan §10.0.9): an IOBOX/MTI Add must re-verify, inside the
// log_config lock, that the device still has a handler in
// Morfeas_Config.xml.
//
// The candidate pool above is built from ramdisk logstat alone. Device
// Delete deliberately does not cascade (plan §12.4) and does not clean up
// the ramdisk, so between deleting a handler and Core's next restart the
// stale logstat still advertises the device as available -- and Add would
// write an ISO channel anchored to a handler that no longer exists.
//
// This is the one scenario the 2026-08-20 hardware session could not
// construct without disturbing the live configuration, which is why it is
// pinned here as well as in the E4 hardware item.
// =====================================================================

$mtiOnly = str_replace(
    '    <IOBOX_HANDLER Disable="false">
      <DEV_NAME>IOBox-A</DEV_NAME>
      <IPv4_ADDR>227.26.29.33</IPv4_ADDR>
    </IOBOX_HANDLER>
',
    '',
    $logConfigWithBothHandlers
);
file_put_contents($logConfigPath, $mtiOnly);

$isoBefore = file_get_contents($xmlPath);
try {
    channel_add_channel_from_pool(
        $xmlPath,
        $logConfigPath,
        add_payload('IOBOX', '_IOBOX_Orphan', '555555555.RX1.Status'),
        [], [$ioboxJson], [], [], []
    );
    check(false, 'F-16: an IOBOX Add whose handler has been deleted must throw, even while its logstat is still on the ramdisk');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'orphan_device_source', 'F-16: an IOBOX Add whose handler has been deleted is refused with orphan_device_source (got ' . $e->apiCode() . ')');
}
check(file_get_contents($xmlPath) === $isoBefore, 'F-16: nothing is written to OPC_UA_Config.xml when the handler check fails');

// The same identifier configured under the WRONG handler type must not
// satisfy the check either -- restore_check_device_handler() reports that
// case separately so the operator is told what is actually wrong.
$wrongType = str_replace('MTI_HANDLER', 'IOBOX_HANDLER', $logConfigWithBothHandlers);
file_put_contents($logConfigPath, $wrongType);
try {
    channel_add_channel_from_pool(
        $xmlPath,
        $logConfigPath,
        add_payload('MTI', '_MTI_WrongType', '222222.TC16.CH2'),
        [], [], [$mtiJson], [], []
    );
    check(false, 'F-16: an MTI Add matched only by an IOBOX handler must throw');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'device_source_type_mismatch', 'F-16: an MTI Add whose identifier is configured as an IOBOX handler is refused as a type mismatch, not a generic orphan (got ' . $e->apiCode() . ')');
}

// MUST STILL PASS: with the handler restored, the same Add succeeds. This
// is what proves the check is a real gate and not a blanket rejection --
// the positive IOBOX/MTI paths at the top of this file all run against the
// full fixture, and this one runs after the removal and restore.
file_put_contents($logConfigPath, $logConfigWithBothHandlers);
channel_add_channel_from_pool(
    $xmlPath,
    $logConfigPath,
    add_payload('IOBOX', '_IOBOX_Restored', '555555555.RX1.Status'),
    [], [$ioboxJson], [], [], []
);
check(written_anchor($xmlPath, '_IOBOX_Restored') === '555555555.RX1.Status', 'F-16: the same IOBOX Add succeeds once the handler is back in Morfeas_Config.xml');

// SDAQ and NOX identity is bus-based, not handler-IP-based, so they must
// not be gated by Morfeas_Config.xml contents at all -- and must not even
// need the file to exist. A NOX Add against a deleted log config proves
// both halves at once. It runs against its own empty NODESet because the
// only NOX candidate in the fixture was linked by the tests above.
$freshXmlPath = $dir . '/OPC_UA_Config_nox.xml';
file_put_contents($freshXmlPath, "<?xml version=\"1.0\"?>\n<NODESet>\n</NODESet>\n");
unlink($logConfigPath);
channel_add_channel_from_pool(
    $freshXmlPath,
    $logConfigPath,
    add_payload('NOX', '_NOX_NoLogConfig', 'can2.addr_1.NOx'),
    [], [], [], [$noxJson], []
);
check(written_anchor($freshXmlPath, '_NOX_NoLogConfig') === 'can2.addr_1.NOx', 'F-16: a NOX Add is not gated on Morfeas_Config.xml (bus-based identity), so it succeeds even with no log config present');
file_put_contents($logConfigPath, $logConfigWithBothHandlers);

echo "\n{$g_checks} checks, " . ($g_checks - $g_failures) . " passed, {$g_failures} failed\n";
exit($g_failures === 0 ? 0 : 1);
