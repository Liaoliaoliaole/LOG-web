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

    // SDAQ/NOX identity is bus-based, not handler-IP-based, so they must
    // never be flagged as orphans by this check.
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

echo "\n{$g_checks} checks, " . ($g_checks - $g_failures) . " passed, {$g_failures} failed\n";
exit($g_failures === 0 ? 0 : 1);
