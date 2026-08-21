<?php
/*
 * tests/php/documentValidatorTest.php
 *
 * Regression test for iso_validate_document() (opcua_config.php), the
 * single whole-document Core-equivalence gate added by Phase A4
 * (2026-08-19 code review, plan §6.0.2). Every rule below is cross-
 * referenced by number (C-1..C-9, D-1..D-3) against the same section, and
 * against Core's tests/opcua_config_parser_test.c, which gained matching
 * test_whole_document_rejects_empty_description() /
 * _iso_channel_too_long() / _iso_channel_with_dot() cases in the same
 * change -- these three were previously untested on *either* side of the
 * Web/Core boundary, which is how the Web gap went unnoticed until it was
 * reproduced against a live LOGDemo32.
 *
 * Section 1 calls iso_validate_document() directly against hand-built
 * SimpleXMLElement documents (unit-level, mirrors the Core suite's style).
 * Section 2 goes through iso_add_channel_body()/iso_update_channel_body()
 * to prove the gate is actually wired into iso_save_xml() and cannot be
 * bypassed by a real write path, not just callable in isolation.
 *
 * Run: php tests/php/documentValidatorTest.php   (from Morfeas_WEB/)
 */

require __DIR__ . '/../../backend/core/opcua_config.php';

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

// One CHANNEL's inner XML, every element present in DTD order, all non-empty.
function valid_channel_xml(string $iso = 'TE1', string $interface = 'SDAQ', string $anchor = '796834087.CH1', string $unit = ''): string
{
    $unitNode = $interface !== 'SDAQ' ? "<UNIT>" . ($unit !== '' ? $unit : 'C') . "</UNIT>" : '';
    return "<CHANNEL><ISO_CHANNEL>$iso</ISO_CHANNEL><INTERFACE_TYPE>$interface</INTERFACE_TYPE>"
        . "<ANCHOR>$anchor</ANCHOR><DESCRIPTION>d</DESCRIPTION><MIN>0</MIN><MAX>1</MAX>$unitNode</CHANNEL>";
}

function doc_from_channels(string $channelsXml): SimpleXMLElement
{
    $xml = simplexml_load_string("<NODESet>$channelsXml</NODESet>");
    if ($xml === false) {
        throw new RuntimeException('fixture failed to parse as XML');
    }
    return $xml;
}

function expect_valid(string $channelsXml, string $label): void
{
    try {
        iso_validate_document(doc_from_channels($channelsXml));
        check(true, "$label is accepted");
    } catch (ChannelConfigException $e) {
        check(false, "$label is accepted (unexpectedly rejected: {$e->apiCode()}: {$e->getMessage()})");
    }
}

function expect_rejected(string $channelsXml, string $expectedCode, string $label): void
{
    try {
        iso_validate_document(doc_from_channels($channelsXml));
        check(false, "$label is rejected (unexpectedly accepted)");
    } catch (ChannelConfigException $e) {
        check($e->apiCode() === $expectedCode, "$label is rejected with $expectedCode (got {$e->apiCode()}: {$e->getMessage()})");
    }
}

// --- Section 1: iso_validate_document() called directly. ---------------

expect_valid(valid_channel_xml(), 'a single valid SDAQ channel');
expect_valid('', 'an empty document (zero channels)');

