<?php
/* Add writes only a canonical identity resolved from the live candidate pool. */

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
    // Non-SDAQ Unit is XML-owned; SDAQ Unit is runtime-owned.
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

// A stale logstat candidate must still have a matching configured handler.

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

// a handler that is CONFIGURED but Disable="true" is a different,
// still-rejectable problem from a missing one -- restore_check_device_handler()
// (shared with Local JSON Restore) reports device_handler_disabled, and Add
// treats that as a hard 409 the same way it already treats orphan_device_source,
// because a disabled handler cannot make a new channel work either.
$logConfigIoboxDisabled = str_replace(
    '<IOBOX_HANDLER Disable="false">',
    '<IOBOX_HANDLER Disable="true">',
    $logConfigWithBothHandlers
);
file_put_contents($logConfigPath, $logConfigIoboxDisabled);
$isoBeforeIoboxDisabled = file_get_contents($xmlPath);
try {
    channel_add_channel_from_pool(
        $xmlPath,
        $logConfigPath,
        add_payload('IOBOX', '_IOBOX_Disabled', '555555555.RX1.Success'),
        [], [$ioboxJson], [], [], []
    );
    check(false, 'P2: an IOBOX Add whose handler is Disable="true" must throw');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'device_handler_disabled', 'P2: IOBOX Add against a disabled handler is refused with device_handler_disabled (got ' . $e->apiCode() . ')');
    check($e->status() === 409, 'P2: IOBOX Add against a disabled handler uses HTTP 409 (got ' . $e->status() . ')');
}
check(file_get_contents($xmlPath) === $isoBeforeIoboxDisabled, 'P2: nothing is written to OPC_UA_Config.xml when the handler is disabled');

$logConfigMtiDisabled = str_replace(
    '<MTI_HANDLER Disable="false">',
    '<MTI_HANDLER Disable="true">',
    $logConfigWithBothHandlers
);
file_put_contents($logConfigPath, $logConfigMtiDisabled);
$isoBeforeMtiDisabled = file_get_contents($xmlPath);
try {
    channel_add_channel_from_pool(
        $xmlPath,
        $logConfigPath,
        add_payload('MTI', '_MTI_Disabled', '222222.TC16.CH2'),
        [], [], [$mtiJson], [], []
    );
    check(false, 'P2: an MTI Add whose handler is Disable="true" must throw (guards the MTI branch, not just IOBOX, against type-check drift)');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'device_handler_disabled', 'P2: MTI Add against a disabled handler is refused with device_handler_disabled (got ' . $e->apiCode() . ')');
    check($e->status() === 409, 'P2: MTI Add against a disabled handler uses HTTP 409 (got ' . $e->status() . ')');
}
check(file_get_contents($xmlPath) === $isoBeforeMtiDisabled, 'P2: nothing is written to OPC_UA_Config.xml when the MTI handler is disabled');

// MUST STILL PASS: with the handler re-enabled, the same Add succeeds.
file_put_contents($logConfigPath, $logConfigWithBothHandlers);
channel_add_channel_from_pool(
    $xmlPath,
    $logConfigPath,
    add_payload('IOBOX', '_IOBOX_ReEnabled', '555555555.RX1.Success'),
    [], [$ioboxJson], [], [], []
);
check(written_anchor($xmlPath, '_IOBOX_ReEnabled') === '555555555.RX1.Success', 'P2 regression: the same IOBOX Add succeeds once the handler is re-enabled');

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

$auditPayload = add_payload('IOBOX', '_IOBOX_Audit', '555555555.RX1.CH2');
$auditPayload['build_date'] = '1';
$auditPayload['mod_date'] = '1';
$beforeAudit = sha1_file($xmlPath);
try {
    channel_add_channel_from_pool(
        $xmlPath, $logConfigPath, $auditPayload,
        [], [$ioboxJson], [], [], []
    );
    check(false, 'Add with client audit fields must throw');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'add_field_not_allowed' && $e->status() === 400, 'Add rejects client audit fields before resolving candidates');
}
check(sha1_file($xmlPath) === $beforeAudit, 'Add with client audit fields leaves XML unchanged');

echo "\n{$g_checks} checks, " . ($g_checks - $g_failures) . " passed, {$g_failures} failed\n";
exit($g_failures === 0 ? 0 : 1);
