<?php

require __DIR__ . '/../../backend/core/logstat_iobox.php';
require __DIR__ . '/../../backend/core/logstat_mti.php';
require __DIR__ . '/../../backend/core/logstat_sdaq.php';

$checks = 0;
$failures = 0;

function parser_check(bool $condition, string $message): void
{
    global $checks, $failures;
    $checks++;
    if ($condition) {
        echo "PASS: $message\n";
        return;
    }

    $failures++;
    echo "FAIL: $message\n";
}

function parser_fixture_dir(): string
{
    $dir = sys_get_temp_dir() . '/morfeas_logstat_parser_' . bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    return $dir;
}

function parser_write_json(string $dir, string $name, array $data): string
{
    $path = $dir . '/' . $name;
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return $path;
}

$dir = parser_fixture_dir();
register_shutdown_function(static function () use ($dir): void {
    foreach (glob($dir . '/*') ?: [] as $path) {
        @unlink($path);
    }
    @rmdir($dir);
});

// IOBOX: normal RX data, link metrics, reserved measurement errors, and an
// offline device snapshot must each have a stable, non-fatal representation.
$ioboxOnline = parser_write_json($dir, 'logstat_iobox_online.json', [
    'Identifier' => ' 101 ',
    'IPv4_address' => '192.0.2.101',
    'Connection_status' => 'Okay',
    'RX1' => [
        'CH1' => 42.5,
        'CH2' => -905,
        'CH3' => 'No sensor',
        'Status' => 0,
        'Success' => 98,
    ],
]);
$ioboxOffline = parser_write_json($dir, 'logstat_iobox_offline.json', [
    'Identifier' => 102,
    'IPv4_address' => '192.0.2.102',
    'Connection_status' => 'Disconnected',
    'RX1' => ['CH1' => 1],
]);
$ioboxInvalid = $dir . '/logstat_iobox_invalid.json';
file_put_contents($ioboxInvalid, '{not json');
$iobox = iobox_load_anchor_map([$ioboxOnline, $ioboxOffline, $ioboxInvalid, $dir . '/missing.json']);

parser_check($iobox['ipv4']['101'] === '192.0.2.101', 'IOBOX parser trims string identifiers and retains IPv4 metadata');
parser_check($iobox['anchors']['101.RX1.CH1']['meas_value'] === 42.5 && $iobox['anchors']['101.RX1.CH1']['is_meas_valid'] === true, 'IOBOX parser exposes a normal channel measurement');
parser_check($iobox['anchors']['101.RX1.CH2']['status'] === 'Unreachable' && $iobox['anchors']['101.RX1.CH2']['meas_value'] === -905.0, 'IOBOX parser preserves a reserved error value with its status');
parser_check($iobox['anchors']['101.RX1.CH3']['status'] === 'No sensor' && $iobox['anchors']['101.RX1.CH3']['meas_value'] === null, 'IOBOX parser handles the textual No sensor state');
parser_check($iobox['anchors']['101.RX1.Status']['meas_value'] === 0.0 && $iobox['anchors']['101.RX1.Success']['status'] === 'Disconnected', 'IOBOX parser preserves RX status and link-quality semantics');
parser_check(!isset($iobox['anchors']['102.RX1.CH1']) && $iobox['connections']['102'] === 'Disconnected', 'IOBOX parser keeps an offline device diagnostic but does not fabricate channel data');

// MTI: cover both ordinary TC telemetry and the distinct Mini RMSW/MUX shape.
$mtiTc = parser_write_json($dir, 'logstat_mti_tc.json', [
    'Identifier' => 202,
    'IPv4_address' => '192.0.2.202',
    'Connection_status' => 'Okay',
    'MTI_status' => ['Tele_Device_type' => 'Tele_TC16'],
    'Tele_data' => ['IsValid' => true, 'CHs' => [12.25, -902]],
]);
$mtiMux = parser_write_json($dir, 'logstat_mti_mux.json', [
    'Identifier' => 203,
    'Connection_status' => 'Okay',
    'MTI_status' => ['Tele_Device_type' => 'Tele_RMSW/MUX'],
    'Tele_data' => [[
        'Dev_type' => 'Mini_RMSW',
        'Dev_ID' => 7,
        'CHs_meas' => [4.5],
    ]],
]);
$mtiIncomplete = parser_write_json($dir, 'logstat_mti_incomplete.json', [
    'Identifier' => 204,
    'Connection_status' => 'Okay',
    'MTI_status' => ['Tele_Device_type' => 'Tele_TC4'],
]);
$mti = mti_load_anchor_map([$mtiTc, $mtiMux, $mtiIncomplete, $dir . '/missing-mti.json']);

