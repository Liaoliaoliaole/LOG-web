<?php
/*
 * tests/php/sdaqAddCandidateTest.php
 *
 * Standalone (no PHPUnit dependency) regression test for the Web-A1 fix:
 *   - logstat_sdaq.php no longer exposes a CAN-address fallback as the
 *     preferred/connection anchor.
 *   - channel_service.php's SDAQ Add/Replace candidate pool only contains
 *     channels with a valid serial anchor and registration=Done.
 *   - channel_add_channel_from_pool() re-derives the canonical serial anchor
 *     server-side inside the XML lock and rejects anything that isn't a
 *     currently-available SDAQ candidate (closing the incident entry point).
 *
 * Run: php tests/php/sdaqAddCandidateTest.php   (from Morfeas_WEB/)
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

function write_sdaq_fixture(string $dir): string
{
    $fixture = [
        'CANBus_interface' => 'CAN1',
        'SDAQs_data' => [
            [
                // Device A: detected (has a CAN address) but registration is not
                // Done yet and it has no serial -- this is the field-incident
                // shape (an SDAQ that just moved LOGs). Must NOT be Add-able.
                'Address' => 5,
                'SDAQ_type' => 'SDAQ-I',
                'Serial_number' => null,
                'SDAQ_Status' => ['Registration_status' => 'Registering'],
                'Meas' => [[
                    'Channel' => 1,
                    'CNT' => 10,
                    'Channel_Status' => ['Channel_status_val' => 0, 'No_Sensor' => false, 'Over_Range' => false, 'Out_of_Range' => false],
                    'Unit' => 'C',
                    'Last_Meas' => 23.5,
                ]],
            ],
            [
                // Device B: fully registered with a real serial. Must be Add-able
                // as "796834087.CH1", never as its current CAN address.
                'Address' => 8,
                'SDAQ_type' => 'SDAQ-I',
                'Serial_number' => 796834087,
                'SDAQ_Status' => ['Registration_status' => 'Done'],
                'Meas' => [[
                    'Channel' => 1,
                    'CNT' => 10,
                    'Channel_Status' => ['Channel_status_val' => 0, 'No_Sensor' => false, 'Over_Range' => false, 'Out_of_Range' => false],
                    'Unit' => 'C',
                    'Last_Meas' => 24.1,
                ]],
            ],
        ],
    ];
    $path = $dir . '/logstat_SDAQ_can1.json';
    file_put_contents($path, json_encode($fixture));
    return $path;
}

function write_empty_opcua_xml(string $dir): string
{
    $path = $dir . '/OPC_UA_Config.xml';
    file_put_contents($path, "<?xml version=\"1.0\"?>\n<NODESet>\n</NODESet>\n");
    return $path;
}

$dir = make_tmp_dir('sdaq_add_test');
$sdaqJson = write_sdaq_fixture($dir);
$xmlPath = write_empty_opcua_xml($dir);
// SDAQ identity is bus/serial-based, so Add never reads Morfeas_Config.xml
// for it and never takes the log_config lock (F-16). The path is passed
// anyway, and deliberately points at a file that does not exist: if the
// SDAQ path ever starts consulting it, these tests fail rather than
// silently acquiring a second lock.
$logConfigPath = $dir . '/Morfeas_config_absent.xml';

// --- 1) sdaq_load_anchor_map(): no address fallback into preferred/connection anchor ---
$map = sdaq_load_anchor_map($sdaqJson, []);
$byServial = null;
$byNoSerial = null;
foreach ($map['channels'] as $ch) {
    if ($ch['serial_anchor'] === '796834087.CH1') {
        $byServial = $ch;
    }
    if ($ch['serial_anchor'] === null) {
        $byNoSerial = $ch;
    }
}
check($byServial !== null, 'sdaq_load_anchor_map(): registered device produces serial_anchor 796834087.CH1');
check($byServial !== null && $byServial['preferred_anchor'] === '796834087.CH1', 'sdaq_load_anchor_map(): preferred_anchor is the serial anchor, not the CAN address');
check($byServial !== null && $byServial['connection_anchor'] === '796834087.CH1', 'sdaq_load_anchor_map(): connection_anchor is the serial anchor');
check($byNoSerial !== null, 'sdaq_load_anchor_map(): unregistered device is still present for diagnostics');
check($byNoSerial !== null && $byNoSerial['preferred_anchor'] === null, 'sdaq_load_anchor_map(): unregistered device has NO preferred_anchor fallback to its CAN address (the original bug)');
check($byNoSerial !== null && $byNoSerial['connection_anchor'] === null, 'sdaq_load_anchor_map(): unregistered device has NO connection_anchor fallback either');

// --- 2) channel_build_rows_with_logstat(): SDAQ search pool only contains the registered candidate ---
$extras = [];
channel_build_rows_with_logstat($xmlPath, [$sdaqJson], [], [], [], [], $extras);
$sdaqPool = $extras['search_pool']['SDAQ'] ?? [];
$anchorsInPool = array_map(static fn($c) => $c['anchor'], $sdaqPool);
check(in_array('796834087.CH1', $anchorsInPool, true), 'search pool contains the registered candidate 796834087.CH1');
check(!in_array('CAN1.ADDR:05.CH:01', $anchorsInPool, true), 'search pool does NOT contain the unregistered device\'s CAN address');
check(count($sdaqPool) === 1, 'search pool contains exactly one SDAQ Add candidate (got ' . count($sdaqPool) . ')');

// --- 3) iso_sdaq_anchor_is_valid(): grammar parity with Core's decode_sdaq_anchor() ---
$accept = ['1.CH1', '796834087.CH1', '4294967295.CH16'];
$reject = ['CAN1.ADDR:05.CH:01', 'can0.5.CH1', '0.CH1', '123.CH0', '123.CH17', '4294967296.CH1', '123.CH1.foo', ' 123.CH1', '-123.CH1', '000123.CH01', '123.ch1'];
foreach ($accept as $a) {
    check(iso_sdaq_anchor_is_valid($a) === true, "iso_sdaq_anchor_is_valid(): accepts \"$a\"");
}
foreach ($reject as $r) {
    check(iso_sdaq_anchor_is_valid($r) === false, "iso_sdaq_anchor_is_valid(): rejects \"$r\"");
}

// --- 4) channel_add_channel_from_pool(): the actual incident reproduction ---
$beforeHash = sha1_file($xmlPath);

// 4a) Reject: submitting the unregistered device's CAN address (the exact incident anchor).
try {
    channel_add_channel_from_pool(
        $xmlPath,
        $logConfigPath,
        ['iso_channel' => '_Protea_NH3', 'interface_type' => 'SDAQ', 'anchor' => 'CAN1.ADDR:05.CH:01', 'description' => 'd', 'min' => '0', 'max' => '1'],
        [$sdaqJson], [], [], [], []
    );
    check(false, 'Add with the incident address-style anchor for an unregistered device must throw');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'candidate_not_available', 'Add rejects the incident anchor with candidate_not_available (got ' . $e->apiCode() . ')');
}
check(sha1_file($xmlPath) === $beforeHash, 'XML file is byte-for-byte unchanged after the rejected incident-reproduction Add');

// 4b) Accept: submitting the registered device's CURRENT CAN address must still
//     resolve to and persist the canonical serial anchor, never the address.
channel_add_channel_from_pool(
    $xmlPath,
    $logConfigPath,
    ['iso_channel' => '_Test_Serial', 'interface_type' => 'SDAQ', 'anchor' => 'CAN1.ADDR:08.CH:01', 'description' => 'd', 'min' => '0', 'max' => '1'],
    [$sdaqJson], [], [], [], []
);
$xmlAfter = simplexml_load_file($xmlPath);
$writtenAnchor = null;
foreach ($xmlAfter->CHANNEL as $ch) {
    if ((string)$ch->ISO_CHANNEL === '_Test_Serial') {
        $writtenAnchor = (string)$ch->ANCHOR;
    }
}
check($writtenAnchor === '796834087.CH1', 'Add persists the canonical serial anchor even though the client submitted the CAN address (got ' . var_export($writtenAnchor, true) . ')');

// 4c) Reject: the now-linked candidate cannot be Add-ed a second time.
try {
    channel_add_channel_from_pool(
        $xmlPath,
        $logConfigPath,
        ['iso_channel' => '_Test_Serial_2', 'interface_type' => 'SDAQ', 'anchor' => '796834087.CH1', 'description' => 'd', 'min' => '0', 'max' => '1'],
        [$sdaqJson], [], [], [], []
    );
    check(false, 'Add of an already-linked candidate must throw');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'candidate_not_available', 'Add rejects an already-linked candidate with candidate_not_available (got ' . $e->apiCode() . ')');
}

// 4d) Reject: a syntactically well-formed but wholly fabricated serial anchor
//     that matches no current candidate must be rejected, not written verbatim.
try {
    channel_add_channel_from_pool(
        $xmlPath,
        $logConfigPath,
        ['iso_channel' => '_Fabricated', 'interface_type' => 'SDAQ', 'anchor' => '999999999.CH1', 'description' => 'd', 'min' => '0', 'max' => '1'],
        [$sdaqJson], [], [], [], []
    );
    check(false, 'Add of a fabricated (never-detected) serial anchor must throw');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'candidate_not_available', 'Add rejects a fabricated serial anchor not in the pool (got ' . $e->apiCode() . ')');
}

echo "\n{$g_checks} checks, " . ($g_checks - $g_failures) . " passed, {$g_failures} failed\n";
exit($g_failures === 0 ? 0 : 1);
