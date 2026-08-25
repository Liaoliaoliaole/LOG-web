<?php
/* Web parser limits must match Core constants; skip only when Core is absent. */

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

function read_core_file(string $coreDir, string $relPath): ?string
{
    $path = $coreDir . '/' . $relPath;
    if (!is_file($path)) {
        return null;
    }
    $contents = @file_get_contents($path);
    return $contents === false ? null : $contents;
}

function extract_define(string $contents, string $name): ?int
{
    // Matches "#define NAME 20" but not "#define NAME (A + B)" -- those are
    // handled by extract_sum_define() below.
    if (preg_match('/#define\s+' . preg_quote($name, '/') . '\s+(\d+)\b/', $contents, $m)) {
        return (int)$m[1];
    }
    return null;
}

function extract_sum_define(string $contents, string $partA, string $partB): ?int
{
    $a = extract_define($contents, $partA);
    $b = extract_define($contents, $partB);
    return ($a === null || $b === null) ? null : $a + $b;
}

function extract_struct_array_size(string $contents, string $structName, string $fieldName): ?int
{
    if (!preg_match('/struct\s+' . preg_quote($structName, '/') . '\s*\{([^}]*)\}/s', $contents, $sm)) {
        return null;
    }
    if (!preg_match('/\b' . preg_quote($fieldName, '/') . '\[(\d+)\]/', $sm[1], $fm)) {
        return null;
    }
    return (int)$fm[1];
}

// --- Locate LOG-core ---
$coreDir = getenv('MORFEAS_CORE_SRC_DIR');
if ($coreDir === false || trim($coreDir) === '') {
    $coreDir = realpath(__DIR__ . '/../../../../LOG-core');
}
if ($coreDir === false || !is_dir($coreDir)) {
    echo "SKIPPED: LOG-core checkout not found (set MORFEAS_CORE_SRC_DIR or check out LOG-core as a sibling of LOG-web) -- F-8 drift protection needs both repos present\n";
    exit(0);
}

$typesH = read_core_file($coreDir, 'src/Morfeas_Types.h');
$sdaqDrvH = read_core_file($coreDir, 'src/sdaq-worker/src/SDAQ_drv.h');
$ipcH = read_core_file($coreDir, 'src/IPC/Morfeas_IPC.h');

if ($typesH === null || $sdaqDrvH === null || $ipcH === null) {
    echo "SKIPPED: LOG-core found at $coreDir but expected source files are missing -- Core source layout may have changed, update this test's paths\n";
    exit(0);
}

// --- ISO_channel_name_size (C-4 in iso_validate_document()) ---
$isoNameSize = extract_define($typesH, 'ISO_channel_name_size');
check($isoNameSize !== null, "Core defines ISO_channel_name_size (found in Morfeas_Types.h)");
if ($isoNameSize !== null) {
    $maxLen = $isoNameSize - 1;
    $okIso = str_repeat('_', $maxLen);
    $tooLongIso = str_repeat('_', $isoNameSize);
    $xmlOk = simplexml_load_string('<NODESet><CHANNEL><ISO_CHANNEL>' . $okIso . '</ISO_CHANNEL><INTERFACE_TYPE>SDAQ</INTERFACE_TYPE><ANCHOR>1.CH1</ANCHOR><DESCRIPTION>d</DESCRIPTION><MIN>0</MIN><MAX>1</MAX></CHANNEL></NODESet>');
    $xmlTooLong = simplexml_load_string('<NODESet><CHANNEL><ISO_CHANNEL>' . $tooLongIso . '</ISO_CHANNEL><INTERFACE_TYPE>SDAQ</INTERFACE_TYPE><ANCHOR>1.CH1</ANCHOR><DESCRIPTION>d</DESCRIPTION><MIN>0</MIN><MAX>1</MAX></CHANNEL></NODESet>');
    $accepted = true;
    try {
        iso_validate_document($xmlOk);
    } catch (ChannelConfigException $e) {
        $accepted = false;
    }
    check($accepted, "Web accepts ISO_CHANNEL at Core's ISO_channel_name_size-1 boundary ($maxLen bytes)");
    $rejected = false;
    try {
        iso_validate_document($xmlTooLong);
    } catch (ChannelConfigException $e) {
        $rejected = $e->apiCode() === 'invalid_iso_channel';
    }
    check($rejected, "Web rejects ISO_CHANNEL at Core's ISO_channel_name_size boundary ($isoNameSize bytes)");
}

// --- SDAQ_MAX_AMOUNT_OF_CHANNELS ---
$sdaqMaxCh = extract_define($sdaqDrvH, 'SDAQ_MAX_AMOUNT_OF_CHANNELS');
check($sdaqMaxCh !== null, "Core defines SDAQ_MAX_AMOUNT_OF_CHANNELS (found in SDAQ_drv.h)");
if ($sdaqMaxCh !== null) {
    check(iso_parse_sdaq_identity('1.CH' . $sdaqMaxCh) !== null, "Web SDAQ parser accepts CH$sdaqMaxCh (Core's SDAQ_MAX_AMOUNT_OF_CHANNELS)");
    check(iso_parse_sdaq_identity('1.CH' . ($sdaqMaxCh + 1)) === null, "Web SDAQ parser rejects CH" . ($sdaqMaxCh + 1) . " (one past Core's SDAQ_MAX_AMOUNT_OF_CHANNELS)");
}