// C-1: no element's content may be empty -- for all fifteen elements, not
// just the six required ones.
expect_rejected(
    '<CHANNEL><ISO_CHANNEL>TE1</ISO_CHANNEL><INTERFACE_TYPE>SDAQ</INTERFACE_TYPE><ANCHOR>796834087.CH1</ANCHOR><DESCRIPTION></DESCRIPTION><MIN>0</MIN><MAX>1</MAX></CHANNEL>',
    'empty_element',
    'a channel with an empty <DESCRIPTION/>'
);
expect_rejected(
    '<CHANNEL><ISO_CHANNEL>TE1</ISO_CHANNEL><INTERFACE_TYPE>SDAQ</INTERFACE_TYPE><ANCHOR>796834087.CH1</ANCHOR><DESCRIPTION>d</DESCRIPTION><MIN></MIN><MAX>1</MAX></CHANNEL>',
    'empty_element',
    'a channel with an empty <MIN/>'
);
expect_rejected(
    '<CHANNEL><ISO_CHANNEL>TE1</ISO_CHANNEL><INTERFACE_TYPE>SDAQ</INTERFACE_TYPE><ANCHOR>796834087.CH1</ANCHOR><DESCRIPTION>d</DESCRIPTION><MIN>0</MIN><MAX></MAX></CHANNEL>',
    'empty_element',
    'a channel with an empty <MAX/>'
);
expect_rejected(
    '<CHANNEL><ISO_CHANNEL>TE1</ISO_CHANNEL><INTERFACE_TYPE>IOBOX</INTERFACE_TYPE><ANCHOR>117440522.RX1.CH1</ANCHOR><DESCRIPTION>d</DESCRIPTION><MIN>0</MIN><MAX>1</MAX><UNIT></UNIT></CHANNEL>',
    'empty_element',
    'a channel with an empty optional <UNIT/> (optional but present must still be non-empty)'
);

// C-4: ISO_CHANNEL length must be < 20 bytes (matches Core's
// ISO_channel_name_size). Judged on the value actually stored, i.e. after
// iso_normalize_iso_channel()'s "_" prefixing -- these fixtures write the
// already-prefixed form directly since they bypass that helper.
expect_valid(valid_channel_xml('_AAAAAAAAAAAAAAAAAA'), 'a 19-byte ISO_CHANNEL (< 20)');
expect_rejected(
    valid_channel_xml('_AAAAAAAAAAAAAAAAAAA'),
    'invalid_iso_channel',
    'a 20-byte ISO_CHANNEL (>= 20)'
);

// C-5: ISO_CHANNEL must not contain '.'.
expect_rejected(valid_channel_xml('_Bad.Name'), 'invalid_iso_channel', 'an ISO_CHANNEL containing "."');

// C-2 / C-9: INTERFACE_TYPE must be a known, supported interface -- MDAQ
// (retired) and any other unrecognized value land here with a distinct
// code, not folded into C-6's anchor-grammar failure.
expect_rejected(valid_channel_xml('TE1', 'MDAQ', '1.CH1.Val1'), 'unsupported_interface', 'INTERFACE_TYPE=MDAQ (retired)');
expect_rejected(valid_channel_xml('TE1', 'BOGUS', 'whatever'), 'unsupported_interface', 'an unrecognized INTERFACE_TYPE');
expect_rejected(valid_channel_xml('TE1', 'sdaq', '796834087.CH1'), 'unsupported_interface', 'lower-case INTERFACE_TYPE that Core strcmp() rejects');
expect_rejected(valid_channel_xml('TE1', ' SDAQ', '796834087.CH1'), 'unsupported_interface', 'INTERFACE_TYPE with leading whitespace that Core strcmp() rejects');
expect_rejected(valid_channel_xml(' TE1', 'SDAQ', '796834087.CH1'), 'invalid_iso_channel', 'ISO_CHANNEL with leading whitespace');

// C-6: ANCHOR must satisfy the interface's strict grammar.
expect_rejected(valid_channel_xml('TE1', 'SDAQ', 'CAN1.ADDR:05.CH:01'), 'invalid_anchor', 'an address-style SDAQ anchor (the 2026-08-13 incident pattern)');
expect_rejected(valid_channel_xml('TE1', 'SDAQ', '0.CH1'), 'invalid_anchor', 'a zero SDAQ serial');
expect_rejected(valid_channel_xml('TE1', 'SDAQ', '796834087.CH1 '), 'invalid_anchor', 'an ANCHOR with trailing whitespace that Core rejects');

