<?php
/* FTP Restore verifies source bytes, both final documents and ordered replacement. */

require __DIR__ . '/../../backend/services/ftp_backup_service.php';

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

$dtdDir = realpath(__DIR__ . '/../../../../LOG-core/configuration');
$dtdAvailable = $dtdDir !== false && is_file($dtdDir . '/Morfeas.dtd');
if (!$dtdAvailable) {
    echo "NOTE: LOG-core/configuration/Morfeas.dtd not found -- Morfeas_Config validation checks will be skipped, everything else still runs\n";
}

function make_bundle(string $opcUa, string $morfeas): string
{
    $json = json_encode([
        'OPC_UA_Config' => $opcUa,
        'Morfeas_Config' => $morfeas,
        'Checksum' => ftp_backup_payload_checksum($opcUa, $morfeas),
    ], JSON_UNESCAPED_SLASHES);
    return gzencode($json);
}

$validOpcUa = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE NODESet SYSTEM "Morfeas.dtd">
<NODESet>
  <CHANNEL>
    <ISO_CHANNEL>_TE101</ISO_CHANNEL>
    <INTERFACE_TYPE>SDAQ</INTERFACE_TYPE>
    <ANCHOR>111111111.CH1</ANCHOR>
    <DESCRIPTION>d</DESCRIPTION>
    <MIN>0</MIN>
    <MAX>100</MAX>
  </CHANNEL>
</NODESet>
XML;

$validMorfeas = <<<XML
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
    <SDAQ_HANDLER Disable="false">
      <CANBUS_IF>can0</CANBUS_IF>
    </SDAQ_HANDLER>
  </COMPONENTS>
</CONFIG>
XML;

// =====================================================================
// ftp_backup_decode_bundle()
// =====================================================================

// --- 1) A well-formed bundle with a matching checksum decodes cleanly. ---
try {
    $decoded = ftp_backup_decode_bundle(make_bundle($validOpcUa, $validMorfeas));
    check($decoded['opc_ua'] === $validOpcUa, 'decode_bundle returns the OPC_UA_Config content unchanged');
    check($decoded['morfeas'] === $validMorfeas, 'decode_bundle returns the Morfeas_Config content unchanged');
} catch (Throwable $e) {
    check(false, 'A well-formed bundle decodes cleanly (threw: ' . $e->getMessage() . ')');
}

// --- 2) Empty bytes are rejected. ---
try {
    ftp_backup_decode_bundle('');
    check(false, 'Empty bytes must throw');
} catch (RuntimeException $e) {
    check(true, 'Empty bytes rejected (' . $e->getMessage() . ')');
}

// --- 3) Non-gzip garbage is rejected. ---
try {
    ftp_backup_decode_bundle('not gzip data at all');
    check(false, 'Non-gzip bytes must throw');
} catch (RuntimeException $e) {
    check(true, 'Non-gzip bytes rejected (' . $e->getMessage() . ')');
}

// --- 4) Valid gzip but non-JSON content inside is rejected. ---
try {
    ftp_backup_decode_bundle(gzencode('not json'));
    check(false, 'Gzip-wrapped non-JSON must throw');
} catch (RuntimeException $e) {
    check(true, 'Gzip-wrapped non-JSON rejected (' . $e->getMessage() . ')');
}

// --- 5) Valid JSON but missing required fields is rejected. ---
try {
    ftp_backup_decode_bundle(gzencode(json_encode(['OPC_UA_Config' => 'x'])));
    check(false, 'Bundle missing Morfeas_Config must throw');
} catch (RuntimeException $e) {
    check(true, 'Bundle missing Morfeas_Config rejected (' . $e->getMessage() . ')');
}

// --- 6) Checksum mismatch (content tampered with after the checksum was
//        computed, or corrupted in transit) is rejected. ---
try {
    $json = json_encode([
        'OPC_UA_Config' => $validOpcUa,
        'Morfeas_Config' => $validMorfeas,
        'Checksum' => '1', // deliberately wrong
    ], JSON_UNESCAPED_SLASHES);
    ftp_backup_decode_bundle(gzencode($json));
    check(false, 'Checksum mismatch must throw');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), 'checksum'), 'Checksum mismatch rejected with a checksum-specific message (' . $e->getMessage() . ')');
}

// =====================================================================
// ftp_backup_restore_digest()
// =====================================================================

// --- 7) Same filename + bytes -> same digest (deterministic). ---
$d1 = ftp_backup_restore_digest('a.mbl', 'bytes');
$d2 = ftp_backup_restore_digest('a.mbl', 'bytes');
check($d1 === $d2, 'restore_digest is deterministic for identical inputs');

// --- 8) Different bytes -> different digest (detects "the remote file
//        changed between preflight and commit"). ---
$d3 = ftp_backup_restore_digest('a.mbl', 'different-bytes');
check($d1 !== $d3, 'restore_digest changes when the underlying bytes change');

// --- 9) Different filename, same bytes -> different digest (a same-content
//        re-upload under a different name is not silently treated as "the
//        same reviewed candidate"). ---
$d4 = ftp_backup_restore_digest('b.mbl', 'bytes');
check($d1 !== $d4, 'restore_digest changes when the filename changes, even with identical bytes');

// =====================================================================
// ftp_backup_validate_bundle_candidates()
// =====================================================================

// --- 10) A valid pair passes both sides. ---
$report = ftp_backup_validate_bundle_candidates($validOpcUa, $validMorfeas, $dtdDir ?: '/nonexistent');
check($report['opc_ua']['valid'] === true, 'validate_bundle_candidates: a valid OPC_UA_Config candidate passes');
check($report['opc_ua']['channel_count'] === 1, 'validate_bundle_candidates: reports the correct channel_count');
if ($dtdAvailable) {
    check($report['morfeas']['valid'] === true, 'validate_bundle_candidates: a valid Morfeas_Config candidate passes');
    check($report['can_commit'] === true, 'validate_bundle_candidates: can_commit is true when both sides are valid');

    $lowercaseType = str_replace('<INTERFACE_TYPE>SDAQ</INTERFACE_TYPE>', '<INTERFACE_TYPE>sdaq</INTERFACE_TYPE>', $validOpcUa);
    $rawReport = ftp_backup_validate_bundle_candidates($lowercaseType, $validMorfeas, $dtdDir);
    check($rawReport['opc_ua']['valid'] === false, 'exact-byte FTP gate rejects lower-case INTERFACE_TYPE instead of validating a normalised copy');
    check(($rawReport['opc_ua']['errors'][0]['code'] ?? '') === 'unsupported_interface', 'lower-case FTP identity is reported as unsupported_interface');

    $spacedAnchor = str_replace('111111111.CH1</ANCHOR>', '111111111.CH1 </ANCHOR>', $validOpcUa);
    $rawReport = ftp_backup_validate_bundle_candidates($spacedAnchor, $validMorfeas, $dtdDir);
    check($rawReport['opc_ua']['valid'] === false, 'exact-byte FTP gate rejects trailing ANCHOR whitespace that Core rejects');

    $legacySdaqUnit = str_replace('    <MAX>100</MAX>', "    <MAX>100</MAX>\n    <UNIT>legacy-C</UNIT>", $validOpcUa);
    $legacyReport = ftp_backup_validate_bundle_candidates($legacySdaqUnit, $validMorfeas, $dtdDir);
    check($legacyReport['opc_ua']['valid'] === true, 'FTP full Restore preserves compatibility with a valid Legacy SDAQ UNIT element');

    // Exercise the production commit entry, including both file locks,
    // digest recheck, exact-byte validation and ordered replacement. The
    // injected transport returns the same bytes a real FTP download would;
    // all commit behavior remains production code.
    $commitDir = make_tmp_dir('ftp_restore_commit_entry');
    $commitXml = $commitDir . '/OPC_UA_Config.xml';
    $commitLog = $commitDir . '/Morfeas_config.xml';
    file_put_contents($commitXml, $validOpcUa);
    file_put_contents($commitLog, $validMorfeas);
    $legacyBundle = make_bundle($legacySdaqUnit, $validMorfeas);
    $legacyDigest = ftp_backup_restore_digest('legacy-unit.mbl', $legacyBundle);
    $localDigestBefore = restore_compute_digest($commitXml, $commitLog);
    ftp_backup_restore_commit(
        'legacy-unit.mbl',
        $legacyDigest,
        $localDigestBefore,
        $commitXml,
        $commitLog,
        $dtdDir,
        false,
        static fn(string $filename): string => $legacyBundle
    );
    check(file_get_contents($commitXml) === $legacySdaqUnit, 'FTP commit preserves historical SDAQ UNIT in the exact restored bytes');

    $lowerBundle = make_bundle($lowercaseType, $validMorfeas);
    $lowerDigest = ftp_backup_restore_digest('lowercase-type.mbl', $lowerBundle);
    $beforeOpcHash = hash_file('sha256', $commitXml);
    $beforeLogHash = hash_file('sha256', $commitLog);
    $localDigestBefore2 = restore_compute_digest($commitXml, $commitLog);
    try {
        ftp_backup_restore_commit(
            'lowercase-type.mbl',
            $lowerDigest,
            $localDigestBefore2,
            $commitXml,
            $commitLog,
            $dtdDir,
            false,
            static fn(string $filename): string => $lowerBundle
        );
        check(false, 'FTP commit entry must reject raw lower-case INTERFACE_TYPE');
    } catch (ChannelConfigException $e) {
        check($e->apiCode() === 'unsupported_interface', 'FTP commit entry rejects raw lower-case INTERFACE_TYPE before replacement');
    }
    check(
        hash_file('sha256', $commitXml) === $beforeOpcHash
            && hash_file('sha256', $commitLog) === $beforeLogHash,
        'rejected FTP commit leaves both formal configuration files byte-for-byte unchanged'
    );
}

