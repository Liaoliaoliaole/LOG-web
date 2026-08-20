<?php
/*
 * tests/php/deviceWriterTest.php
 *
 * Regression test for the ordinary (non-FTP) Morfeas_Config.xml writers,
 * plan §10.0.9 F-15: Device Add and the CAN role transition could write a
 * document Core refuses to start on, and then restart Core into it.
 *
 * Three things are asserted, each against real files on disk and the real
 * shared DTD -- no stubs, because what F-15 was about is precisely the gap
 * between what the writers checked and what the file ended up containing:
 *
 *   1. the DEV_NAME length a new device may be given,
 *   2. that name/IP uniqueness is enforced by the writer itself, inside the
 *      lock, rather than by a caller reading a snapshot beforehand,
 *   3. that the whole-document Core-equivalence validator now gates every
 *      write that adds a component -- and that a rejected write leaves the
 *      file byte-for-byte unchanged, since the caller restarts Core
 *      immediately after a successful one.
 *
 * Run: php tests/php/deviceWriterTest.php   (from Morfeas_WEB/)
 */

require __DIR__ . '/../../backend/services/device_service.php';

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

$dtdSource = realpath(__DIR__ . '/../../../../LOG-core/configuration/Morfeas.dtd');
if ($dtdSource === false) {
    echo "SKIPPED: LOG-core/configuration/Morfeas.dtd not found (set up LOG-core as a sibling of LOG-web) -- these writers validate against the real shared DTD, not a copy\n";
    exit(0);
}

// The writers resolve the DTD from dirname($xmlPath), exactly as the running
// system does (/home/morfeas/configuration holds both files), so the fixture
// has to be a directory containing both.
$tmpDir = sys_get_temp_dir() . '/morfeas_device_writer_test_' . getmypid();
@mkdir($tmpDir, 0775, true);
copy($dtdSource, $tmpDir . '/Morfeas.dtd');
$xmlPath = $tmpDir . '/Morfeas_config.xml';

