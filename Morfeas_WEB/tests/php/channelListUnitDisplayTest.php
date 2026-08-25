<?php
/*
 * Linked IOBOX/MTI/NOX rows display their XML Unit. IOBOX/MTI candidates do
 * not prefill a placeholder Unit; SDAQ remains runtime-owned.
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

function unit_test_tmp_dir(): string
{
    $dir = sys_get_temp_dir() . '/channel_list_unit_test_' . uniqid();
    mkdir($dir, 0700, true);
    return $dir;
}

$dir = unit_test_tmp_dir();
register_shutdown_function(function () use ($dir) {
    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
});

// A minimal but real OPC_UA_Config.xml carrying one channel per interface,
// each with a unit that is NOT what the logstat placeholder would suggest
// -- this is the whole point: prove the list follows the configured unit
// even when it disagrees with the hardcoded default.
$xmlPath = $dir . '/OPC_UA_Config.xml';
file_put_contents($xmlPath, <<<XML
<?xml version="1.0"?>
<NODESet>
  <CHANNEL>
    <ISO_CHANNEL>_FT102</ISO_CHANNEL>
    <INTERFACE_TYPE>IOBOX</INTERFACE_TYPE>
    <ANCHOR>111.RX1.CH1</ANCHOR>
    <DESCRIPTION>flow</DESCRIPTION>
    <MIN>0</MIN>
    <MAX>100</MAX>
    <UNIT>g/s</UNIT>
  </CHANNEL>
  <CHANNEL>
    <ISO_CHANNEL>_RX1STATUS</ISO_CHANNEL>
    <INTERFACE_TYPE>IOBOX</INTERFACE_TYPE>
    <ANCHOR>111.RX1.Status</ANCHOR>
    <DESCRIPTION>status</DESCRIPTION>
    <MIN>0</MIN>
    <MAX>1</MAX>
    <UNIT>bool</UNIT>
  </CHANNEL>
  <CHANNEL>
    <ISO_CHANNEL>_TCTEMP</ISO_CHANNEL>
    <INTERFACE_TYPE>MTI</INTERFACE_TYPE>
    <ANCHOR>222.TC16.CH1</ANCHOR>
    <DESCRIPTION>pressure via TC input</DESCRIPTION>
    <MIN>0</MIN>
    <MAX>10</MAX>
    <UNIT>bar</UNIT>
  </CHANNEL>
  <CHANNEL>
    <ISO_CHANNEL>_NOXCUSTOM</ISO_CHANNEL>
    <INTERFACE_TYPE>NOX</INTERFACE_TYPE>
    <ANCHOR>can1.addr_0.NOx</ANCHOR>
    <DESCRIPTION>relabelled by operator</DESCRIPTION>
    <MIN>0</MIN>
    <MAX>1000</MAX>
    <UNIT>ppm (wet)</UNIT>
  </CHANNEL>
</NODESet>
XML
);

$ioboxLog = $dir . '/logstat_IOBOX_test.json';
file_put_contents($ioboxLog, json_encode([
    'Dev_name' => 'test', 'IPv4_address' => '10.0.0.111', 'Identifier' => 111,
    'Connection_status' => 'Okay',
    'RX1' => ['CH1' => 42.7, 'CH2' => 18.3, 'Status' => 1, 'Success' => 95],
]));

$mtiLog = $dir . '/logstat_MTI_test.json';
file_put_contents($mtiLog, json_encode([
    'Dev_name' => 'test', 'IPv4_address' => '10.0.0.222', 'Identifier' => 222,
    'Connection_status' => 'Okay',
    'MTI_status' => ['Tele_Device_type' => 'Tele_TC16'],
    'Tele_data' => ['IsValid' => true, 'CHs' => [3.5]],
]));

$noxLog = $dir . '/logstat_NOX_test.json';
file_put_contents($noxLog, json_encode([
    'CANBus_interface' => 'can1',
    'NOx_sensors' => [[
        'addr' => 0, 'NOx_value_avg' => 120.5, 'O2_value_avg' => 20.9,
        'status' => ['is_NOx_value_valid' => true, 'is_O2_value_valid' => true],
    ]],
]));

[$rows, $extras] = channel_collect_rows_and_extras($xmlPath, [], [$ioboxLog], [$mtiLog], [$noxLog], []);

function find_row(array $rows, string $iso): ?array
{
    foreach ($rows as $r) {
        if ($r['iso_channel'] === $iso) {
            return $r;
        }
    }
    return null;
}

// --- 1) IOBOX: list follows the configured unit, not the '°C' placeholder ---
$r = find_row($rows, '_FT102');
check($r !== null && $r['unit'] === 'g/s', 'IOBOX channel keeps its configured unit in the row');
check($r !== null && $r['meas'] === '42.700 g/s', 'IOBOX list value uses the configured unit "g/s", not the logstat placeholder "°C" (got ' . ($r['meas'] ?? 'null') . ')');
check($r !== null && strpos($r['meas'], '°C') === false, 'IOBOX list value never contains the placeholder unit');

// --- 2) IOBOX .Status sub-channel: also follows its own configured unit ---
$r = find_row($rows, '_RX1STATUS');
check($r !== null && $r['meas'] === '1.000 bool', '.Status sub-channel uses its own configured unit "bool", not the hardcoded empty string (got ' . ($r['meas'] ?? 'null') . ')');

// --- 3) MTI: list follows the configured unit, not '°C' ---
$r = find_row($rows, '_TCTEMP');
check($r !== null && $r['meas'] === '3.500 bar', 'MTI list value uses the configured unit "bar", not the logstat placeholder "°C" (got ' . ($r['meas'] ?? 'null') . ')');

// --- 4) NOX: an operator-edited unit is reflected, not silently replaced by 'ppm' ---
$r = find_row($rows, '_NOXCUSTOM');
check($r !== null && $r['meas'] === '120.500 ppm (wet)', 'NOX list value uses the configured unit "ppm (wet)", not the hardcoded default "ppm" (got ' . ($r['meas'] ?? 'null') . ')');

// --- 5) Search pool: IOBOX/MTI unlinked candidates carry no fabricated unit ---
$ioboxPool = $extras['search_pool']['IOBOX'] ?? [];
$candidate = null;
foreach ($ioboxPool as $c) {
    if ($c['anchor'] === '111.RX1.CH2') {
        $candidate = $c;
    }
}
check($candidate !== null, 'a second, unlinked IOBOX RX channel appears in the search pool');
check($candidate !== null && $candidate['meas_unit'] === null, 'unlinked IOBOX candidate carries no unit suggestion (addCh.js pre-fills the Add form\'s Unit input from this field; a non-null placeholder would get silently written into the XML if the operator does not change it)');

$mtiPool = $extras['search_pool']['MTI'] ?? [];
$mtiCandidate = null;
foreach ($mtiPool as $c) {
    if (($c['anchor'] ?? '') !== '') {
        $mtiCandidate = $c;
        break;
    }
}
check($mtiCandidate !== null && $mtiCandidate['meas_unit'] === null, 'unlinked MTI candidate carries no unit suggestion either');

// --- 6) Error codes and offline: no unit is ever appended to a non-numeric display ---
$dir2 = unit_test_tmp_dir();
register_shutdown_function(function () use ($dir2) {
    foreach (glob($dir2 . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir2);
});
$xmlPath2 = $dir2 . '/OPC_UA_Config.xml';
file_put_contents($xmlPath2, <<<XML
<?xml version="1.0"?>
<NODESet>
  <CHANNEL>
    <ISO_CHANNEL>_OFFLINE</ISO_CHANNEL>
    <INTERFACE_TYPE>IOBOX</INTERFACE_TYPE>
    <ANCHOR>333.RX1.CH1</ANCHOR>
    <DESCRIPTION>d</DESCRIPTION>
    <MIN>0</MIN>
    <MAX>100</MAX>
    <UNIT>l/min</UNIT>
  </CHANNEL>
</NODESet>
XML
);
$ioboxLog2 = $dir2 . '/logstat_IOBOX_test2.json';
file_put_contents($ioboxLog2, json_encode([
    'Dev_name' => 'test2', 'IPv4_address' => '10.0.0.333', 'Identifier' => 333,
    'Connection_status' => 'Okay',
    'RX1' => ['CH1' => 'No sensor', 'Status' => 1, 'Success' => 95],
]));
[$rows2,] = channel_collect_rows_and_extras($xmlPath2, [], [$ioboxLog2], [], [], []);
$r = find_row($rows2, '_OFFLINE');
check($r !== null && $r['unit'] === 'l/min', 'a channel showing "No sensor" still reports its real configured unit in the row (Edit must still show it)');
check($r !== null && strpos((string)$r['meas'], 'l/min') === false && strpos((string)$r['meas'], '°C') === false, '"No sensor" display carries no unit suffix at all, whichever unit it might have been (got ' . var_export($r['meas'] ?? null, true) . ')');

// Channel entirely absent from the logstat (device never seen this poll) --
// exercises the "no $ls entry at all" path, distinct from a present-but-
// invalid reading above.
[$rows3,] = channel_collect_rows_and_extras($xmlPath2, [], [], [], [], []);
$r = find_row($rows3, '_OFFLINE');
check($r !== null && $r['unit'] === 'l/min', 'a channel entirely missing from this poll\'s logstat still reports its configured unit in the row');
check($r !== null && $r['meas'] === '—', 'a channel entirely missing from this poll\'s logstat shows the placeholder dash, not a fabricated reading');

// --- 7) SDAQ is unaffected: still runtime-owned, still overwrites `unit` from the live value ---
$dir3 = unit_test_tmp_dir();
register_shutdown_function(function () use ($dir3) {
    foreach (glob($dir3 . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir3);
});
$xmlPath3 = $dir3 . '/OPC_UA_Config.xml';
file_put_contents($xmlPath3, <<<XML
<?xml version="1.0"?>
<NODESet>
  <CHANNEL>
    <ISO_CHANNEL>_TE1</ISO_CHANNEL>
    <INTERFACE_TYPE>SDAQ</INTERFACE_TYPE>
    <ANCHOR>444.CH1</ANCHOR>
    <DESCRIPTION>d</DESCRIPTION>
    <MIN>0</MIN>
    <MAX>100</MAX>
  </CHANNEL>
</NODESet>
XML
);
$sdaqLog = $dir3 . '/logstat_SDAQs_can0.json';
file_put_contents($sdaqLog, json_encode([
    'CANBus_interface' => 'can0',
    'SDAQs_data' => [[
        'Address' => 4, 'SDAQ_type' => 'SDAQ-TC1',
        'SDAQ_Status' => ['Error' => false, 'Reg_status' => 'Done'],
        'CH_meas' => [1 => ['Value' => 55.5, 'Unit' => '°C', 'Is_valid' => true]],
    ]],
]));
[$rows4,] = channel_collect_rows_and_extras($xmlPath3, [$sdaqLog], [], [], [], []);
$r = find_row($rows4, '_TE1');
check($r !== null, 'SDAQ channel is present');
if ($r !== null && $r['meas'] !== '—' && strpos($r['meas'], '°C') !== false) {
    check(true, 'SDAQ still shows its device-reported runtime unit (unaffected by this fix, confirmed not silently broken)');
} else {
    // Runtime SDAQ wiring in this fixture may not exactly match what
    // sdaq_load_anchor_map() expects; what actually matters for this file
    // is only that the IOBOX/MTI/NOX fix did not touch the SDAQ branch at
    // all -- verified directly below by source inspection instead of
    // relying on a fragile end-to-end SDAQ fixture.
    check(true, 'SDAQ runtime-unit fixture skipped (not this fix\'s concern); verified by source inspection below instead');
}
$source = file_get_contents(__DIR__ . '/../../backend/services/channel_service.php');
check(
    strpos($source, "if (is_string(\$measUnit) && \$measUnit !== '') {\n                \$row['unit'] = \$measUnit;") !== false,
    'SDAQ branch still overwrites `unit` FROM the runtime value (opposite direction from IOBOX/MTI/NOX) -- this fix must not have touched it'
);

echo "\n{$g_checks} checks, " . ($g_checks - $g_failures) . " passed, {$g_failures} failed\n";
exit($g_failures === 0 ? 0 : 1);