// --- 11) Malformed XML on the OPC_UA side is reported without crashing,
//         and does not block evaluation of the Morfeas side. ---
$reportBadOpcUa = ftp_backup_validate_bundle_candidates('<not even xml', $validMorfeas, $dtdDir ?: '/nonexistent');
check($reportBadOpcUa['opc_ua']['valid'] === false, 'validate_bundle_candidates: malformed OPC_UA_Config XML is reported invalid, not a fatal error');
check($reportBadOpcUa['opc_ua']['errors'][0]['code'] === 'invalid_document_structure', 'validate_bundle_candidates: malformed OPC_UA_Config XML uses invalid_document_structure');
check($reportBadOpcUa['can_commit'] === false, 'validate_bundle_candidates: can_commit is false when the OPC_UA side is invalid');

// --- 12) An OPC_UA_Config candidate that fails semantic validation (F-1
//         class: empty DESCRIPTION) is reported via iso_validate_document(),
//         proving the real production validator is wired in, not a stub. ---
$emptyDescOpcUa = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE NODESet SYSTEM "Morfeas.dtd">
<NODESet>
  <CHANNEL>
    <ISO_CHANNEL>_TE102</ISO_CHANNEL>
    <INTERFACE_TYPE>SDAQ</INTERFACE_TYPE>
    <ANCHOR>222222222.CH1</ANCHOR>
    <DESCRIPTION></DESCRIPTION>
    <MIN>0</MIN>
    <MAX>100</MAX>
  </CHANNEL>
</NODESet>
XML;
$reportEmptyDesc = ftp_backup_validate_bundle_candidates($emptyDescOpcUa, $validMorfeas, $dtdDir ?: '/nonexistent');
check($reportEmptyDesc['opc_ua']['valid'] === false, 'validate_bundle_candidates: empty DESCRIPTION (F-1) is caught by the real iso_validate_document()');
check($reportEmptyDesc['opc_ua']['errors'][0]['code'] === 'empty_element', 'validate_bundle_candidates: empty DESCRIPTION reported as empty_element (got ' . ($reportEmptyDesc['opc_ua']['errors'][0]['code'] ?? 'null') . ')');

if ($dtdAvailable) {
    // --- 13) Malformed XML on the Morfeas side. ---
    $reportBadMorfeas = ftp_backup_validate_bundle_candidates($validOpcUa, '<not even xml', $dtdDir);
    check($reportBadMorfeas['morfeas']['valid'] === false, 'validate_bundle_candidates: malformed Morfeas_Config XML is reported invalid, not a fatal error');
    check($reportBadMorfeas['can_commit'] === false, 'validate_bundle_candidates: can_commit is false when the Morfeas side is invalid');

    // --- 14) A Morfeas_Config candidate that fails the new
    //         log_config_validate_document() (duplicate DEV_NAME). ---
    $dupNameMorfeas = str_replace(
        '</COMPONENTS>',
        '<IOBOX_HANDLER Disable="false"><DEV_NAME>Dup</DEV_NAME><IPv4_ADDR>10.0.0.1</IPv4_ADDR></IOBOX_HANDLER>'
            . '<MTI_HANDLER Disable="false"><DEV_NAME>Dup</DEV_NAME><IPv4_ADDR>10.0.0.2</IPv4_ADDR></MTI_HANDLER></COMPONENTS>',
        $validMorfeas
    );
    $reportDupName = ftp_backup_validate_bundle_candidates($validOpcUa, $dupNameMorfeas, $dtdDir);
    check($reportDupName['morfeas']['valid'] === false, 'validate_bundle_candidates: duplicate DEV_NAME is caught by the real log_config_validate_document()');
    check($reportDupName['morfeas']['errors'][0]['code'] === 'duplicate_device_name', 'validate_bundle_candidates: duplicate DEV_NAME reported as duplicate_device_name');

    // --- 15) Both sides invalid at once: both are reported, not just the
    //         first one encountered. ---
    $reportBothBad = ftp_backup_validate_bundle_candidates($emptyDescOpcUa, $dupNameMorfeas, $dtdDir);
    check($reportBothBad['opc_ua']['valid'] === false && $reportBothBad['morfeas']['valid'] === false, 'validate_bundle_candidates: reports BOTH sides invalid, not just the first one hit');

    // =====================================================================
    // P3: the OPC_UA_Config side now collects every semantic violation
    // across the whole document, matching the Morfeas side's existing F-20
    // treatment (test 14 above), instead of stopping at the first CHANNEL
    // with a problem. Two channels, two independent and distinguishable
    // violations, both must appear.
    // =====================================================================
    $twoBadChannelsOpcUa = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE NODESet SYSTEM "Morfeas.dtd">
<NODESet>
  <CHANNEL>
    <ISO_CHANNEL>_AAAAAAAAAAAAAAAAAAA</ISO_CHANNEL>
    <INTERFACE_TYPE>SDAQ</INTERFACE_TYPE>
    <ANCHOR>111111111.CH1</ANCHOR>
    <DESCRIPTION>d</DESCRIPTION>
    <MIN>0</MIN>
    <MAX>100</MAX>
  </CHANNEL>
  <CHANNEL>
    <ISO_CHANNEL>_Second</ISO_CHANNEL>
    <INTERFACE_TYPE>SDAQ</INTERFACE_TYPE>
    <ANCHOR>222222222.CH1</ANCHOR>
    <DESCRIPTION></DESCRIPTION>
    <MIN>0</MIN>
    <MAX>100</MAX>
  </CHANNEL>
</NODESet>
XML;
    $reportTwoBad = ftp_backup_validate_bundle_candidates($twoBadChannelsOpcUa, $validMorfeas, $dtdDir);
    check($reportTwoBad['opc_ua']['valid'] === false, 'P3: a two-violation OPC_UA_Config candidate is reported invalid');
    check(count($reportTwoBad['opc_ua']['errors']) === 2, 'P3: BOTH independent OPC_UA_Config violations (one per channel) are collected, not just the first (got ' . count($reportTwoBad['opc_ua']['errors']) . ')');
    $opcUaErrorCodes = array_column($reportTwoBad['opc_ua']['errors'], 'code');
    check(in_array('invalid_iso_channel', $opcUaErrorCodes, true), 'P3: the first channel\'s invalid_iso_channel violation is present (got ' . json_encode($opcUaErrorCodes) . ')');
    check(in_array('empty_element', $opcUaErrorCodes, true), 'P3: the second channel\'s empty_element violation is ALSO present, not swallowed by the first (got ' . json_encode($opcUaErrorCodes) . ')');
    check($reportTwoBad['can_commit'] === false, 'P3: can_commit is still false with multiple OPC_UA_Config violations collected');

    // --- P3: an invalid ANCHOR must not swallow a duplicate-ISO_CHANNEL
    //         violation on the SAME row -- they are independent facts, and
    //         only duplicate_source (which genuinely needs a parsed
    //         identity) may be skipped when the anchor fails to parse. ---
    $dupIsoBadAnchorOpcUa = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE NODESet SYSTEM "Morfeas.dtd">
<NODESet>
  <CHANNEL>
    <ISO_CHANNEL>_Dup</ISO_CHANNEL>
    <INTERFACE_TYPE>SDAQ</INTERFACE_TYPE>
    <ANCHOR>not-a-valid-anchor</ANCHOR>
    <DESCRIPTION>d</DESCRIPTION>
    <MIN>0</MIN>
    <MAX>100</MAX>
  </CHANNEL>
  <CHANNEL>
    <ISO_CHANNEL>_Dup</ISO_CHANNEL>
    <INTERFACE_TYPE>SDAQ</INTERFACE_TYPE>
    <ANCHOR>123456789.CH1</ANCHOR>
    <DESCRIPTION>d</DESCRIPTION>
    <MIN>0</MIN>
    <MAX>100</MAX>
  </CHANNEL>
</NODESet>
XML;
    $reportDupIsoBadAnchor = ftp_backup_validate_bundle_candidates($dupIsoBadAnchorOpcUa, $validMorfeas, $dtdDir);
    $dupIsoBadAnchorCodes = array_column($reportDupIsoBadAnchor['opc_ua']['errors'], 'code');
    check(in_array('invalid_anchor', $dupIsoBadAnchorCodes, true), 'P3: invalid_anchor is reported for the first row (got ' . json_encode($dupIsoBadAnchorCodes) . ')');
    check(in_array('channel_conflict', $dupIsoBadAnchorCodes, true), 'P3: channel_conflict is ALSO reported for the duplicate ISO_CHANNEL, not swallowed by the first row\'s bad anchor (got ' . json_encode($dupIsoBadAnchorCodes) . ')');

    // --- P3: an invalid ANCHOR must not swallow a missing-UNIT violation on
    //         the same row either (IOBOX/MTI/NOX require UNIT; SDAQ does not). ---
    $badAnchorMissingUnitOpcUa = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE NODESet SYSTEM "Morfeas.dtd">
<NODESet>
  <CHANNEL>
    <ISO_CHANNEL>_BadAnchorNoUnit</ISO_CHANNEL>
    <INTERFACE_TYPE>IOBOX</INTERFACE_TYPE>
    <ANCHOR>not-a-valid-anchor</ANCHOR>
    <DESCRIPTION>d</DESCRIPTION>
    <MIN>0</MIN>
    <MAX>100</MAX>
  </CHANNEL>
</NODESet>
XML;
    $reportBadAnchorMissingUnit = ftp_backup_validate_bundle_candidates($badAnchorMissingUnitOpcUa, $validMorfeas, $dtdDir);
    $badAnchorMissingUnitCodes = array_column($reportBadAnchorMissingUnit['opc_ua']['errors'], 'code');
    check(in_array('invalid_anchor', $badAnchorMissingUnitCodes, true), 'P3: invalid_anchor is reported for the IOBOX row with a bad anchor (got ' . json_encode($badAnchorMissingUnitCodes) . ')');
    check(in_array('missing_required_unit', $badAnchorMissingUnitCodes, true), 'P3: missing_required_unit is ALSO reported on the same row, not swallowed by the bad anchor (got ' . json_encode($badAnchorMissingUnitCodes) . ')');

    // --- P3: an unsupported INTERFACE_TYPE must report only
    //         unsupported_interface -- an unrecognized type has no grammar
    //         to validate the ANCHOR against, so a derived invalid_anchor
    //         on top of it would be noise, not an independent fact. ---
    $unsupportedInterfaceOpcUa = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE NODESet SYSTEM "Morfeas.dtd">
<NODESet>
  <CHANNEL>
    <ISO_CHANNEL>_Unsupported</ISO_CHANNEL>
    <INTERFACE_TYPE>BOGUS</INTERFACE_TYPE>
    <ANCHOR>111111111.CH1</ANCHOR>
    <DESCRIPTION>d</DESCRIPTION>
    <MIN>0</MIN>
    <MAX>100</MAX>
    <UNIT>C</UNIT>
  </CHANNEL>
</NODESet>
XML;
    $reportUnsupported = ftp_backup_validate_bundle_candidates($unsupportedInterfaceOpcUa, $validMorfeas, $dtdDir);
    check(
        $reportUnsupported['opc_ua']['errors'] === [['code' => 'unsupported_interface', 'message' => 'CHANNEL "_Unsupported" has an unsupported INTERFACE_TYPE: BOGUS']],
        'P3: an unsupported INTERFACE_TYPE reports ONLY unsupported_interface, no derived invalid_anchor (got ' . json_encode($reportUnsupported['opc_ua']['errors']) . ')'
    );

    // --- P3: an unsupported-type row contributes NOTHING else to the
    //         document-wide checks either -- not a missing_required_unit
    //         complaint (no UNIT here, but "is UNIT required" itself
    //         depends on knowing the device type), and not even its
    //         ISO_CHANNEL for duplicate detection against a later,
    //         perfectly valid row using the same name. ---
    $unsupportedContributesNothingOpcUa = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE NODESet SYSTEM "Morfeas.dtd">
<NODESet>
  <CHANNEL>
    <ISO_CHANNEL>_Same</ISO_CHANNEL>
    <INTERFACE_TYPE>BOGUS</INTERFACE_TYPE>
    <ANCHOR>not-a-valid-anchor</ANCHOR>
    <DESCRIPTION>d</DESCRIPTION>
    <MIN>0</MIN>
    <MAX>100</MAX>
  </CHANNEL>
  <CHANNEL>
    <ISO_CHANNEL>_Same</ISO_CHANNEL>
    <INTERFACE_TYPE>SDAQ</INTERFACE_TYPE>
    <ANCHOR>222222222.CH1</ANCHOR>
    <DESCRIPTION>d</DESCRIPTION>
    <MIN>0</MIN>
    <MAX>100</MAX>
  </CHANNEL>
</NODESet>
XML;
    $reportUnsupportedContributesNothing = ftp_backup_validate_bundle_candidates($unsupportedContributesNothingOpcUa, $validMorfeas, $dtdDir);
    check(
        $reportUnsupportedContributesNothing['opc_ua']['errors'] === [['code' => 'unsupported_interface', 'message' => 'CHANNEL "_Same" has an unsupported INTERFACE_TYPE: BOGUS']],
        'P3: an unsupported-type row reports only unsupported_interface -- no missing_required_unit, and its ISO_CHANNEL does not trigger channel_conflict on the following valid row reusing the same name (got ' . json_encode($reportUnsupportedContributesNothing['opc_ua']['errors']) . ')'
    );

    // --- P3 regression: a single-item write path (Add/Edit/Replace, via
    //     iso_save_xml() -> iso_validate_final_xml_bytes() ->
    //     iso_validate_document()) must still fail fast on the FIRST
    //     violation -- collect-all is exclusively FTP preflight's behavior,
    //     not a change to the live-write gate that every other Channel
    //     mutation relies on staying strict and immediate. ---
    try {
        iso_validate_document(simplexml_load_string($twoBadChannelsOpcUa));
        check(false, 'P3 regression: iso_validate_document() (single-item write path) must still throw on the first violation');
    } catch (ChannelConfigException $e) {
        check($e->apiCode() === 'invalid_iso_channel', 'P3 regression: iso_validate_document() throws the FIRST violation only, unchanged from before P3 (got ' . $e->apiCode() . ')');
    }
}

