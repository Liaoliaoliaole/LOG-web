<?php
/*
 * tests/php/sourceIdentityParserTest.php
 *
 * Regression test for iso_parse_source_identity() and the four per-interface
 * decoders it dispatches to (opcua_config.php). This is the Web-side
 * counterpart to Core's tests/opcua_config_parser_test.c: the grammar tables
 * below are deliberately kept in the same shape (accept/reject per
 * interface, then whole-document duplicate/canonicalization checks) so the
 * two suites can be compared side by side to catch Web/Core drift.
 *
 * Also covers:
 *   - iso_find_anchor_conflict() using semantic keys instead of raw ANCHOR
 *     text (an existing channel whose own ANCHOR fails to parse must be
 *     skipped, never crash and never falsely match).
 *   - iso_add_channel_body()/iso_update_channel_body() now gating every
 *     interface (previously SDAQ-only) through iso_require_valid_source_identity(),
 *     always persisting the canonical form, and using duplicate_source /
 *     invalid_anchor as the plan's unified error codes.
 *
 * Run: php tests/php/sourceIdentityParserTest.php   (from Morfeas_WEB/)
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

function accept(string $type, string $anchor, ?string $expectCanonical = null): void
{
    $identity = iso_parse_source_identity($type, $anchor);
    check($identity !== null, "$type accept \"$anchor\"");
    if ($identity !== null && $expectCanonical !== null) {
        check($identity['canonical_anchor'] === $expectCanonical, "$type \"$anchor\" canonicalizes to \"$expectCanonical\" (got " . var_export($identity['canonical_anchor'], true) . ")");
    }
}

function reject(string $type, string $anchor): void
{
    $identity = iso_parse_source_identity($type, $anchor);
    check($identity === null, "$type reject \"$anchor\"");
}

// ============================================================
// 1) SDAQ grammar (parity with iso_sdaq_anchor_is_valid()/decode_sdaq_anchor())
// ============================================================
accept('SDAQ', '1.CH1');
accept('SDAQ', '796834087.CH1');
accept('SDAQ', '4294967295.CH16');
reject('SDAQ', 'CAN1.ADDR:05.CH:01');
reject('SDAQ', 'can0.5.CH1');
reject('SDAQ', '0.CH1');
reject('SDAQ', '123.CH0');
reject('SDAQ', '123.CH17');
reject('SDAQ', '4294967296.CH1');
reject('SDAQ', '123.CH1.foo');
reject('SDAQ', '000123.CH01');
reject('SDAQ', '123.ch1');
check(iso_sdaq_anchor_is_valid('796834087.CH1') === true, 'iso_sdaq_anchor_is_valid() still true for a valid anchor (back-compat wrapper)');
check(iso_sdaq_anchor_is_valid('CAN1.ADDR:05.CH:01') === false, 'iso_sdaq_anchor_is_valid() still false for the incident anchor (back-compat wrapper)');

// ============================================================
// 2) IOBOX grammar (parity with decode_iobox_anchor())
// ============================================================
accept('IOBOX', '1.RX1.CH1');
accept('IOBOX', '117440522.RX1.CH1');
accept('IOBOX', '117440522.RX6.CH16');
accept('IOBOX', '117440522.RX1.Status');
accept('IOBOX', '117440522.RX1.Success');
reject('IOBOX', '0.RX1.CH1');
reject('IOBOX', '117440522.RX0.CH1');
reject('IOBOX', '117440522.RX7.CH1');
reject('IOBOX', '117440522.RX1.CH0');
reject('IOBOX', '117440522.RX1.CH17');
reject('IOBOX', '117440522.RX01.CH1');
reject('IOBOX', '117440522.RX1.CH01');
reject('IOBOX', '0117440522.RX1.CH1');
reject('IOBOX', '117440522.rx1.CH1');
reject('IOBOX', '117440522.RX1.ch1');
reject('IOBOX', '117440522.RX1.Statusx');
reject('IOBOX', '117440522.RX1.CH1extra');
reject('IOBOX', '117440522.RX1');
reject('IOBOX', ' 117440522.RX1.CH1');
reject('IOBOX', '117440522.RX1.CH1 ');
reject('IOBOX', '4294967296.RX1.CH1');
reject('IOBOX', '-117440522.RX1.CH1');

// Two different but syntactically similar CH numbers must not be confused
// with each other or with Status/Success -- semantic_key must reflect kind.
$ioboxCh1 = iso_parse_source_identity('IOBOX', '117440522.RX1.CH1');
$ioboxCh2 = iso_parse_source_identity('IOBOX', '117440522.RX1.CH2');
$ioboxStatus = iso_parse_source_identity('IOBOX', '117440522.RX1.Status');
check($ioboxCh1['semantic_key'] !== $ioboxCh2['semantic_key'], 'IOBOX RX1.CH1 and RX1.CH2 have different semantic keys');
check($ioboxCh1['semantic_key'] !== $ioboxStatus['semantic_key'], 'IOBOX RX1.CH1 and RX1.Status have different semantic keys');

// ============================================================
// 3) MTI grammar (parity with decode_mti_anchor())
// ============================================================
accept('MTI', '222222.TC16.CH1');
accept('MTI', '222222.TC16.CH16');
accept('MTI', '222222.TC8.CH8');
accept('MTI', '222222.TC4.CH4');
accept('MTI', '222222.QUAD.CH2');
accept('MTI', '222222.ID:1.CH1');
accept('MTI', '222222.ID:255.CH4');
reject('MTI', '222222.TC16.CH0');
reject('MTI', '222222.TC16.CH17');
reject('MTI', '222222.TC8.CH9');
reject('MTI', '222222.TC4.CH5');
reject('MTI', '222222.QUAD.CH3');
reject('MTI', '222222.ID:4.CH5');
reject('MTI', '222222.ID:0.CH1');
reject('MTI', '222222.ID:256.CH1'); // tele_ID is unsigned char in Core; must reject, not truncate
reject('MTI', '222222.ID:01.CH1');
reject('MTI', '222222.RMSW/MUX.CH1'); // the runtime radio-mode string is never itself a valid anchor
reject('MTI', '222222.tc16.CH1');
reject('MTI', '222222.TC16.ch1');
reject('MTI', '222222.TC160.CH1'); // literal must be followed immediately by '.'
reject('MTI', '222222.TC16.CH1extra');
reject('MTI', '222222.TC16');
reject('MTI', '0222222.TC16.CH1');

$mtiTc16 = iso_parse_source_identity('MTI', '222222.TC16.CH1');
$mtiTc8 = iso_parse_source_identity('MTI', '222222.TC8.CH1');
check($mtiTc16['semantic_key'] !== $mtiTc8['semantic_key'], 'MTI TC16.CH1 and TC8.CH1 (same identifier/channel, different type) are not a semantic duplicate');

// ============================================================
// 4) NOX grammar (parity with decode_nox_anchor(), including its exact
//    permissiveness -- this function's job is Core-equivalence, not a
//    cleaner grammar than what Core actually accepts today)
// ============================================================
accept('NOX', 'can0.addr_0.NOx', 'can0.addr_0.NOx');
accept('NOX', 'can0.addr_1.O2', 'can0.addr_1.O2');
accept('NOX', 'CAN0.ADDR:1.NOx', 'can0.addr_1.NOx'); // legacy alias, canonicalized
accept('NOX', 'can0.addr:1.nox', 'can0.addr_1.NOx'); // case-insensitive prefix/measurement
accept('NOX', 'can0.addr_01.NOx', 'can0.addr_1.NOx'); // Core's decode_nox_anchor() does not reject a leading zero on the address digits
reject('NOX', 'can0.addr_2.NOx'); // Core's decode_nox_anchor() only ever accepts address 0 or 1
reject('NOX', 'can0.addr_3.NOx');
reject('NOX', 'can0.sensor0.NOx'); // "sensor" is a Web-internal pool-matching alias only; Core's decoder never accepts it
reject('NOX', 'can0.addr_1.NO2'); // invalid measurement token
reject('NOX', 'can0.addr_1.NOx.extra'); // more than two dots
reject('NOX', 'can0..NOx'); // empty address segment
$noxLongIf = str_repeat('x', 16) . '.addr_1.NOx';
reject('NOX', $noxLongIf); // can_if segment must be < Dev_or_Bus_name_str_size (16)

// Same physical sensor, two measurements: legal, independent sources.
$noxNOx = iso_parse_source_identity('NOX', 'can0.addr_0.NOx');
$noxO2 = iso_parse_source_identity('NOX', 'can0.addr_0.O2');
check($noxNOx['semantic_key'] !== $noxO2['semantic_key'], 'NOX NOx and O2 on the same sensor have different semantic keys');

// ============================================================
// 5) iso_find_anchor_conflict(): semantic-key based, skips unparseable
//    existing anchors instead of crashing or falsely matching.
// ============================================================
$dir = make_tmp_dir('source_identity_test');
$xmlPath = $dir . '/OPC_UA_Config.xml';
file_put_contents($xmlPath, <<<XML
<?xml version="1.0"?>
<NODESet>
    <CHANNEL>
        <ISO_CHANNEL>_SDAQ_A</ISO_CHANNEL>
        <INTERFACE_TYPE>SDAQ</INTERFACE_TYPE>
        <ANCHOR>111111111.CH1</ANCHOR>
        <DESCRIPTION>d</DESCRIPTION>
        <MIN>0</MIN>
        <MAX>1</MAX>
    </CHANNEL>
    <CHANNEL>
        <ISO_CHANNEL>_IOBOX_A</ISO_CHANNEL>
        <INTERFACE_TYPE>IOBOX</INTERFACE_TYPE>
        <ANCHOR>222222222.RX1.CH1</ANCHOR>
        <DESCRIPTION>d</DESCRIPTION>
        <MIN>0</MIN>
        <MAX>1</MAX>
        <UNIT>C</UNIT>
    </CHANNEL>
    <CHANNEL>
        <ISO_CHANNEL>_Legacy_Bad</ISO_CHANNEL>
        <INTERFACE_TYPE>SDAQ</INTERFACE_TYPE>
        <ANCHOR>CAN1.ADDR:05.CH:01</ANCHOR>
        <DESCRIPTION>a pre-existing/hand-edited row with an unparseable legacy anchor</DESCRIPTION>
        <MIN>0</MIN>
        <MAX>1</MAX>
    </CHANNEL>
    <CHANNEL>
        <ISO_CHANNEL>_NOX_A</ISO_CHANNEL>
        <INTERFACE_TYPE>NOX</INTERFACE_TYPE>
        <ANCHOR>can0.addr_1.NOx</ANCHOR>
        <DESCRIPTION>d</DESCRIPTION>
        <MIN>0</MIN>
        <MAX>1</MAX>
        <UNIT>ppm</UNIT>
    </CHANNEL>
</NODESet>
XML
);
$xml = simplexml_load_file($xmlPath);

$sameSdaq = iso_parse_source_identity('SDAQ', '111111111.CH1');
check(
    iso_find_anchor_conflict($xml, $sameSdaq['semantic_key']) === '_SDAQ_A',
    'iso_find_anchor_conflict() finds the existing SDAQ duplicate by semantic key'
);

$differentSdaqChannel = iso_parse_source_identity('SDAQ', '111111111.CH2');
check(
    iso_find_anchor_conflict($xml, $differentSdaqChannel['semantic_key']) === null,
    'iso_find_anchor_conflict() does not flag a different channel on the same serial'
);

// IOBOX's strict grammar is case-sensitive by design (matches Core exactly);
// there is no legal alias form, so a same-canonical-text parse is the
// meaningful check here.
$sameIobox = iso_parse_source_identity('IOBOX', '222222222.RX1.CH1');
check(
    $sameIobox !== null && iso_find_anchor_conflict($xml, $sameIobox['semantic_key']) === '_IOBOX_A',
    'iso_find_anchor_conflict() finds the existing IOBOX duplicate by semantic key'
);

// NOX does have legal aliases (case, addr_/addr: separator, leading zero on
// the address digits); a different textual form of the same source must
// still be caught as a duplicate.
$aliasedNox = iso_parse_source_identity('NOX', 'CAN0.ADDR:01.nox');
check(
    $aliasedNox !== null && iso_find_anchor_conflict($xml, $aliasedNox['semantic_key']) === '_NOX_A',
    'iso_find_anchor_conflict() finds the existing NOX duplicate via a differently-spelled legal alias (got ' . var_export($aliasedNox !== null ? iso_find_anchor_conflict($xml, $aliasedNox['semantic_key']) : null, true) . ')'
);

// The unparseable legacy row must never crash the scan and must never be
// treated as matching anything (it simply can't be compared).
$anySdaq = iso_parse_source_identity('SDAQ', '999999999.CH9');
check(
    iso_find_anchor_conflict($xml, $anySdaq['semantic_key']) === null,
    'iso_find_anchor_conflict() skips a pre-existing unparseable legacy anchor without crashing or false-matching'
);

// ignoreIso must still work with the new signature.
check(
    iso_find_anchor_conflict($xml, $sameSdaq['semantic_key'], '_SDAQ_A') === null,
    'iso_find_anchor_conflict() honors $ignoreIso (self-match excluded, e.g. for Edit-in-place)'
);

// ============================================================
// 6) iso_add_channel_body()/iso_update_channel_body(): every interface now
//    gated through iso_require_valid_source_identity(), always persisting
//    the canonical form, using duplicate_source/invalid_anchor.
//
// This section needs its own clean fixture rather than reusing $xmlPath
// from section 5: since Phase A4 (2026-08-19), every write validates the
// *whole* document, not just the row being touched, so a file containing
// the pre-existing _Legacy_Bad row would now reject every one of 6a-6c/6e
// too, not just an edit of that specific row -- 6d below is exactly the
// scenario where that whole-document rejection is what's under test.
// ============================================================
$xmlPath = $dir . '/OPC_UA_Config_section6.xml';
file_put_contents($xmlPath, <<<XML
<?xml version="1.0"?>
<NODESet>
    <CHANNEL>
        <ISO_CHANNEL>_SDAQ_A</ISO_CHANNEL>
        <INTERFACE_TYPE>SDAQ</INTERFACE_TYPE>
        <ANCHOR>111111111.CH1</ANCHOR>
        <DESCRIPTION>d</DESCRIPTION>
        <MIN>0</MIN>
        <MAX>1</MAX>
    </CHANNEL>
    <CHANNEL>
        <ISO_CHANNEL>_IOBOX_A</ISO_CHANNEL>
        <INTERFACE_TYPE>IOBOX</INTERFACE_TYPE>
        <ANCHOR>222222222.RX1.CH1</ANCHOR>
        <DESCRIPTION>d</DESCRIPTION>
        <MIN>0</MIN>
        <MAX>1</MAX>
        <UNIT>C</UNIT>
    </CHANNEL>
    <CHANNEL>
        <ISO_CHANNEL>_NOX_A</ISO_CHANNEL>
        <INTERFACE_TYPE>NOX</INTERFACE_TYPE>
        <ANCHOR>can0.addr_1.NOx</ANCHOR>
        <DESCRIPTION>d</DESCRIPTION>
        <MIN>0</MIN>
        <MAX>1</MAX>
        <UNIT>ppm</UNIT>
    </CHANNEL>
</NODESet>
XML
);

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

// 6a) IOBOX Add with already-canonical text round-trips unchanged. The write
//     layer applies iso_parse_source_identity() strictly (no pool-matching
//     tolerance lives here); a non-canonical IOBOX text is a straight reject,
//     not a silent fix -- proven right after this.
iso_add_channel_body($xmlPath, [
    'iso_channel' => '_IOBOX_New', 'interface_type' => 'IOBOX', 'anchor' => '333333333.RX2.CH5',
    'description' => 'd', 'min' => '0', 'max' => '1', 'unit' => 'C',
]);
check(written_anchor($xmlPath, '_IOBOX_New') === '333333333.RX2.CH5', 'iso_add_channel_body() persists a canonical IOBOX anchor unchanged (got ' . var_export(written_anchor($xmlPath, '_IOBOX_New'), true) . ')');

try {
    iso_add_channel_body($xmlPath, [
        'iso_channel' => '_IOBOX_LowerCase', 'interface_type' => 'IOBOX', 'anchor' => '444444444.rx1.ch1',
        'description' => 'd', 'min' => '0', 'max' => '1', 'unit' => 'C',
    ]);
    check(false, 'iso_add_channel_body() with lower-case IOBOX text must throw, not silently canonicalize it');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'invalid_anchor', 'iso_add_channel_body() rejects non-canonical IOBOX text outright at the write layer (got ' . $e->apiCode() . ')');
}

// NOX does have a legal write-time alias; the write layer must canonicalize it.
iso_add_channel_body($xmlPath, [
    'iso_channel' => '_NOX_New', 'interface_type' => 'NOX', 'anchor' => 'CAN2.ADDR:1.O2',
    'description' => 'd', 'min' => '0', 'max' => '1', 'unit' => '%',
]);
check(written_anchor($xmlPath, '_NOX_New') === 'can2.addr_1.O2', 'iso_add_channel_body() persists the canonical NOX form for a legal alias (got ' . var_export(written_anchor($xmlPath, '_NOX_New'), true) . ')');

// 6b) Invalid grammar (any interface) is rejected with invalid_anchor, XML unchanged.
$beforeHash = sha1_file($xmlPath);
try {
    iso_add_channel_body($xmlPath, [
        'iso_channel' => '_MTI_Bad', 'interface_type' => 'MTI', 'anchor' => '222222.RMSW/MUX.CH1',
        'description' => 'd', 'min' => '0', 'max' => '1', 'unit' => 'C',
    ]);
    check(false, 'iso_add_channel_body() with an invalid MTI anchor must throw');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'invalid_anchor', 'iso_add_channel_body() rejects an invalid anchor with invalid_anchor (got ' . $e->apiCode() . ')');
    check($e->status() === 409, 'invalid_anchor rejection uses HTTP 409 (got ' . $e->status() . ')');
}
check(sha1_file($xmlPath) === $beforeHash, 'XML file is unchanged after rejecting an invalid-grammar Add');

// 6c) Semantic duplicate (different text, same parsed source) rejected with duplicate_source.
$beforeHash2 = sha1_file($xmlPath);
try {
    iso_add_channel_body($xmlPath, [
        'iso_channel' => '_IOBOX_Dup', 'interface_type' => 'IOBOX', 'anchor' => '222222222.RX1.CH1', // exact duplicate of _IOBOX_A
        'description' => 'd', 'min' => '0', 'max' => '1', 'unit' => 'C',
    ]);
    check(false, 'iso_add_channel_body() of a semantic duplicate must throw');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'duplicate_source', 'iso_add_channel_body() rejects a semantic duplicate with duplicate_source (got ' . $e->apiCode() . ')');
}
check(sha1_file($xmlPath) === $beforeHash2, 'XML file is unchanged after rejecting a duplicate-source Add');

// 6d) iso_update_channel_body(): a plain metadata Edit on the pre-existing
//     legacy-bad-anchor row must still be rejected -- and, since Phase A4's
//     whole-document validator (plan §6.0.2), any write to a file that
//     still contains that row is rejected, not just an edit targeting it
//     specifically. This is Core's actual behaviour (a single invalid row
//     fails the *entire* document on every reload, confirmed live against
//     Morfeas_opc_ua_config_valid()) and matches the plan's Delete
//     authorization note: the operator's way out is to delete the one bad
//     channel, not edit around it. Isolated in its own fixture so it does
//     not interfere with 6a-6c/6e above.
$legacyBadPath = $dir . '/OPC_UA_Config_legacy_bad.xml';
file_put_contents($legacyBadPath, <<<XML
<?xml version="1.0"?>
<NODESet>
    <CHANNEL>
        <ISO_CHANNEL>_Legacy_Bad</ISO_CHANNEL>
        <INTERFACE_TYPE>SDAQ</INTERFACE_TYPE>
        <ANCHOR>CAN1.ADDR:05.CH:01</ANCHOR>
        <DESCRIPTION>a pre-existing/hand-edited row with an unparseable legacy anchor</DESCRIPTION>
        <MIN>0</MIN>
        <MAX>1</MAX>
    </CHANNEL>
</NODESet>
XML
);
try {
    iso_update_channel_body($legacyBadPath, '_Legacy_Bad', ['description' => 'trying to just edit description']);
    check(false, 'Editing metadata on a channel with a legacy unparseable anchor must still throw (unchanged anchor is re-validated)');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'invalid_anchor', 'Metadata-only Edit on a legacy-bad-anchor row is rejected with invalid_anchor (got ' . $e->apiCode() . ')');
}
try {
    iso_add_channel_body($legacyBadPath, [
        'iso_channel' => '_Unrelated_New', 'interface_type' => 'SDAQ', 'anchor' => '999999999.CH1',
        'description' => 'd', 'min' => '0', 'max' => '1',
    ]);
    check(false, 'Even an unrelated, otherwise-valid Add must throw while a legacy-bad row remains in the file (Phase A4 whole-document gate)');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'invalid_anchor', 'An unrelated Add is blocked by the pre-existing legacy-bad row, matching Core (got ' . $e->apiCode() . ')');
}

// 6e) iso_update_channel_body(): a plain metadata Edit on a channel with a
//     valid canonical anchor is unaffected (anchor round-trips unchanged).
iso_update_channel_body($xmlPath, '_IOBOX_A', ['description' => 'updated description']);
check(written_anchor($xmlPath, '_IOBOX_A') === '222222222.RX1.CH1', 'Metadata-only Edit on a valid-anchor channel leaves the canonical anchor unchanged');

echo "\n{$g_checks} checks, " . ($g_checks - $g_failures) . " passed, {$g_failures} failed\n";
exit($g_failures === 0 ? 0 : 1);