register_shutdown_function(function () use ($tmpDir) {
    foreach (glob($tmpDir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($tmpDir);
});

function write_config(string $xmlPath, string $components): void
{
    file_put_contents($xmlPath, <<<XML
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
$components
  </COMPONENTS>
</CONFIG>
XML);
}

$iobox = '    <IOBOX_HANDLER Disable="false"><DEV_NAME>IOBOX1</DEV_NAME><IPv4_ADDR>192.168.234.141</IPv4_ADDR></IOBOX_HANDLER>';
$sdaqCan0 = '    <SDAQ_HANDLER Disable="false"><CANBUS_IF>can0</CANBUS_IF></SDAQ_HANDLER>';

// =====================================================================
// 1) devices_validate_name(): the length a new name may have.
//
// This accepted 64 characters before F-15. The limit is now 15 = IFNAMSIZ-1,
// one byte tighter than the >16 Core's daemon-config validator enforces,
// because at exactly 16 the IOBOX handler exits and the MTI handler
// truncates -- see log_config_dev_name_safe_max_length().
// =====================================================================

check(log_config_dev_name_safe_max_length() === 15, 'The creation limit is 15 bytes (IFNAMSIZ-1)');
check(devices_validate_name(str_repeat('A', 15), 'IOBOX') === true, 'A 15-byte name is accepted');
check(devices_validate_name(str_repeat('A', 16), 'IOBOX') === false, 'A 16-byte name is refused, even though Core would load a config containing one');
check(devices_validate_name(str_repeat('A', 64), 'IOBOX') === false, 'A 64-byte name is refused (this was the old limit)');
check(devices_validate_name('', 'IOBOX') === false, 'An empty name is refused');
check(devices_validate_name('IOBOX_01', 'IOBOX') === true, 'A realistic name is accepted');
check(devices_validate_name('Test-IOBox', 'IOBOX') === true, 'A hyphenated IOBOX name is accepted');
check(devices_validate_name('Bad Name', 'IOBOX') === false, 'A name containing a space is refused');
check(devices_validate_name("Bad'Name", 'IOBOX') === false, 'A name containing a quote is refused');

// MTI names become a D-Bus interface name element -- Morfeas_MTI_DBus.c
// concatenates "Morfeas.MTI." with the raw DEV_NAME -- so they may not
// contain a hyphen or start with a digit. devices.js has enforced this in
// the browser all along while the server accepted it, so a direct API call
// could still create an MTI whose D-Bus registration then fails.
check(devices_validate_name('Test_MTI', 'MTI') === true, 'A valid D-Bus element name is accepted for MTI');
check(devices_validate_name('Test-MTI', 'MTI') === false, 'A hyphenated name is refused for MTI (invalid D-Bus interface name element)');
check(devices_validate_name('1MTI', 'MTI') === false, 'A name starting with a digit is refused for MTI');
check(devices_validate_name('Test-MTI', 'IOBOX') === true, 'The same hyphenated name is still fine for IOBOX, which has no D-Bus name derived from it');

// =====================================================================
// 2) Uniqueness, enforced by the writer inside the lock.
//
// The check that used to guard this ran in api_devices.php against a
// snapshot read before the lock, and the in-lock check compared only the
// composed id ("IOBOX:-:Name") -- so a same-name/different-IP Add passed
// both. These call log_config_append_device() directly, i.e. below the
// layer where the old check lived, which is the only way to show the
// enforcement really moved.
// =====================================================================

write_config($xmlPath, $iobox);
$before = file_get_contents($xmlPath);

foreach ([
    ['IOBOX1', '10.0.0.9', 'a device whose name duplicates an existing handler'],
    ['iobox1', '10.0.0.9', 'a device whose name duplicates an existing handler in a different case'],
    ['IOBOX9', '192.168.234.141', 'a device whose IPv4 duplicates an existing handler'],
] as [$name, $ip, $label]) {
    try {
        log_config_append_device($xmlPath, ['type' => 'IOBOX', 'bus' => '-', 'name' => $name, 'ip' => $ip]);
        check(false, "log_config_append_device() must refuse $label");
    } catch (ChannelConfigException $e) {
        check($e->apiCode() === 'duplicate_device' && $e->status() === 409, "log_config_append_device() refuses $label with a 409 duplicate_device (got " . $e->status() . ' ' . $e->apiCode() . ')');
    }
    check(file_get_contents($xmlPath) === $before, "The file is unchanged after refusing $label");
}

$device = log_config_append_device($xmlPath, ['type' => 'MTI', 'bus' => '-', 'name' => 'Test_MTI', 'ip' => '192.168.234.150']);
check($device['name'] === 'Test_MTI', 'A device that collides with nothing is appended');
check(strpos(file_get_contents($xmlPath), '<DEV_NAME>Test_MTI</DEV_NAME>') !== false, 'The appended device is in the file');

// =====================================================================
// 3) The whole-document validator gates the writers.
//
// log_config_validate_document() existed before F-15 but was reachable only
// from FTP Restore. The proof that it now runs on the ordinary write path
// is a document defect that has nothing to do with the device being added
// and that only that validator can see: Disable="maybe" is CDATA, so it
// passes DTD validation, and Core refuses to start on it.
// =====================================================================

write_config($xmlPath, '    <SDAQ_HANDLER Disable="maybe"><CANBUS_IF>can0</CANBUS_IF></SDAQ_HANDLER>');
$before = file_get_contents($xmlPath);
try {
    log_config_append_device($xmlPath, ['type' => 'IOBOX', 'bus' => '-', 'name' => 'NewBox', 'ip' => '10.0.0.5']);
    check(false, 'Adding a device to a config Core would refuse to start on must fail');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'invalid_disable_attribute', 'Adding a device to a config Core would refuse to start on fails with the document error (got ' . $e->apiCode() . ')');
}
check(file_get_contents($xmlPath) === $before, 'Nothing is written when whole-document validation fails -- the caller restarts Core straight after a successful write');

// The CAN role writer is the second entry point F-15 named. Handing can0 to
// NOX while an enabled SDAQ_HANDLER still owns it is a config Core rejects
// (Morfeas_XML.c's cross-handler CANBUS_IF scan).
write_config($xmlPath, $sdaqCan0);
$before = file_get_contents($xmlPath);
try {
    log_config_set_can_role($xmlPath, 'can1', 'NOX');
    check(true, 'A CAN role change onto a free bus succeeds');
} catch (ChannelConfigException $e) {
    check(false, 'A CAN role change onto a free bus succeeds (got ' . $e->apiCode() . ': ' . $e->getMessage() . ')');
}

// Removals must stay possible even on a document that is already invalid,
// or the UI could not repair a config a hand edit had broken.
write_config($xmlPath, '    <SDAQ_HANDLER Disable="maybe"><CANBUS_IF>can0</CANBUS_IF></SDAQ_HANDLER>');
try {
    log_config_set_can_role($xmlPath, 'can0', 'FREE');
    check(strpos(file_get_contents($xmlPath), 'can0') === false, 'Setting a bus FREE still works on an already-invalid document (removal is never gated)');
} catch (ChannelConfigException $e) {
    check(false, 'Setting a bus FREE still works on an already-invalid document (got ' . $e->apiCode() . ': ' . $e->getMessage() . ')');
}

write_config($xmlPath, $iobox);
log_config_delete_devices($xmlPath, ['IOBOX:-:IOBOX1']);
check(strpos(file_get_contents($xmlPath), 'IOBOX1') === false, 'Device delete still works');

echo "\n{$g_checks} checks, " . ($g_checks - $g_failures) . " passed, {$g_failures} failed\n";
exit($g_failures === 0 ? 0 : 1);