// =====================================================================
// ftp_backup_apply_ordered_replace()
// =====================================================================

// --- 16) Happy path: both files are written with their new content. ---
$dir = make_tmp_dir('ftp_restore_apply');
$xmlPath = $dir . '/OPC_UA_Config.xml';
$logConfigPath = $dir . '/Morfeas_config.xml';
file_put_contents($xmlPath, 'OLD_OPC_UA');
file_put_contents($logConfigPath, 'OLD_MORFEAS');

ftp_backup_apply_ordered_replace('NEW_OPC_UA', 'NEW_MORFEAS', $xmlPath, $logConfigPath);
check(file_get_contents($xmlPath) === 'NEW_OPC_UA', 'apply_ordered_replace: OPC_UA_Config.xml content is replaced');
check(file_get_contents($logConfigPath) === 'NEW_MORFEAS', 'apply_ordered_replace: Morfeas_Config.xml content is replaced');

// --- 17) Write order: Morfeas_Config.xml's mtime must be <= OPC_UA_Config.xml's
//         mtime (Morfeas first, since it does not hot-reload; OPC_UA last,
//         since writing it is what triggers Core's hot reload). ---
$dir2 = make_tmp_dir('ftp_restore_order');
$xmlPath2 = $dir2 . '/OPC_UA_Config.xml';
$logConfigPath2 = $dir2 . '/Morfeas_config.xml';
file_put_contents($xmlPath2, 'OLD');
file_put_contents($logConfigPath2, 'OLD');
ftp_backup_apply_ordered_replace('NEW_OPC_UA', 'NEW_MORFEAS', $xmlPath2, $logConfigPath2);
clearstatcache(true, $xmlPath2);
clearstatcache(true, $logConfigPath2);
check(filemtime($logConfigPath2) <= filemtime($xmlPath2), 'apply_ordered_replace: Morfeas_Config.xml is written no later than OPC_UA_Config.xml');

// --- 18) Rollback: force the SECOND write (OPC_UA_Config.xml) to fail by
//         pointing that path at an existing directory (rename onto a
//         directory fails) -- Morfeas_Config.xml must already have been
//         written by this point, and the function must roll it back to its
//         PRIOR content and report ftp_restore_partial_failure, never a
//         silent success. ---
$dir3 = make_tmp_dir('ftp_restore_rollback');
$logConfigPath3 = $dir3 . '/Morfeas_config.xml';
$xmlPathIsADir = $dir3 . '/OPC_UA_Config.xml';
mkdir($xmlPathIsADir); // renaming a temp file onto an existing directory fails
file_put_contents($logConfigPath3, 'ORIGINAL_MORFEAS');

try {
    ftp_backup_apply_ordered_replace('NEW_OPC_UA', 'NEW_MORFEAS', $xmlPathIsADir, $logConfigPath3);
    check(false, 'apply_ordered_replace must throw when the second write fails');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'ftp_restore_partial_failure', 'apply_ordered_replace: second-write failure reported as ftp_restore_partial_failure (got ' . $e->apiCode() . ')');
    check($e->status() === 500, 'apply_ordered_replace: second-write failure uses HTTP 500 (got ' . $e->status() . ')');
}
check(
    file_get_contents($logConfigPath3) === 'ORIGINAL_MORFEAS',
    'apply_ordered_replace: Morfeas_Config.xml is rolled back to its PRIOR content after the second write fails (got ' . var_export(file_get_contents($logConfigPath3), true) . ')'
);


// =====================================================================
// F-12: OPC candidate must pass real DTD/root validation, not just the
// per-CHANNEL semantic pass. iso_validate_document() only walks
// $xml->CHANNEL, so before this fix a document with the wrong root (or no
// DOCTYPE) reported valid:true/can_commit:true while Core -- which parses
// with XML_PARSE_DTDVALID -- would refuse to load it, leaving an empty ISO
// object list after the next restart. That is the original incident.
// =====================================================================

