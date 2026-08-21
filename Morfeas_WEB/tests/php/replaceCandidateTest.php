<?php
/*
 * tests/php/replaceCandidateTest.php
 *
 * Standalone regression test for the Replace fix in channel_service.php:
 *   - Replace is SDAQ-only (device-relocation is the only scenario it
 *     exists for): a source with any other resolvable interface family
 *     must be rejected with replace_source_not_sdaq, even when that source
 *     is genuinely offline and a matching same-family candidate exists.
 *   - channel_replace_channel_from_pool() re-derives the canonical target
 *     anchor server-side inside the XML lock (never persists the client's
 *     raw submission), mirroring channel_add_channel_from_pool().
 *   - The previous silent pass-through ("source SDAQ subtype unknown and no
 *     candidate found -> allow anyway") is removed: an unresolved target is
 *     now always rejected with replace_target_not_detected.
 *   - The source channel must be re-confirmed offline server-side, inside
 *     the same lock as the write; a currently-online/connected source must
 *     reject with replace_source_not_offline (plan 5.4).
 *   - A source with an unresolvable interface family (e.g. a malformed row
 *     with an empty INTERFACE_TYPE) must reject, never silently skip the
 *     family-compatibility check.
 *   - Replace only ever persists anchor + mod_date; any other field in the
 *     replace_mode PATCH body (iso_channel, description, min/max, unit,
 *     alarms) must be ignored, not written.
 *
 * Run: php tests/php/replaceCandidateTest.php   (from Morfeas_WEB/)
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

$dir = make_tmp_dir('replace_test');

// SDAQ subtype resolution for an *offline* source falls back to a persisted
// type cache keyed by the channel's own stored ANCHOR text (see
// channel_sdaq_cache_keys()'s raw-anchor fallback). Point the cache at a
// test-local file so a genuinely successful SDAQ Replace (which requires a
// known source subtype) can be exercised without touching the real cache.
$typeCachePath = $dir . '/sdaq_type_cache.json';
putenv('MORFEAS_SDAQ_TYPE_CACHE_PATH=' . $typeCachePath);
file_put_contents($typeCachePath, json_encode([
    'types' => [
        '111111111.CH1' => 'SDAQ-I', // _Old_SDAQ_Known1's own stored anchor
        '222222222.CH1' => 'SDAQ-I', // _Old_SDAQ_Known2's own stored anchor
    ],
]));

// --- Fixture: one detected, unlinked IOBOX candidate (used to prove a
//     same-family IOBOX Replace is now rejected, and for the cross-family
//     mismatch test against an SDAQ source). ---
$ioboxJson = $dir . '/logstat_IOBOX_iobox1.json';
file_put_contents($ioboxJson, json_encode([
    'Identifier' => '117440522',
    'IPv4_address' => '7.1.1.10',
    'Connection_status' => 'Okay',
    'RX1' => ['1' => 23.5, 'Status' => 1, 'Success' => 98],
]));

// --- Fixture: SDAQ candidates for the genuinely successful Replace tests. ---
$sdaqJson = $dir . '/logstat_SDAQ_can1.json';
file_put_contents($sdaqJson, json_encode([
    'CANBus_interface' => 'CAN1',
    'SDAQs_data' => [
        [
            'Address' => 10,
            'SDAQ_type' => 'SDAQ-I',
            'Serial_number' => 900000001,
            'SDAQ_Status' => ['Registration_status' => 'Done'],
            'Meas' => [[
                'Channel' => 1,
                'CNT' => 10,
                'Channel_Status' => ['Channel_status_val' => 0, 'No_Sensor' => false, 'Over_Range' => false, 'Out_of_Range' => false],
                'Unit' => 'C',
                'Last_Meas' => 21.0,
            ]],
        ],
        [
            'Address' => 11,
            'SDAQ_type' => 'SDAQ-I',
            'Serial_number' => 900000002,
            'SDAQ_Status' => ['Registration_status' => 'Done'],
            'Meas' => [[
                'Channel' => 1,
                'CNT' => 10,
                'Channel_Status' => ['Channel_status_val' => 0, 'No_Sensor' => false, 'Over_Range' => false, 'Out_of_Range' => false],
                'Unit' => 'C',
                'Last_Meas' => 22.0,
            ]],
        ],
    ],
]));

// --- XML: an offline IOBOX channel (now-rejected same-family Replace), an
//          SDAQ channel with an unknown subtype (unresolvable-target /
//          cross-family regression), and an offline SDAQ channel whose
//          subtype is known only via the type cache above (genuinely
//          successful Replace). ---
$xmlPath = $dir . '/OPC_UA_Config.xml';
file_put_contents($xmlPath, <<<XML
<?xml version="1.0"?>
<NODESet>
    <CHANNEL>
        <ISO_CHANNEL>_Old_IOBOX</ISO_CHANNEL>
        <INTERFACE_TYPE>IOBOX</INTERFACE_TYPE>
        <ANCHOR>999999999.RX1.CH1</ANCHOR>
        <DESCRIPTION>d</DESCRIPTION>
        <MIN>-40</MIN>
        <MAX>150</MAX>
        <UNIT>C</UNIT>
    </CHANNEL>
    <CHANNEL>
        <ISO_CHANNEL>_Old_SDAQ</ISO_CHANNEL>
        <INTERFACE_TYPE>SDAQ</INTERFACE_TYPE>
        <ANCHOR>555555555.CH1</ANCHOR>
        <DESCRIPTION>d</DESCRIPTION>
        <MIN>0</MIN>
        <MAX>1</MAX>
    </CHANNEL>
    <CHANNEL>
        <ISO_CHANNEL>_Old_SDAQ_Known1</ISO_CHANNEL>
        <INTERFACE_TYPE>SDAQ</INTERFACE_TYPE>
        <ANCHOR>111111111.CH1</ANCHOR>
        <DESCRIPTION>d</DESCRIPTION>
        <MIN>0</MIN>
        <MAX>1</MAX>
    </CHANNEL>
</NODESet>
XML
);

// --- 1) Replace is SDAQ-only: an offline IOBOX source with a real,
//        available, same-family IOBOX candidate must still be rejected.
//        IOBOX/MTI/NOX identity moves go through Devices + Add/Delete, not
//        Replace. ---
$beforeHashIobox = sha1_file($xmlPath);
try {
    channel_replace_channel_from_pool(
        $xmlPath,
        '_Old_IOBOX',
        ['replace_mode' => true, 'anchor' => '117440522.RX1.CH1'],
        [], [$ioboxJson], [], [], []
    );
    check(false, 'Replace of an offline IOBOX source must throw, even with a valid same-family candidate');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'replace_source_not_sdaq', 'Replace rejects a non-SDAQ source with replace_source_not_sdaq (got ' . $e->apiCode() . ')');
    check($e->status() === 409, 'Non-SDAQ-source rejection uses HTTP 409 (got ' . $e->status() . ')');
}
check(sha1_file($xmlPath) === $beforeHashIobox, 'XML file is unchanged after rejecting a same-family IOBOX Replace');

// --- 2) Successful Replace: client submits a non-canonical (lowercase) text
//        that resolves to the real candidate; the persisted ANCHOR must be
//        the candidate's own canonical form, never the client's literal
//        text. Requires a genuinely offline SDAQ source with a
//        cache-resolved known subtype (see $typeCachePath above). ---
channel_replace_channel_from_pool(
    $xmlPath,
    '_Old_SDAQ_Known1',
    ['replace_mode' => true, 'anchor' => '900000001.ch1'],
    [$sdaqJson], [], [], [], []
);
$xml = simplexml_load_file($xmlPath);
$written = null;
foreach ($xml->CHANNEL as $ch) {
    if ((string)$ch->ISO_CHANNEL === '_Old_SDAQ_Known1') {
        $written = (string)$ch->ANCHOR;
    }
}
check($written === '900000001.CH1', 'Replace persists the candidate\'s canonical anchor, not the client\'s literal text (got ' . var_export($written, true) . ')');

// --- 3) Regression test for the removed silent pass-through: an SDAQ source
//        with an unknown subtype and a target that matches NOTHING in the
//        pool must be rejected, not silently written verbatim (this used to
//        "return" and let iso_update_channel() persist the raw client
//        anchor unmodified). ---
$beforeHash = sha1_file($xmlPath);
try {
    channel_replace_channel_from_pool(
        $xmlPath,
        '_Old_SDAQ',
        ['replace_mode' => true, 'anchor' => 'CAN9.ADDR:99.CH:01'],
        [$sdaqJson], [$ioboxJson], [], [], []
    );
    check(false, 'Replace with an unresolvable target and unknown source subtype must throw');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'replace_target_not_detected', 'Replace rejects an unresolvable target with replace_target_not_detected (got ' . $e->apiCode() . ')');
    check($e->status() === 409, 'Replace rejection uses HTTP 409 (got ' . $e->status() . ')');
}
check(sha1_file($xmlPath) === $beforeHash, 'XML file is byte-for-byte unchanged after the rejected Replace (no silent pass-through write)');

// --- 4) Family mismatch is still enforced after the refactor. ---
try {
    channel_replace_channel_from_pool(
        $xmlPath,
        '_Old_SDAQ',
        ['replace_mode' => true, 'anchor' => '117440522.RX1.CH1'],
        [$sdaqJson], [$ioboxJson], [], [], []
    );
    check(false, 'Replace of an SDAQ source with an IOBOX candidate must throw');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'replace_type_mismatch', 'Replace rejects a cross-family candidate with replace_type_mismatch (got ' . $e->apiCode() . ')');
}

// --- 5) Missing source ISO_CHANNEL is still rejected. ---
try {
    channel_replace_channel_from_pool(
        $xmlPath,
        '_Does_Not_Exist',
        ['replace_mode' => true, 'anchor' => '117440522.RX1.CH1'],
        [], [$ioboxJson], [], [], []
    );
    check(false, 'Replace of a non-existent source ISO_CHANNEL must throw');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'replace_source_not_found', 'Replace rejects a missing source with replace_source_not_found (got ' . $e->apiCode() . ')');
}

// --- Fixtures for the source-offline / field-allowlist tests below: an SDAQ
//     device that is currently online (source-offline test) and reuse of
//     the second cached-subtype SDAQ source declared above for the
//     field-allowlist test. ---
$sdaqJsonLive = $dir . '/logstat_SDAQ_can2.json';
file_put_contents($sdaqJsonLive, json_encode([
    'CANBus_interface' => 'CAN2',
    'SDAQs_data' => [[
        'Address' => 12,
        'SDAQ_type' => 'SDAQ-I',
        'Serial_number' => 444444444,
        'SDAQ_Status' => ['Registration_status' => 'Done'],
        'Meas' => [[
            'Channel' => 1,
            'CNT' => 10,
            'Channel_Status' => ['Channel_status_val' => 0, 'No_Sensor' => false, 'Over_Range' => false, 'Out_of_Range' => false],
            'Unit' => 'C',
            'Last_Meas' => 12.0,
        ]],
    ]],
]));

// --- XML: one channel whose anchor matches a currently-online SDAQ
//          candidate (source-offline test), one with an empty
//          INTERFACE_TYPE (family test), and the field-allowlist source
//          channel. ---
$xml2 = simplexml_load_file($xmlPath);
$liveNode = $xml2->addChild('CHANNEL');
$liveNode->addChild('ISO_CHANNEL', '_Live_SDAQ');
$liveNode->addChild('INTERFACE_TYPE', 'SDAQ');
$liveNode->addChild('ANCHOR', '444444444.CH1'); // matches the online candidate above -> status Okay
$liveNode->addChild('DESCRIPTION', 'live');
$liveNode->addChild('MIN', '-40');
$liveNode->addChild('MAX', '150');

$blankTypeNode = $xml2->addChild('CHANNEL');
$blankTypeNode->addChild('ISO_CHANNEL', '_Blank_Type');
$blankTypeNode->addChild('INTERFACE_TYPE', '');
$blankTypeNode->addChild('ANCHOR', 'legacy_anchor_text');
$blankTypeNode->addChild('DESCRIPTION', 'blank');
$blankTypeNode->addChild('MIN', '0');
$blankTypeNode->addChild('MAX', '1');

$allowlistNode = $xml2->addChild('CHANNEL');
$allowlistNode->addChild('ISO_CHANNEL', '_Old_SDAQ_Known2');
$allowlistNode->addChild('INTERFACE_TYPE', 'SDAQ');
$allowlistNode->addChild('ANCHOR', '222222222.CH1'); // not in any logstat -> offline; subtype known via cache
$allowlistNode->addChild('DESCRIPTION', 'original description');
$allowlistNode->addChild('MIN', '-40');
$allowlistNode->addChild('MAX', '150');
file_put_contents($xmlPath, $xml2->asXML());

// --- 6) Source-offline re-verification (plan 5.4): a source that is
//        currently online/connected must never be replaceable, even via a
//        direct API call with a syntactically fine target. ---
$beforeHash2 = sha1_file($xmlPath);
try {
    channel_replace_channel_from_pool(
        $xmlPath,
        '_Live_SDAQ',
        ['replace_mode' => true, 'anchor' => '900000002.CH1'],
        [$sdaqJson, $sdaqJsonLive], [], [], [], []
    );
    check(false, 'Replace of a currently-online source must throw');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'replace_source_not_offline', 'Replace rejects an online source with replace_source_not_offline (got ' . $e->apiCode() . ')');
    check($e->status() === 409, 'Online-source rejection uses HTTP 409 (got ' . $e->status() . ')');
}
check(sha1_file($xmlPath) === $beforeHash2, 'XML file is unchanged after rejecting a Replace of an online source');

// --- 7) Unresolvable source interface family must reject with
//        replace_source_family_unknown. Now that the SDAQ-only gate sits
//        ahead of the offline check (see channel_service.php), this is a
//        directly reachable, deterministic rejection rather than
//        defense-in-depth behind the offline check. ---
try {
    channel_replace_channel_from_pool(
        $xmlPath,
        '_Blank_Type',
        ['replace_mode' => true, 'anchor' => '900000002.CH1'],
        [$sdaqJson, $sdaqJsonLive], [], [], [], []
    );
    check(false, 'Replace of a source with an unresolvable interface type must throw');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'replace_source_family_unknown', 'Replace rejects a source with an unresolvable interface type with replace_source_family_unknown (got ' . $e->apiCode() . ')');
}

// _Blank_Type's Replace attempt above always failed, so it is still sitting
// in the file with its empty INTERFACE_TYPE. Since Phase A4's whole-document
// gate (plan §6.0.2, C-1) now rejects *any* write to a file containing an
// empty element -- not just an operation targeting that row -- it has to be
// removed before section 8 can write anything else, exactly like an
// operator would have to Delete a genuinely broken row in production before
// continuing to use the rest of the config.
$xmlCleanup = simplexml_load_file($xmlPath);
$cleanupIdx = 0;
foreach ($xmlCleanup->CHANNEL as $ch) {
    if ((string)$ch->ISO_CHANNEL === '_Blank_Type') {
        unset($xmlCleanup->CHANNEL[$cleanupIdx]);
        break;
    }
    $cleanupIdx++;
}
file_put_contents($xmlPath, $xmlCleanup->asXML());

// --- 8) SDAQ Unit is an explicit contract error on every write endpoint. ---
try {
    channel_replace_channel_from_pool(
        $xmlPath,
        '_Old_SDAQ_Known2',
        ['replace_mode' => true, 'anchor' => '900000002.CH1', 'unit' => 'HACKED'],
        [$sdaqJson, $sdaqJsonLive], [], [], [], []
    );
    check(false, 'Replace rejects a supplied SDAQ Unit');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'sdaq_unit_not_allowed', 'Replace rejects a supplied SDAQ Unit with sdaq_unit_not_allowed (got ' . $e->apiCode() . ')');
}

// --- 9) Field allowlist: other smuggled metadata is ignored and Replace
//        changes only the server-resolved anchor. ---
channel_replace_channel_from_pool(
    $xmlPath,
    '_Old_SDAQ_Known2',
    [
        'replace_mode' => true,
        'anchor' => '900000002.CH1',
        'iso_channel' => '_Hacked_Rename',
        'description' => 'HACKED VIA REPLACE',
        'min' => '-999',
        'max' => '999',
    ],
    [$sdaqJson, $sdaqJsonLive], [], [], [], []
);
$xml3 = simplexml_load_file($xmlPath);
$after = null;
foreach ($xml3->CHANNEL as $ch) {
    if ((string)$ch->ISO_CHANNEL === '_Old_SDAQ_Known2') {
        $after = $ch;
    }
}
check($after !== null, 'Replaced channel is still found under its original ISO_CHANNEL (iso_channel field was ignored)');
if ($after !== null) {
    check((string)$after->ANCHOR === '900000002.CH1', 'Replace still updates ANCHOR to the resolved candidate (got ' . (string)$after->ANCHOR . ')');
    check((string)$after->DESCRIPTION === 'original description', 'Replace ignores a smuggled description field (got ' . var_export((string)$after->DESCRIPTION, true) . ')');
    check((string)$after->MIN === '-40', 'Replace ignores a smuggled min field (got ' . var_export((string)$after->MIN, true) . ')');
    check((string)$after->MAX === '150', 'Replace ignores a smuggled max field (got ' . var_export((string)$after->MAX, true) . ')');
}
$hackedFound = false;
foreach ($xml3->CHANNEL as $ch) {
    if ((string)$ch->ISO_CHANNEL === '_Hacked_Rename') {
        $hackedFound = true;
    }
}
check(!$hackedFound, 'Replace does not create/rename a channel via a smuggled iso_channel field');

echo "\n{$g_checks} checks, " . ($g_checks - $g_failures) . " passed, {$g_failures} failed\n";
exit($g_failures === 0 ? 0 : 1);