// --- IOBOX_Amount_of_All_RXs (STD + Extra) ---
$ioboxRXs = extract_sum_define($typesH, 'IOBOX_Amount_of_STD_RXs', 'IOBOX_Amount_of_Extra_RXs');
check($ioboxRXs !== null, "Core defines IOBOX_Amount_of_STD_RXs + IOBOX_Amount_of_Extra_RXs (found in Morfeas_Types.h)");
if ($ioboxRXs !== null) {
    check(iso_parse_iobox_identity('1.RX' . $ioboxRXs . '.CH1') !== null, "Web IOBOX parser accepts RX$ioboxRXs (Core's IOBOX_Amount_of_All_RXs)");
    check(iso_parse_iobox_identity('1.RX' . ($ioboxRXs + 1) . '.CH1') === null, "Web IOBOX parser rejects RX" . ($ioboxRXs + 1) . " (one past Core's IOBOX_Amount_of_All_RXs)");
}

// --- IOBOX_Amount_of_channels ---
$ioboxCh = extract_define($typesH, 'IOBOX_Amount_of_channels');
check($ioboxCh !== null, "Core defines IOBOX_Amount_of_channels (found in Morfeas_Types.h)");
if ($ioboxCh !== null) {
    check(iso_parse_iobox_identity('1.RX1.CH' . $ioboxCh) !== null, "Web IOBOX parser accepts CH$ioboxCh (Core's IOBOX_Amount_of_channels)");
    check(iso_parse_iobox_identity('1.RX1.CH' . ($ioboxCh + 1)) === null, "Web IOBOX parser rejects CH" . ($ioboxCh + 1) . " (one past Core's IOBOX_Amount_of_channels)");
}

// --- MTI telemetry channel counts: struct {TC4,TC8,TC16,QUAD}_data_struct ---
$mtiStructs = [
    'TC4' => ['TC4_data_struct', 'CHs'],
    'TC8' => ['TC8_data_struct', 'CHs'],
    'TC16' => ['TC16_data_struct', 'CHs'],
    'QUAD' => ['QUAD_data_struct', 'CHs'],
];
foreach ($mtiStructs as $type => [$structName, $field]) {
    $max = extract_struct_array_size($typesH, $structName, $field);
    $arrayDesc = $structName . '.' . $field . '[' . ($max === null ? '?' : $max) . ']';
    check($max !== null, "Core defines $structName.$field array size (found in Morfeas_Types.h)");
    if ($max !== null) {
        check(iso_parse_mti_identity('1.' . $type . '.CH' . $max) !== null, "Web MTI parser accepts $type.CH$max (Core's $arrayDesc)");
        check(iso_parse_mti_identity('1.' . $type . '.CH' . ($max + 1)) === null, "Web MTI parser rejects $type.CH" . ($max + 1) . " (one past Core's $arrayDesc)");
    }
}

// --- MTI Mini-RMSW tele_ID range: unsigned char in struct Link_entry ---
if (preg_match('/struct\s+Link_entry\s*\{([^}]*)\}/s', $typesH, $lm) && preg_match('/unsigned\s+char\s+tele_ID\s*;/', $lm[1])) {
    check(true, "Core's struct Link_entry.tele_ID is unsigned char (found in Morfeas_Types.h)");
    check(iso_parse_mti_identity('1.ID:255.CH1') !== null, "Web MTI parser accepts ID:255 (max value of Core's unsigned char tele_ID)");
    check(iso_parse_mti_identity('1.ID:256.CH1') === null, "Web MTI parser rejects ID:256 (overflows Core's unsigned char tele_ID)");
} else {
    check(false, "Core's struct Link_entry.tele_ID is unsigned char (found in Morfeas_Types.h)");
}

// --- MTI Mini-RMSW channel count: RMSW_MUX_Mini_data_struct.meas_data[] ---
$miniRmswCh = extract_struct_array_size($typesH, 'RMSW_MUX_Mini_data_struct', 'meas_data');
$miniRmswDesc = 'RMSW_MUX_Mini_data_struct.meas_data[' . ($miniRmswCh === null ? '?' : $miniRmswCh) . ']';
check($miniRmswCh !== null, "Core defines RMSW_MUX_Mini_data_struct.meas_data array size (found in Morfeas_Types.h)");
if ($miniRmswCh !== null) {
    check(iso_parse_mti_identity('1.ID:1.CH' . $miniRmswCh) !== null, "Web MTI parser accepts Mini-RMSW CH$miniRmswCh (Core's $miniRmswDesc)");
    check(iso_parse_mti_identity('1.ID:1.CH' . ($miniRmswCh + 1)) === null, "Web MTI parser rejects Mini-RMSW CH" . ($miniRmswCh + 1) . " (one past Core's $miniRmswDesc)");
}

// --- Dev_or_Bus_name_str_size (IFNAMSIZ) ---
// IFNAMSIZ itself is a POSIX system constant from <net/if.h>, not something
// Core's own repo defines a numeric value for -- Core only aliases the name
// (Dev_or_Bus_name_str_size == IFNAMSIZ). It has been 16 across every
// Linux/glibc version this product targets; there is no repo file to read
// the number from, so this is documented rather than auto-extracted. The
// NOX can_if grammar check (opcua_config.php's iso_parse_nox_identity())
// allows up to IFNAMSIZ-1 = 15 bytes for the interface name segment.
check(
    str_contains($ipcH, 'Dev_or_Bus_name_str_size IFNAMSIZ'),
    "Core aliases Dev_or_Bus_name_str_size to IFNAMSIZ (found in Morfeas_IPC.h) -- IFNAMSIZ==16 is a POSIX system constant, documented not auto-extracted"
);

echo "\n{$g_checks} checks, " . ($g_checks - $g_failures) . " passed, {$g_failures} failed\n";
exit($g_failures === 0 ? 0 : 1);