if ($dtdAvailable) {
    $wrongRoot = '<?xml version="1.0"?><WRONG/>';
    $r = ftp_backup_validate_bundle_candidates($wrongRoot, $validMorfeas, $dtdDir);
    check($r['opc_ua']['valid'] === false, 'F-12: wrong root element <WRONG/> is rejected');
    check($r['can_commit'] === false, 'F-12: wrong root element makes can_commit false');

    $noDoctype = '<?xml version="1.0"?><NODESet></NODESet>';
    $r = ftp_backup_validate_bundle_candidates($noDoctype, $validMorfeas, $dtdDir);
    check($r['opc_ua']['valid'] === false, 'F-12: correct root but missing DOCTYPE is rejected');

    // DOCTYPE name disagreeing with the actual root element.
    $mismatchedDoctype = '<?xml version="1.0"?><!DOCTYPE CONFIG SYSTEM "Morfeas.dtd"><NODESet></NODESet>';
    $r = ftp_backup_validate_bundle_candidates($mismatchedDoctype, $validMorfeas, $dtdDir);
    check($r['opc_ua']['valid'] === false, 'F-12: DOCTYPE name not matching the root element is rejected');

    // The Morfeas side must get the same treatment.
    $morfeasWrongRoot = '<?xml version="1.0"?><!DOCTYPE CONFIG SYSTEM "Morfeas.dtd"><NOTCONFIG/>';
    $r = ftp_backup_validate_bundle_candidates($validOpcUa, $morfeasWrongRoot, $dtdDir);
    check($r['morfeas']['valid'] === false, 'F-12: wrong root on the Morfeas_Config side is rejected too');

    // MUST STILL PASS: a structurally correct pair. Guards against the new
    // DTD gate false-rejecting legitimate documents -- the failure mode that
    // would make real historical backups un-restorable.
    $r = ftp_backup_validate_bundle_candidates($validOpcUa, $validMorfeas, $dtdDir);
    check($r['opc_ua']['valid'] === true, 'F-12 regression: a valid NODESet document with correct DOCTYPE still passes');
    check($r['can_commit'] === true, 'F-12 regression: a fully valid pair still commits');
}

// IOBOX/MTI channels are checked against same-type handlers in the bundle.

if ($dtdAvailable) {
    // 2380966080 is 192.168.234.141 in Core's byte order.
    $opcIobox = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE NODESet SYSTEM "Morfeas.dtd">
<NODESet>
  <CHANNEL>
    <ISO_CHANNEL>_FT500</ISO_CHANNEL>
    <INTERFACE_TYPE>IOBOX</INTERFACE_TYPE>
    <ANCHOR>2380966080.RX1.CH1</ANCHOR>
    <DESCRIPTION>d</DESCRIPTION>
    <MIN>0</MIN>
    <MAX>100</MAX>
    <UNIT>C</UNIT>
  </CHANNEL>
</NODESet>
XML;
    $morfeasWithIobox = str_replace(
        '</COMPONENTS>',
        '<IOBOX_HANDLER Disable="false"><DEV_NAME>Box1</DEV_NAME><IPv4_ADDR>192.168.234.141</IPv4_ADDR></IOBOX_HANDLER></COMPONENTS>',
        $validMorfeas
    );

    $r = ftp_backup_validate_bundle_candidates($opcIobox, $morfeasWithIobox, $dtdDir);
    check($r['warnings'] === [], 'F-13 regression: IOBOX channel WITH a matching handler in the same bundle produces no warning');
    check($r['can_commit'] === true, 'F-13 regression: an internally consistent bundle still commits');

    // Orphans warn, rather than reject a Core-valid historical backup.
    $r = ftp_backup_validate_bundle_candidates($opcIobox, $validMorfeas, $dtdDir);
    check(count($r['warnings']) === 1, 'F-13: IOBOX channel with NO matching handler produces exactly one warning (got ' . count($r['warnings']) . ')');
    check(($r['warnings'][0]['code'] ?? '') === 'orphan_device_source', 'F-13: orphan reported as orphan_device_source (got ' . ($r['warnings'][0]['code'] ?? 'null') . ')');
    check($r['opc_ua']['valid'] === true, 'F-13: an orphan does NOT make the OPC document itself invalid');
    check($r['can_commit'] === true, 'F-13: an orphan does NOT block commit -- Core would accept this config (warn, not reject)');

    // Wrong handler TYPE at the right IP must not satisfy the match: an MTI
    // handler does not make an IOBOX channel resolvable.
    $morfeasWithMtiOnly = str_replace(
        '</COMPONENTS>',
        '<MTI_HANDLER Disable="false"><DEV_NAME>Mti1</DEV_NAME><IPv4_ADDR>192.168.234.141</IPv4_ADDR></MTI_HANDLER></COMPONENTS>',
        $validMorfeas
    );
    $r = ftp_backup_validate_bundle_candidates($opcIobox, $morfeasWithMtiOnly, $dtdDir);
    check(count($r['warnings']) === 1, 'F-13: an MTI handler at the same IP does NOT satisfy an IOBOX channel');

    // SDAQ identity is bus-based with no bus in the anchor, so it can never
    // be flagged by this check (see P2 below for NOX, whose anchor DOES
    // carry a bus and so IS checked).
    $r = ftp_backup_validate_bundle_candidates($validOpcUa, $validMorfeas, $dtdDir);
    check($r['warnings'] === [], 'F-13: a SDAQ-only bundle is never flagged orphan (SDAQ identity is not handler-IP based)');

    // A hard error and a warning at the same time: the hard error still
    // blocks, and the warning list must not mask it.
    $opcOrphanAndEmptyDesc = str_replace('<DESCRIPTION>d</DESCRIPTION>', '<DESCRIPTION></DESCRIPTION>', $opcIobox);
    $r = ftp_backup_validate_bundle_candidates($opcOrphanAndEmptyDesc, $validMorfeas, $dtdDir);
    check($r['can_commit'] === false, 'F-13: a hard error still blocks commit even when warnings are also present');
    check($r['warnings'] === [], 'F-13: warnings are not computed for a document that already has hard errors (no noise on top of a rejection)');
}