// C-7: IOBOX/MTI/NOX must carry a non-empty XML-owned UNIT; SDAQ must not
// require one (its Unit is runtime-owned, never read from XML).
expect_rejected(
    '<CHANNEL><ISO_CHANNEL>TE1</ISO_CHANNEL><INTERFACE_TYPE>IOBOX</INTERFACE_TYPE><ANCHOR>117440522.RX1.CH1</ANCHOR><DESCRIPTION>d</DESCRIPTION><MIN>0</MIN><MAX>1</MAX></CHANNEL>',
    'missing_required_unit',
    'an IOBOX channel with no <UNIT> node at all'
);
expect_valid(valid_channel_xml('TE1', 'SDAQ', '796834087.CH1'), 'an SDAQ channel with no <UNIT> node (never required)');

// C-3: ISO_CHANNEL must be unique across the whole document.
expect_rejected(
    valid_channel_xml('TE_Dup', 'SDAQ', '796834087.CH1') . valid_channel_xml('TE_Dup', 'SDAQ', '111111111.CH1'),
    'channel_conflict',
    'two channels sharing the same ISO_CHANNEL'
);

// C-8: the parsed source identity must be unique per interface, on decoded
// fields -- not raw ANCHOR text, and not shared across interfaces (the
// semantic_key already namespaces by interface).
expect_rejected(
    valid_channel_xml('TE_A', 'SDAQ', '796834087.CH1') . valid_channel_xml('TE_B', 'SDAQ', '796834087.CH1'),
    'duplicate_source',
    'two ISO_CHANNELs resolving to the same (SDAQ, serial, channel)'
);
expect_valid(
    valid_channel_xml('TE_A', 'SDAQ', '796834087.CH1') . valid_channel_xml('TE_B', 'SDAQ', '796834087.CH2'),
    'the same SDAQ serial on two different channels (legal, not a duplicate source)'
);
expect_valid(
    valid_channel_xml('TE_A', 'IOBOX', '117440522.RX1.CH1', 'C') . valid_channel_xml('TE_B', 'MTI', '117440522.TC16.CH1', 'C'),
    'the same identifier used by an IOBOX and an MTI channel (distinct interfaces, not a collision)'
);

// D-3: an element name outside the DTD's CHANNEL content model.
expect_rejected(
    '<CHANNEL><ISO_CHANNEL>TE1</ISO_CHANNEL><INTERFACE_TYPE>SDAQ</INTERFACE_TYPE><ANCHOR>796834087.CH1</ANCHOR><DESCRIPTION>d</DESCRIPTION><MIN>0</MIN><MAX>1</MAX><NOT_A_REAL_FIELD>x</NOT_A_REAL_FIELD></CHANNEL>',
    'invalid_document_structure',
    'a channel with an element name not in Morfeas.dtd'
);

// D-1: the six required elements must all be present.
expect_rejected(
    '<CHANNEL><ISO_CHANNEL>TE1</ISO_CHANNEL><INTERFACE_TYPE>SDAQ</INTERFACE_TYPE><ANCHOR>796834087.CH1</ANCHOR><DESCRIPTION>d</DESCRIPTION><MIN>0</MIN></CHANNEL>',
    'invalid_document_structure',
    'a channel missing the required <MAX> element entirely'
);

// D-2: elements that are present must appear in Morfeas.dtd sequence order.
expect_rejected(
    '<CHANNEL><ISO_CHANNEL>TE1</ISO_CHANNEL><INTERFACE_TYPE>SDAQ</INTERFACE_TYPE><ANCHOR>796834087.CH1</ANCHOR><DESCRIPTION>d</DESCRIPTION><MAX>1</MAX><MIN>0</MIN></CHANNEL>',
    'invalid_document_structure',
    'a channel with <MIN>/<MAX> swapped out of DTD order'
);

// --- Section 2: proof the gate is wired into the real write paths, not ---
// --- just callable in isolation.                                      ---

function make_base_xml(string $dir): string
{
    $path = $dir . '/OPC_UA_Config.xml';
    file_put_contents($path, "<?xml version=\"1.0\"?>\n<NODESet>\n</NODESet>\n");
    return $path;
}

$dir = make_tmp_dir('doc_validator_wiring_test');
$xmlPath = make_base_xml($dir);