parser_check($mti['anchors']['202.TC16.CH1']['meas_value'] === 12.25 && $mti['anchors']['202.TC16.CH1']['status'] === 'Okay', 'MTI parser exposes valid TC telemetry under the canonical anchor');
parser_check($mti['anchors']['202.TC16.CH2']['status'] === 'No sensor' && $mti['anchors']['202.TC16.CH2']['is_meas_valid'] === false, 'MTI parser maps reserved TC errors without treating them as live data');
parser_check(isset($mti['anchors']['202.Tele_TC16.CH1']), 'MTI parser retains the legacy telemetry-type alias');
parser_check($mti['anchors']['203.ID:7.CH1']['meas_value'] === 4.5, 'MTI parser supports Mini RMSW/MUX telemetry');
parser_check($mti['tele_types']['204'] === 'Tele_TC4' && !isset($mti['anchors']['204.TC4.CH1']), 'MTI parser keeps metadata but safely ignores incomplete telemetry');

// SDAQ: identity must retain canonical serial.CHn only when supplied by the
// runtime snapshot; CAN address aliases remain diagnostics, never candidates.
$sdaqOnline = parser_write_json($dir, 'logstat_SDAQ_fixture_can3.json', [
    'CANBus_interface' => 'can3',
    'SDAQs_data' => [[
        'Address' => 5,
        'Serial_number' => '796834087',
        'SDAQ_type' => 'SDAQ-TC',
        'SDAQ_Status' => ['Registration_status' => 'Done'],
        'Calibration_Data' => [[
            'Channel' => 1,
            'Calibration_date_UNIX' => 1704067200,
            'Calibration_period' => 12,
        ]],
        'Meas' => [[
            'Channel' => 1,
            'CNT' => 3,
            'Unit' => 'bar',
            'Last_Meas' => 7.5,
            'Channel_Status' => ['Channel_status_val' => 0, 'No_Sensor' => false],
        ], [
            'Channel' => 2,
            'CNT' => 1,
            'Unit' => 'C',
            'Last_Meas' => -902,
            'Channel_Status' => ['Channel_status_val' => 0, 'No_Sensor' => true],
        ]],
    ]],
]);
$sdaqInvalid = $dir . '/logstat_SDAQ_invalid_can4.json';
file_put_contents($sdaqInvalid, '{not json');
$sdaq = sdaq_load_anchor_map($sdaqOnline, ['796834087.CH1' => true]);
$sdaqInvalidMap = sdaq_load_anchor_map($sdaqInvalid);
$sdaqTypes = sdaq_collect_device_types([$sdaqOnline, $sdaqInvalid]);

parser_check($sdaq['channels'][0]['serial_anchor'] === '796834087.CH1' && $sdaq['channels'][0]['display_anchor'] === '796834087.CH1', 'SDAQ parser exposes serial.CHn as the channel identity when registration supplied a serial');
parser_check($sdaq['channels'][0]['address_anchor'] === 'CAN3.ADDR:05.CH:01' && $sdaq['channels'][0]['link_state'] === 'Linked', 'SDAQ parser keeps the CAN address only as diagnostic metadata');
parser_check($sdaq['anchors']['796834087.CH1']['meas_unit'] === 'bar' && $sdaq['anchors']['796834087.CH1']['cal_period'] === 12, 'SDAQ parser preserves live runtime unit and calibration metadata');
parser_check($sdaq['channels'][1]['has_sensor'] === false && $sdaq['anchors']['796834087.CH2']['status'] === 'NO_Sensor', 'SDAQ parser represents a real channel with no connected sensor');
parser_check($sdaqTypes['CAN3.ADDR:05'] === 'SDAQ-TC', 'SDAQ device-type collection uses the snapshot CAN address');
parser_check($sdaqInvalidMap === ['anchors' => [], 'channels' => []], 'SDAQ parser ignores malformed JSON without producing partial identity data');

echo "\n$checks checks, " . ($checks - $failures) . " passed, $failures failed\n";
exit($failures === 0 ? 0 : 1);