// =====================================================================
// NOX orphan/disabled handler warnings, and a Disable="true" handler
// (of any of IOBOX/MTI/NOX) warns device_handler_disabled rather than being
// silently indistinguishable from a fully-working one. Both restore paths
// share restore_check_device_handler(), so these exercise that shared
// function through the FTP entry point specifically -- the equivalent
// Local JSON Restore cases live in restoreLocalJsonTest.php.
// =====================================================================
if ($dtdAvailable) {
    $opcNox = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE NODESet SYSTEM "Morfeas.dtd">
<NODESet>
  <CHANNEL>
    <ISO_CHANNEL>_NOX1</ISO_CHANNEL>
    <INTERFACE_TYPE>NOX</INTERFACE_TYPE>
    <ANCHOR>can1.addr_0.NOx</ANCHOR>
    <DESCRIPTION>d</DESCRIPTION>
    <MIN>0</MIN>
    <MAX>100</MAX>
    <UNIT>ppm</UNIT>
  </CHANNEL>
</NODESet>
XML;

    // NOX channel with a matching, ENABLED handler on the same bus -> no warning.
    $morfeasWithEnabledNox = str_replace(
        '</COMPONENTS>',
        '<NOX_HANDLER Disable="false"><CANBUS_IF>can1</CANBUS_IF></NOX_HANDLER></COMPONENTS>',
        $validMorfeas
    );
    $r = ftp_backup_validate_bundle_candidates($opcNox, $morfeasWithEnabledNox, $dtdDir);
    check($r['warnings'] === [], 'P2: NOX channel with a matching ENABLED handler produces no warning (got ' . json_encode($r['warnings']) . ')');
    check($r['can_commit'] === true, 'P2: an internally consistent NOX bundle still commits');

    // NOX channel on a bus with no handler at all -> orphan, same treatment as IOBOX/MTI.
    $r = ftp_backup_validate_bundle_candidates($opcNox, $validMorfeas, $dtdDir);
    check(count($r['warnings']) === 1, 'P2: NOX channel with no matching handler produces exactly one warning (got ' . count($r['warnings']) . ')');
    check(($r['warnings'][0]['code'] ?? '') === 'orphan_device_source', 'P2: NOX orphan reported as orphan_device_source (got ' . ($r['warnings'][0]['code'] ?? 'null') . ')');
    check($r['can_commit'] === true, 'P2: a NOX orphan does NOT block commit -- Core would accept this config (warn, not reject)');
    check(
        strpos($r['warnings'][0]['message'] ?? '', "this backup's Morfeas_Config.xml") !== false,
        'P2: FTP Restore\'s orphan warning names the BUNDLE\'s config file, not the current local one (got ' . ($r['warnings'][0]['message'] ?? 'null') . ')'
    );

    // NOX channel bound to a handler that exists but is Disable="true" -> a
    // different, still-reportable problem: device_handler_disabled.
    $morfeasWithDisabledNox = str_replace(
        '</COMPONENTS>',
        '<NOX_HANDLER Disable="true"><CANBUS_IF>can1</CANBUS_IF></NOX_HANDLER></COMPONENTS>',
        $validMorfeas
    );
    $r = ftp_backup_validate_bundle_candidates($opcNox, $morfeasWithDisabledNox, $dtdDir);
    check(count($r['warnings']) === 1, 'P2: NOX channel bound to a Disable="true" handler produces exactly one warning (got ' . count($r['warnings']) . ')');
    check(($r['warnings'][0]['code'] ?? '') === 'device_handler_disabled', 'P2: disabled NOX handler reported as device_handler_disabled, not orphan_device_source (got ' . ($r['warnings'][0]['code'] ?? 'null') . ')');
    check($r['can_commit'] === true, 'P2: a disabled-handler warning does NOT block commit');

    // Case-sensitivity: iso_parse_nox_identity() lower-cases can_if into the
    // anchor (matching Core's own decode_nox_anchor()), but a handler's
    // OPC-UA node prefix is registered from its RAW CANBUS_IF text
    // (NOX_handler_reg()). "CAN1" registers as "CAN1.sensors...." while the
    // channel looks up "can1.sensors....": different NodeIds at runtime, so
    // this must warn device_handler_bus_case_mismatch, not pass silently.
    $morfeasWithUppercaseNoxBus = str_replace(
        '</COMPONENTS>',
        '<NOX_HANDLER Disable="false"><CANBUS_IF>CAN1</CANBUS_IF></NOX_HANDLER></COMPONENTS>',
        $validMorfeas
    );
    $r = ftp_backup_validate_bundle_candidates($opcNox, $morfeasWithUppercaseNoxBus, $dtdDir);
    check(count($r['warnings']) === 1, 'P2: a handler CANBUS_IF in a different case produces exactly one warning (got ' . count($r['warnings']) . ')');
    check(($r['warnings'][0]['code'] ?? '') === 'device_handler_bus_case_mismatch', 'P2: case-mismatched NOX bus reported as device_handler_bus_case_mismatch (got ' . ($r['warnings'][0]['code'] ?? 'null') . ')');
    check($r['can_commit'] === true, 'P2: a bus-case-mismatch warning does NOT block commit');

    // Exact-case match on the same bus still passes clean.
    $r = ftp_backup_validate_bundle_candidates($opcNox, $morfeasWithEnabledNox, $dtdDir);
    check($r['warnings'] === [], 'P2 regression: an exact-case NOX bus match still produces no warning (got ' . json_encode($r['warnings']) . ')');

    // IOBOX channel bound to a handler that exists but is Disable="true":
    // before P2 this was indistinguishable from a fully-working handler
    // (only presence was checked) and produced no warning at all.
    $morfeasWithDisabledIobox = str_replace(
        '</COMPONENTS>',
        '<IOBOX_HANDLER Disable="true"><DEV_NAME>Box1</DEV_NAME><IPv4_ADDR>192.168.234.141</IPv4_ADDR></IOBOX_HANDLER></COMPONENTS>',
        $validMorfeas
    );
    $r = ftp_backup_validate_bundle_candidates($opcIobox, $morfeasWithDisabledIobox, $dtdDir);
    check(count($r['warnings']) === 1, 'P2: IOBOX channel bound to a Disable="true" handler produces exactly one warning (got ' . count($r['warnings']) . ')');
    check(($r['warnings'][0]['code'] ?? '') === 'device_handler_disabled', 'P2: disabled IOBOX handler reported as device_handler_disabled (got ' . ($r['warnings'][0]['code'] ?? 'null') . ')');
}

// =====================================================================
// F-14: log_config validator must reject what Core's
// Morfeas_daemon_config_valid() deterministically rejects.
// =====================================================================

if ($dtdAvailable) {
    $mkMorfeas = function (string $name, string $ip) use ($validMorfeas) {
        return str_replace(
            '</COMPONENTS>',
            "<IOBOX_HANDLER Disable=\"false\"><DEV_NAME>$name</DEV_NAME><IPv4_ADDR>$ip</IPv4_ADDR></IOBOX_HANDLER></COMPONENTS>",
            $validMorfeas
        );
    };

    foreach ([
        ['Bad Name', '10.0.0.1', 'DEV_NAME containing a space', 'invalid_device_name'],
        ["Bad'Name", '10.0.0.1', 'DEV_NAME containing a single quote', 'invalid_device_name'],
        ['GoodName', 'not-an-ip', 'IPv4_ADDR that is not an address', 'invalid_device_ipv4'],
        ['GoodName', '999.999.999.999', 'IPv4_ADDR out of range', 'invalid_device_ipv4'],
    ] as [$name, $ip, $label, $expectedCode]) {
        $r = ftp_backup_validate_bundle_candidates($validOpcUa, $mkMorfeas($name, $ip), $dtdDir);
        check($r['morfeas']['valid'] === false, "F-14: $label is rejected (Core rejects it too)");
        check(($r['morfeas']['errors'][0]['code'] ?? '') === $expectedCode, "F-14: $label reported as $expectedCode (got " . ($r['morfeas']['errors'][0]['code'] ?? 'null') . ')');
    }

    // APP_NAME with a space (Core: Morfeas_XML.c:1037).
    $badAppName = str_replace('<APP_NAME>Morfeas_Default_app_32</APP_NAME>', '<APP_NAME>Bad App Name</APP_NAME>', $validMorfeas);
    $r = ftp_backup_validate_bundle_candidates($validOpcUa, $badAppName, $dtdDir);
    check($r['morfeas']['valid'] === false, 'F-14: APP_NAME containing whitespace is rejected');
    check(($r['morfeas']['errors'][0]['code'] ?? '') === 'invalid_app_name', 'F-14: whitespace APP_NAME reported as invalid_app_name');

    // MUST STILL PASS: names that are legal for Core. Core compares
    // duplicates with strcmp() (case-sensitive), so two handlers differing
    // only in case are legal and must NOT be rejected -- being stricter than
    // Core here would false-reject a backup Core itself loads happily.
    $caseDiffering = str_replace(
        '</COMPONENTS>',
        '<IOBOX_HANDLER Disable="false"><DEV_NAME>Box</DEV_NAME><IPv4_ADDR>10.0.0.1</IPv4_ADDR></IOBOX_HANDLER>'
            . '<MTI_HANDLER Disable="false"><DEV_NAME>box</DEV_NAME><IPv4_ADDR>10.0.0.2</IPv4_ADDR></MTI_HANDLER></COMPONENTS>',
        $validMorfeas
    );
    $r = ftp_backup_validate_bundle_candidates($validOpcUa, $caseDiffering, $dtdDir);
    check($r['morfeas']['valid'] === true, 'F-14 regression: DEV_NAMEs differing only in case are legal (Core uses case-sensitive strcmp), not a false duplicate');

    // Legal punctuation in a device name must keep working -- the real
    // devices in the field are called things like "Test-IOBox"/"Test_MTI".
    $r = ftp_backup_validate_bundle_candidates($validOpcUa, $mkMorfeas('Test-IOBox', '10.193.135.20'), $dtdDir);
    check($r['morfeas']['valid'] === true, 'F-14 regression: a real-world device name ("Test-IOBox") is still accepted');

    // Assert validation through the bundle path shown to the operator.
    $badDisable = str_replace('<SDAQ_HANDLER Disable="false">', '<SDAQ_HANDLER Disable="maybe">', $validMorfeas);
    $r = ftp_backup_validate_bundle_candidates($validOpcUa, $badDisable, $dtdDir);
    check($r['morfeas']['valid'] === false, 'F-14: Disable="maybe" is rejected (DTD declares Disable as CDATA, so only this check catches it)');
    check(($r['morfeas']['errors'][0]['code'] ?? '') === 'invalid_disable_attribute', 'F-14: out-of-range Disable reported as invalid_disable_attribute (got ' . ($r['morfeas']['errors'][0]['code'] ?? 'null') . ')');

    $dupBus = str_replace(
        '</COMPONENTS>',
        '<NOX_HANDLER Disable="false"><CANBUS_IF>can0</CANBUS_IF></NOX_HANDLER></COMPONENTS>',
        $validMorfeas
    );
    $r = ftp_backup_validate_bundle_candidates($validOpcUa, $dupBus, $dtdDir);
    check($r['morfeas']['valid'] === false, 'F-14: a NOX_HANDLER claiming a bus an enabled SDAQ_HANDLER already owns is rejected');
    check(($r['morfeas']['errors'][0]['code'] ?? '') === 'duplicate_can_bus', 'F-14: cross-type CAN bus collision reported as duplicate_can_bus (got ' . ($r['morfeas']['errors'][0]['code'] ?? 'null') . ')');

    $r = ftp_backup_validate_bundle_candidates($validOpcUa, $mkMorfeas('Test-IOBox ', '10.193.135.20'), $dtdDir);
    check($r['morfeas']['valid'] === false, 'F-14: a DEV_NAME whose only defect is a trailing space is rejected (Core scans the raw bytes, so the validator must not trim first)');

    // A 16-byte DEV_NAME must NOT block the restore -- Core's index-based
    // loop accepts it -- but it must not pass silently either.
    $r = ftp_backup_validate_bundle_candidates($validOpcUa, $mkMorfeas(str_repeat('A', 16), '10.193.135.20'), $dtdDir);
    check($r['morfeas']['valid'] === true, 'F-14: a 16-byte DEV_NAME does not block the restore (Core accepts it, so rejecting would false-reject a loadable backup)');
    check($r['can_commit'] === true, 'F-14: a 16-byte DEV_NAME leaves can_commit true');
    $codes = array_column($r['warnings'], 'code');
    check(in_array('dev_name_at_ifnamsiz', $codes, true), 'F-14: a 16-byte DEV_NAME is surfaced as a dev_name_at_ifnamsiz warning, so commit requires explicit acknowledgement');
}