// This is the exact real-machine reproduction from the 2026-08-19 code
// review (F-1): iso_add_channel_body() must now refuse an empty
// description before Core ever sees the file, instead of writing
// <DESCRIPTION/> and letting the next Core reload discover it.
try {
    iso_add_channel_body($xmlPath, [
        'iso_channel' => '_EmptyDescWiring', 'interface_type' => 'SDAQ',
        'anchor' => '796834087.CH1', 'description' => '', 'min' => '0', 'max' => '1',
    ]);
    check(false, 'iso_add_channel_body() with an empty description must throw (F-1 wiring)');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'empty_element', 'iso_add_channel_body() rejects an empty description via the document gate (got ' . $e->apiCode() . ')');
}
check(trim(file_get_contents($xmlPath)) === trim("<?xml version=\"1.0\"?>\n<NODESet>\n</NODESet>"), 'XML file is byte-for-byte unchanged after the rejected Add');

try {
    iso_add_channel_body($xmlPath, [
        'iso_channel' => '_This_Name_Is_Twenty0', 'interface_type' => 'SDAQ',
        'anchor' => '796834087.CH1', 'description' => 'd', 'min' => '0', 'max' => '1',
    ]);
    check(false, 'iso_add_channel_body() with a 20+ byte ISO_CHANNEL must throw (F-2 wiring)');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'invalid_iso_channel', 'iso_add_channel_body() rejects an over-length ISO_CHANNEL via the document gate (got ' . $e->apiCode() . ')');
}

try {
    iso_add_channel_body($xmlPath, [
        'iso_channel' => '_Bad.Name.Wiring', 'interface_type' => 'SDAQ',
        'anchor' => '796834087.CH2', 'description' => 'd', 'min' => '0', 'max' => '1',
    ]);
    check(false, 'iso_add_channel_body() with a "." in ISO_CHANNEL must throw (F-3 wiring)');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'invalid_iso_channel', 'iso_add_channel_body() rejects a dotted ISO_CHANNEL via the document gate (got ' . $e->apiCode() . ')');
}

try {
    iso_add_channel_body($xmlPath, [
        'iso_channel' => '_IoboxNoUnitWiring', 'interface_type' => 'IOBOX',
        'anchor' => '117440522.RX2.CH1', 'description' => 'd', 'min' => '0', 'max' => '1',
        // no 'unit' key at all
    ]);
    check(false, 'iso_add_channel_body() of an IOBOX channel with no unit must throw (F-4 wiring)');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'missing_required_unit', 'iso_add_channel_body() rejects a unit-less IOBOX channel via the document gate (got ' . $e->apiCode() . ')');
}

// A legitimate Add must still succeed after all the rejections above --
// the gate must not be so strict it blocks valid writes.
iso_add_channel_body($xmlPath, [
    'iso_channel' => '_GoodWiring', 'interface_type' => 'SDAQ',
    'anchor' => '796834087.CH1', 'description' => 'd', 'min' => '0', 'max' => '1',
]);
$rows = iso_load_channels($xmlPath);
check(count($rows) === 1 && $rows[0]['iso_channel'] === '_GoodWiring', 'a legitimate Add still succeeds after the document gate rejects several bad ones');

// Edit must be gated too: blanking out the description of an existing,
// otherwise-valid channel must be refused (F-1 via iso_update_channel_body()).
try {
    iso_update_channel_body($xmlPath, '_GoodWiring', ['description' => '']);
    check(false, 'iso_update_channel_body() blanking description must throw (F-1 wiring via Edit)');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'empty_element', 'iso_update_channel_body() rejects blanking description via the document gate (got ' . $e->apiCode() . ')');
}
$rows = iso_load_channels($xmlPath);
check($rows[0]['description'] === 'd', 'the existing channel is unchanged after the rejected Edit');

echo "\n{$g_checks} checks, " . ($g_checks - $g_failures) . " passed, {$g_failures} failed\n";
exit($g_failures === 0 ? 0 : 1);