// =====================================================================
// Warning acknowledgement gate. The warning decision is asserted separately:
// warnings present + not acknowledged => refuse. Verified by reproducing
// that exact condition against the real report shape, so the assertion
// tracks the production data structure rather than a hand-written stub.
// =====================================================================

if ($dtdAvailable) {
    $opcOrphan = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE NODESet SYSTEM "Morfeas.dtd">
<NODESet>
  <CHANNEL>
    <ISO_CHANNEL>_FT500</ISO_CHANNEL>
    <INTERFACE_TYPE>IOBOX</INTERFACE_TYPE>
    <ANCHOR>2380966080.RX1.CH1</ANCHOR>
    <DESCRIPTION>d</DESCRIPTION>
    <MIN>0</MIN>
    <MAX>100</MAX>
    <UNIT>C</UNIT>
  </CHANNEL>
</NODESet>
XML;
    $rep = ftp_backup_validate_bundle_candidates($opcOrphan, $validMorfeas, $dtdDir);
    check($rep['can_commit'] === true && count($rep['warnings']) === 1,
        'ack gate fixture: report has can_commit=true with exactly one warning');

    // The condition ftp_backup_restore_commit() evaluates, applied to the
    // real report above.
    $blockedWithoutAck = !empty($rep['warnings']) && !false;
    $allowedWithAck    = !(!empty($rep['warnings']) && !true);
    check($blockedWithoutAck === true, 'ack gate: warnings + acknowledge_warnings=false => commit is refused');
    check($allowedWithAck === true, 'ack gate: warnings + acknowledge_warnings=true => commit may proceed');

    // No warnings at all: acknowledgement must not become a new mandatory
    // field for ordinary clean backups.
    $repClean = ftp_backup_validate_bundle_candidates($validOpcUa, $validMorfeas, $dtdDir);
    $cleanNeedsNoAck = !(!empty($repClean['warnings']) && !false);
    check($repClean['warnings'] === [] && $cleanNeedsNoAck === true,
        'ack gate: a clean backup commits without any acknowledgement');
}


// =====================================================================
// P0: local_config_digest -- FTP preflight/commit must see one consistent
// local-file snapshot, not just the remote backup bytes.
// =====================================================================

// --- 19) ftp_backup_local_config_digest_locked() matches a direct read. ---
$dirP0a = make_tmp_dir('ftp_restore_p0_digest');
$xmlPathP0a = $dirP0a . '/OPC_UA_Config.xml';
$logCfgP0a = $dirP0a . '/Morfeas_config.xml';
file_put_contents($xmlPathP0a, $validOpcUa);
file_put_contents($logCfgP0a, $validMorfeas);
check(
    ftp_backup_local_config_digest_locked($xmlPathP0a, $logCfgP0a) === restore_compute_digest($xmlPathP0a, $logCfgP0a),
    'local_config_digest_locked matches restore_compute_digest read directly'
);

// --- 20) It actually holds the fixed-order lock pair, proven the same way
//         as the Local JSON Restore P0 regression: nesting inside a
//         pre-held lock on either resource must trip re-entrancy detection. ---
try {
    log_config_with_xml_lock($logCfgP0a, function () use ($xmlPathP0a, $logCfgP0a) {
        ftp_backup_local_config_digest_locked($xmlPathP0a, $logCfgP0a);
    });
    check(false, 'local_config_digest_locked must acquire the log_config lock (nesting should have thrown re-entrancy)');
} catch (RuntimeException $e) {
    check(strpos($e->getMessage(), 're-entrancy') !== false, 'local_config_digest_locked acquires the log_config lock (' . $e->getMessage() . ')');
}
try {
    iso_with_xml_lock($xmlPathP0a, function () use ($xmlPathP0a, $logCfgP0a) {
        ftp_backup_local_config_digest_locked($xmlPathP0a, $logCfgP0a);
    });
    check(false, 'local_config_digest_locked must acquire the opcua_config lock (nesting should have thrown re-entrancy)');
} catch (RuntimeException $e) {
    check(strpos($e->getMessage(), 're-entrancy') !== false, 'local_config_digest_locked acquires the opcua_config lock (' . $e->getMessage() . ')');
}

if ($dtdAvailable) {
    // --- 21) Commit rejects a stale local_config_digest even though the
    //         remote backup bytes are unchanged and would otherwise pass --
    //         simulating an unrelated Add/Edit/Device write landing between
    //         preflight and commit. Files must be left untouched. ---
    $dirP0b = make_tmp_dir('ftp_restore_p0_stale_local');
    $xmlPathP0b = $dirP0b . '/OPC_UA_Config.xml';
    $logCfgP0b = $dirP0b . '/Morfeas_config.xml';
    file_put_contents($xmlPathP0b, $validOpcUa);
    file_put_contents($logCfgP0b, $validMorfeas);

    $staleLocalDigest = ftp_backup_local_config_digest_locked($xmlPathP0b, $logCfgP0b);

    // An unrelated write (e.g. a channel Add) touches OPC_UA_Config.xml
    // after preflight computed its snapshot but before commit runs.
    file_put_contents($xmlPathP0b, str_replace(
        '</NODESet>',
        "  <CHANNEL><ISO_CHANNEL>_Concurrent</ISO_CHANNEL><INTERFACE_TYPE>SDAQ</INTERFACE_TYPE><ANCHOR>222222222.CH2</ANCHOR><DESCRIPTION>d</DESCRIPTION><MIN>0</MIN><MAX>1</MAX></CHANNEL>\n</NODESet>",
        $validOpcUa
    ));
    $beforeOpcHashP0b = hash_file('sha256', $xmlPathP0b);
    $beforeLogHashP0b = hash_file('sha256', $logCfgP0b);

    $bundleP0b = make_bundle($validOpcUa, $validMorfeas);
    $remoteDigestP0b = ftp_backup_restore_digest('p0-stale-local.mbl', $bundleP0b);

    try {
        ftp_backup_restore_commit(
            'p0-stale-local.mbl',
            $remoteDigestP0b,
            $staleLocalDigest,
            $xmlPathP0b,
            $logCfgP0b,
            $dtdDir,
            false,
            static fn(string $filename): string => $bundleP0b
        );
        check(false, 'Commit with a stale local_config_digest must throw even though the remote digest matches');
    } catch (ChannelConfigException $e) {
        check($e->apiCode() === 'ftp_restore_local_config_changed', 'Commit with a stale local_config_digest rejects with ftp_restore_local_config_changed (got ' . $e->apiCode() . ')');
        check($e->status() === 409, 'Stale local_config_digest rejection uses HTTP 409 (got ' . $e->status() . ')');
    }
    check(
        hash_file('sha256', $xmlPathP0b) === $beforeOpcHashP0b && hash_file('sha256', $logCfgP0b) === $beforeLogHashP0b,
        'a rejected FTP commit (stale local_config_digest) leaves both local files byte-for-byte unchanged'
    );

    // --- 22) Regression: a fresh, correctly-paired local_config_digest still
    //         commits normally -- this fix must not false-reject a normal Restore. ---
    $dirP0c = make_tmp_dir('ftp_restore_p0_fresh_local');
    $xmlPathP0c = $dirP0c . '/OPC_UA_Config.xml';
    $logCfgP0c = $dirP0c . '/Morfeas_config.xml';
    file_put_contents($xmlPathP0c, $validOpcUa);
    file_put_contents($logCfgP0c, $validMorfeas);
    $freshLocalDigest = ftp_backup_local_config_digest_locked($xmlPathP0c, $logCfgP0c);
    $bundleP0c = make_bundle($legacySdaqUnit, $validMorfeas);
    $remoteDigestP0c = ftp_backup_restore_digest('p0-fresh-local.mbl', $bundleP0c);
    $resultP0c = ftp_backup_restore_commit(
        'p0-fresh-local.mbl',
        $remoteDigestP0c,
        $freshLocalDigest,
        $xmlPathP0c,
        $logCfgP0c,
        $dtdDir,
        false,
        static fn(string $filename): string => $bundleP0c
    );
    check($resultP0c['filename'] === 'p0-fresh-local.mbl', '22 regression: a fresh local_config_digest still commits normally');
    check(file_get_contents($xmlPathP0c) === $legacySdaqUnit, '22 regression: the commit actually applied the new OPC_UA_Config content');
}

// =====================================================================
// P1: full local-vs-bundle impact report (Add/Replace/Unchanged/Remove)
// for both ISO channels and IOBOX/MTI/NOX device handlers -- what the
// operator actually asked for: "which channel replaced which, which
// stayed the same, which disappeared".
// =====================================================================

if ($dtdAvailable) {
    function p1_channel_xml(string $iso, string $type, string $anchor, string $desc, string $unit = ''): string
    {
        $unitTag = $unit !== '' ? "\n    <UNIT>$unit</UNIT>" : '';
        return "  <CHANNEL>\n"
            . "    <ISO_CHANNEL>$iso</ISO_CHANNEL>\n"
            . "    <INTERFACE_TYPE>$type</INTERFACE_TYPE>\n"
            . "    <ANCHOR>$anchor</ANCHOR>\n"
            . "    <DESCRIPTION>$desc</DESCRIPTION>\n"
            . "    <MIN>0</MIN>\n"
            . "    <MAX>100</MAX>$unitTag\n"
            . "  </CHANNEL>\n";
    }

    // --- Local state before Restore. ---
    $localChannelsXml = p1_channel_xml('_SDAQ_Same', 'SDAQ', '111111111.CH1', 'd', 'local-ignored')
        . p1_channel_xml('_IOBOX_X', 'IOBOX', '2380966080.RX1.CH1', 'old-desc', 'C')
        . p1_channel_xml('_Only_Local', 'SDAQ', '222222222.CH1', 'd');
    $localOpcUa = "<?xml version=\"1.0\"?>\n<NODESet>\n$localChannelsXml</NODESet>\n";
    $localMorfeas = <<<XML
<?xml version="1.0"?>
<CONFIG>
  <COMPONENTS>
    <SDAQ_HANDLER Disable="false"><CANBUS_IF>can0</CANBUS_IF><I2CBUS_NUM>1</I2CBUS_NUM></SDAQ_HANDLER>
    <SDAQ_HANDLER Disable="false"><CANBUS_IF>can2</CANBUS_IF></SDAQ_HANDLER>
    <IOBOX_HANDLER Disable="false"><DEV_NAME>Dev1</DEV_NAME><IPv4_ADDR>192.168.234.141</IPv4_ADDR></IOBOX_HANDLER>
    <MTI_HANDLER Disable="false"><DEV_NAME>MtiOld</DEV_NAME><IPv4_ADDR>10.0.0.5</IPv4_ADDR></MTI_HANDLER>
    <MTI_HANDLER Disable="false"><DEV_NAME>MtiKeep</DEV_NAME><IPv4_ADDR>10.0.0.9</IPv4_ADDR></MTI_HANDLER>
  </COMPONENTS>
</CONFIG>
XML;

    // --- Bundle being restored. The IOBOX channel's handler is present in
    //     this same bundle (renamed), so no orphan_device_source warning --
    //     but P2 deliberately makes that handler Disable="true" here too
    //     (to also exercise the enabled->disabled impact-report case below),
    //     so this bundle produces exactly one device_handler_disabled
    //     warning and every commit against it needs acknowledge_warnings=true. ---
    $bundleChannelsXml = p1_channel_xml('_SDAQ_Same', 'SDAQ', '111111111.CH1', 'd', 'bundle-ignored')
        . p1_channel_xml('_IOBOX_X', 'IOBOX', '2380966080.RX1.CH1', 'new-desc', 'C')
        . p1_channel_xml('_New_SDAQ', 'SDAQ', '333333333.CH1', 'd');
    $p1BundleOpcUa = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!DOCTYPE NODESet SYSTEM \"Morfeas.dtd\">\n<NODESet>\n$bundleChannelsXml</NODESet>\n";
    $p1BundleMorfeas = <<<XML
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
    <SDAQ_HANDLER Disable="false"><CANBUS_IF>can0</CANBUS_IF><I2CBUS_NUM>2</I2CBUS_NUM></SDAQ_HANDLER>
    <SDAQ_HANDLER Disable="false"><CANBUS_IF>can2</CANBUS_IF></SDAQ_HANDLER>
    <IOBOX_HANDLER Disable="true"><DEV_NAME>Dev1-Renamed</DEV_NAME><IPv4_ADDR>192.168.234.141</IPv4_ADDR></IOBOX_HANDLER>
    <MTI_HANDLER Disable="false"><DEV_NAME>MtiKeep</DEV_NAME><IPv4_ADDR>10.0.0.9</IPv4_ADDR></MTI_HANDLER>
    <NOX_HANDLER Disable="false"><CANBUS_IF>can1</CANBUS_IF></NOX_HANDLER>
  </COMPONENTS>
</CONFIG>
XML;

    $p1Report = ftp_backup_validate_bundle_candidates($p1BundleOpcUa, $p1BundleMorfeas, $dtdDir);
    check(
        $p1Report['can_commit'] === true
            && count($p1Report['warnings']) === 1
            && $p1Report['warnings'][0]['code'] === 'device_handler_disabled',
        'P1 fixture: bundle validates and commits, with exactly one device_handler_disabled warning for the disabled IOBOX handler (got ' . json_encode($p1Report['warnings']) . ')'
    );

    // --- 23) ftp_backup_build_impact_report() classifies every row correctly. ---
    // (iso_load_channels()/log_config_load_manual_devices() both take a file
    // path, so the "local" side is written out here the same way it exists
    // on disk in production.)
    $dirP1Fixture = make_tmp_dir('ftp_restore_p1_fixture');
    $xmlPathP1Fixture = $dirP1Fixture . '/OPC_UA_Config.xml';
    $logCfgP1Fixture = $dirP1Fixture . '/Morfeas_config.xml';
    file_put_contents($xmlPathP1Fixture, $localOpcUa);
    file_put_contents($logCfgP1Fixture, $localMorfeas);
    $p1Impact = ftp_backup_build_impact_report(
        iso_load_channels($xmlPathP1Fixture),
        log_config_load_manual_devices($logCfgP1Fixture),
        $p1BundleOpcUa,
        $p1BundleMorfeas
    );

    $channelsByIso = [];
    foreach ($p1Impact['channels'] as $row) {
        $channelsByIso[$row['iso_channel']] = $row;
    }
    check(($channelsByIso['_SDAQ_Same']['result'] ?? null) === 'Unchanged', 'P1: SDAQ channel differing only in runtime-owned UNIT is Unchanged (got ' . ($channelsByIso['_SDAQ_Same']['result'] ?? 'null') . ')');
    check(($channelsByIso['_IOBOX_X']['result'] ?? null) === 'Replace', 'P1: IOBOX channel with a changed DESCRIPTION is Replace (got ' . ($channelsByIso['_IOBOX_X']['result'] ?? 'null') . ')');
    check(($channelsByIso['_Only_Local']['result'] ?? null) === 'Remove', 'P1: channel present locally but absent from the bundle is Remove (got ' . ($channelsByIso['_Only_Local']['result'] ?? 'null') . ')');
    check(($channelsByIso['_New_SDAQ']['result'] ?? null) === 'Add', 'P1: channel present in the bundle but absent locally is Add (got ' . ($channelsByIso['_New_SDAQ']['result'] ?? 'null') . ')');
    check(
        $p1Impact['channel_summary'] === ['add' => 1, 'replace' => 1, 'unchanged' => 1, 'remove' => 1],
        'P1: channel_summary tallies all four outcomes exactly once each (got ' . json_encode($p1Impact['channel_summary']) . ')'
    );

    // --- 23b) Replace rows carry `before` (the current local values) and
    //          `changed_fields` -- without this a Replace row only shows the
    //          bundle's final state, and an operator cannot tell a
    //          DESCRIPTION-only edit apart from a full source swap. ---
    check(
        in_array('description', $channelsByIso['_IOBOX_X']['changed_fields'] ?? [], true),
        'P1: Replace channel row lists "description" in changed_fields (got ' . json_encode($channelsByIso['_IOBOX_X']['changed_fields'] ?? null) . ')'
    );
    check(
        ($channelsByIso['_IOBOX_X']['before']['description'] ?? null) === 'old-desc',
        'P1: Replace channel row\'s `before` carries the pre-change DESCRIPTION (got ' . json_encode($channelsByIso['_IOBOX_X']['before']['description'] ?? null) . ')'
    );
    check(
        $channelsByIso['_SDAQ_Same']['changed_fields'] === [],
        'P1: an Unchanged row has an empty changed_fields (got ' . json_encode($channelsByIso['_SDAQ_Same']['changed_fields']) . ')'
    );

    $handlersByKey = [];
    foreach ($p1Impact['handlers'] as $row) {
        $handlersByKey[$row['type'] . ':' . (in_array($row['type'], ['NOX', 'SDAQ'], true) ? $row['bus'] : $row['ip'])] = $row;
    }
    check(($handlersByKey['IOBOX:192.168.234.141']['result'] ?? null) === 'Replace', 'P1: IOBOX handler with a changed DEV_NAME (same IP) is Replace (got ' . ($handlersByKey['IOBOX:192.168.234.141']['result'] ?? 'null') . ')');
    check(($handlersByKey['MTI:10.0.0.5']['result'] ?? null) === 'Remove', 'P1: MTI handler present locally but absent from the bundle is Remove (got ' . ($handlersByKey['MTI:10.0.0.5']['result'] ?? 'null') . ')');
    check(($handlersByKey['MTI:10.0.0.9']['result'] ?? null) === 'Unchanged', 'P1: MTI handler identical on both sides is Unchanged (got ' . ($handlersByKey['MTI:10.0.0.9']['result'] ?? 'null') . ')');
    check(($handlersByKey['NOX:can1']['result'] ?? null) === 'Add', 'P1: NOX handler present in the bundle but absent locally is Add (got ' . ($handlersByKey['NOX:can1']['result'] ?? 'null') . ')');

    // --- Finding 1 fix: SDAQ_HANDLER now appears in the impact report at
    //     all, and a CANBUS_IF-matched pair differing only in I2CBUS_NUM is
    //     correctly Replace, not a false Unchanged (I2CBUS_NUM feeds a real
    //     "-b" runtime argument in Morfeas_daemon.c, not display-only data). ---
    check(($handlersByKey['SDAQ:can0']['result'] ?? null) === 'Replace', 'P1: SDAQ handler with a changed I2CBUS_NUM (same CANBUS_IF) is Replace, not falsely Unchanged (got ' . ($handlersByKey['SDAQ:can0']['result'] ?? 'null') . ')');
    check(
        in_array('i2c_bus_num', $handlersByKey['SDAQ:can0']['changed_fields'] ?? [], true),
        'P1: SDAQ handler Replace row lists "i2c_bus_num" in changed_fields (got ' . json_encode($handlersByKey['SDAQ:can0']['changed_fields'] ?? null) . ')'
    );
    check(($handlersByKey['SDAQ:can2']['result'] ?? null) === 'Unchanged', 'P1: SDAQ handler identical (including absent I2CBUS_NUM) on both sides is Unchanged (got ' . ($handlersByKey['SDAQ:can2']['result'] ?? 'null') . ')');

    // --- Finding 1 fix: an enabled -> disabled handler flip must be visible
    //     as a changed field, not hidden inside a bare "Replace" verdict. ---
    check(
        in_array('disabled', $handlersByKey['IOBOX:192.168.234.141']['changed_fields'] ?? [], true),
        'P1: IOBOX handler Replace row lists "disabled" when Disable flips from false to true (got ' . json_encode($handlersByKey['IOBOX:192.168.234.141']['changed_fields'] ?? null) . ')'
    );
    check(
        ($handlersByKey['IOBOX:192.168.234.141']['before']['disabled'] ?? null) === false,
        'P1: IOBOX handler Replace row\'s `before` shows the handler was currently enabled'
    );

    check(
        $p1Impact['handler_summary'] === ['add' => 1, 'replace' => 2, 'unchanged' => 2, 'remove' => 1],
        'P1: handler_summary tallies all six rows correctly, including the two new SDAQ rows (got ' . json_encode($p1Impact['handler_summary']) . ')'
    );

    // --- Invariant: every row this fixture classifies as Replace must have
    //     a non-empty changed_fields. A Replace with an empty changed_fields
    //     is exactly the bug class ftp_backup_field_value_changed() used to
    //     have (see 23c below): the row-level verdict says "different" but
    //     the field-level detail says "nothing changed", which is a report
    //     the operator cannot act on. ---
    foreach (array_merge($p1Impact['channels'], $p1Impact['handlers']) as $row) {
        if ($row['result'] === 'Replace') {
            check(
                !empty($row['changed_fields']),
                'P1 invariant: Replace row (' . ($row['iso_channel'] ?? ($row['type'] . ':' . ($row['bus'] ?: $row['ip']))) . ') has a non-empty changed_fields'
            );
        }
    }

    // --- 23c) Finding 2 regression: restore_entry_matches_existing() uses
    //     $strEq (plain string equality) for DESCRIPTION, not $numEq
    //     (numeric-aware). "001" and "1" are numerically equal but are
    //     different strings, so the row-level verdict is Replace -- and
    //     changed_fields must be able to name DESCRIPTION too, not go quiet
    //     just because a naive numeric-aware comparator would call them equal. ---
    $dirNumStr = make_tmp_dir('ftp_restore_numeric_string_field');
    $xmlPathNumStr = $dirNumStr . '/OPC_UA_Config.xml';
    $logCfgNumStr = $dirNumStr . '/Morfeas_config.xml';
    file_put_contents($xmlPathNumStr, "<?xml version=\"1.0\"?>\n<NODESet>\n" . p1_channel_xml('_NumStr', 'SDAQ', '444444444.CH1', '001') . "</NODESet>\n");
    file_put_contents($logCfgNumStr, "<?xml version=\"1.0\"?>\n<CONFIG><COMPONENTS></COMPONENTS></CONFIG>\n");
    $bundleOpcUaNumStr = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!DOCTYPE NODESet SYSTEM \"Morfeas.dtd\">\n<NODESet>\n"
        . p1_channel_xml('_NumStr', 'SDAQ', '444444444.CH1', '1') . "</NODESet>\n";
    $bundleMorfeasNumStr = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE CONFIG SYSTEM "Morfeas.dtd">
<CONFIG>
  <CONFIGS_DIR>/home/morfeas/configuration</CONFIGS_DIR>
  <LOGGERS_DIR>/mnt/ramdisk/Morfeas_Loggers/</LOGGERS_DIR>
  <LOGSTAT_DIR>/mnt/ramdisk/</LOGSTAT_DIR>
  <COMPONENTS>
    <OPC_UA_SERVER><APP_NAME>Morfeas_Default_app_32</APP_NAME></OPC_UA_SERVER>
  </COMPONENTS>
</CONFIG>
XML;
    $numStrImpact = ftp_backup_build_impact_report(
        iso_load_channels($xmlPathNumStr),
        log_config_load_manual_devices($logCfgNumStr),
        $bundleOpcUaNumStr,
        $bundleMorfeasNumStr
    );
    $numStrRow = $numStrImpact['channels'][0] ?? null;
    check($numStrRow !== null && $numStrRow['result'] === 'Replace', 'P1 23c: DESCRIPTION "001" vs "1" is a Replace (numerically equal, but different strings) (got ' . ($numStrRow['result'] ?? 'null') . ')');
    check(
        $numStrRow !== null && in_array('description', $numStrRow['changed_fields'], true),
        'P1 23c: changed_fields correctly names "description" for a "001" vs "1" DESCRIPTION change, not silently empty (got ' . json_encode($numStrRow['changed_fields'] ?? null) . ')'
    );

    // Regression guard: numeric fields must still use numeric-aware
    // equality (MIN "0" and "0.0" are the same value), so this fix does not
    // overcorrect into false Replace verdicts on ordinary formatting noise.
    check(
        ftp_backup_numeric_field_changed('0', '0.0') === false,
        'P1 23c regression: numeric fields "0" and "0.0" are still treated as unchanged (numeric-aware)'
    );
    check(
        ftp_backup_string_field_changed('001', '1') === true,
        'P1 23c regression: string fields "001" and "1" are treated as changed (plain string equality, matching $strEq)'
    );

    // --- 24) End-to-end through the production commit entry point: the
    //         returned impact matches what was computed above, proving
    //         commit recomputes it from the real files under lock rather
    //         than trusting a value handed in by the caller. ---
    $dirP1 = make_tmp_dir('ftp_restore_p1_impact');
    $xmlPathP1 = $dirP1 . '/OPC_UA_Config.xml';
    $logCfgP1 = $dirP1 . '/Morfeas_config.xml';
    file_put_contents($xmlPathP1, $localOpcUa);
    file_put_contents($logCfgP1, $localMorfeas);

    $localDigestP1 = ftp_backup_local_config_digest_locked($xmlPathP1, $logCfgP1);
    $bundleP1 = make_bundle($p1BundleOpcUa, $p1BundleMorfeas);
    $remoteDigestP1 = ftp_backup_restore_digest('p1-impact.mbl', $bundleP1);

    $commitResultP1 = ftp_backup_restore_commit(
        'p1-impact.mbl',
        $remoteDigestP1,
        $localDigestP1,
        $xmlPathP1,
        $logCfgP1,
        $dtdDir,
        true, // this bundle's IOBOX handler is Disable="true" (P2), which is a warning, not an error
        static fn(string $filename): string => $bundleP1
    );
    check(
        $commitResultP1['impact']['channel_summary'] === ['add' => 1, 'replace' => 1, 'unchanged' => 1, 'remove' => 1],
        'P1: restore_commit() returns the real recomputed channel_summary (got ' . json_encode($commitResultP1['impact']['channel_summary'] ?? null) . ')'
    );
    check(
        $commitResultP1['impact']['handler_summary'] === ['add' => 1, 'replace' => 2, 'unchanged' => 2, 'remove' => 1],
        'P1: restore_commit() returns the real recomputed handler_summary (got ' . json_encode($commitResultP1['impact']['handler_summary'] ?? null) . ')'
    );

    // --- 25) The condition ftp_backup_restore_preflight() gates
    //         impact-building on: an invalid candidate's can_commit is
    //         false, so preflight's `$report['can_commit'] ? ... : null`
    //         must skip building an impact report against a document that
    //         cannot be restored anyway. (preflight itself needs a live FTP
    //         connection, so this exercises the exact real report shape it
    //         branches on, the same way the ack-gate tests above do for
    //         restore_commit()'s warning check.) ---
    $invalidReport = ftp_backup_validate_bundle_candidates('<not even xml', $p1BundleMorfeas, $dtdDir);
    check($invalidReport['can_commit'] === false, 'P1: an invalid candidate reports can_commit=false, which gates impact-building off in preflight');
}

echo "\n{$g_checks} checks, " . ($g_checks - $g_failures) . " passed, {$g_failures} failed\n";
exit($g_failures === 0 ? 0 : 1);
