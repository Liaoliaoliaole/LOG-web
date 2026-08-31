<?php
/* Morfeas_Config.xml validation must accept and reject exactly as Core does. */

require __DIR__ . '/../../backend/core/log_config_validation.php';

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

// The real DTD, shared by Morfeas_Config.xml and OPC_UA_Config.xml -- not a
// test-local copy, so this test is exercising the same contract Core's own
// Morfeas_XML_parsing() (XML_PARSE_DTDVALID) checks against.
$coreDir = getenv('MORFEAS_CORE_SRC_DIR');
if ($coreDir === false || trim($coreDir) === '') {
    $coreDir = realpath(__DIR__ . '/../../../../LOG-core');
}
$dtdDir = $coreDir === false ? false : realpath($coreDir . '/configuration');
if ($dtdDir === false || !is_file($dtdDir . '/Morfeas.dtd')) {
    echo "SKIPPED: LOG-core/configuration/Morfeas.dtd not found (set MORFEAS_CORE_SRC_DIR or check out LOG-core as a sibling of LOG-web) -- this test validates against the real shared DTD, not a copy\n";
    exit(0);
}

function load_dom(string $xml): DOMDocument
{
    $dom = new DOMDocument('1.0');
    $dom->loadXML($xml);
    return $dom;
}

function shared_daemon_validation_cases(): array
{
    global $coreDir;
    $path = realpath($coreDir . '/tests/fixtures/daemon_config_validation_cases.json');
    if ($path === false) {
        throw new RuntimeException('Shared Core/Web daemon validation fixture corpus is missing');
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded) || !array_is_list($decoded) || $decoded === []) {
        throw new RuntimeException('Shared Core/Web daemon validation fixture corpus is invalid');
    }
    return $decoded;
}

function base_config(string $devName = 'Test-IOBox', string $ip = '10.193.135.20', string $devName2 = 'Test_MTI', string $ip2 = '10.193.135.28'): string
{
    return <<<XML
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
    <IOBOX_HANDLER Disable="false">
      <DEV_NAME>$devName</DEV_NAME>
      <IPv4_ADDR>$ip</IPv4_ADDR>
    </IOBOX_HANDLER>
    <MTI_HANDLER Disable="false">
      <DEV_NAME>$devName2</DEV_NAME>
      <IPv4_ADDR>$ip2</IPv4_ADDR>
    </MTI_HANDLER>
  </COMPONENTS>
</CONFIG>
XML;
}

// --- 1) A realistic, DTD-valid document (matches what real LOG devices
//        actually run) passes cleanly. ---
try {
    log_config_validate_document(load_dom(base_config()), $dtdDir);
    check(true, 'A realistic valid Morfeas_Config.xml passes');
} catch (ChannelConfigException $e) {
    check(false, 'A realistic valid Morfeas_Config.xml passes (got ' . $e->apiCode() . ': ' . $e->getMessage() . ')');
}

// --- 2) Missing DOCTYPE is rejected before DTD validation is even attempted. ---
try {
    $dom = new DOMDocument('1.0');
    $dom->loadXML('<CONFIG><CONFIGS_DIR>x</CONFIGS_DIR></CONFIG>');
    log_config_validate_document($dom, $dtdDir);
    check(false, 'Document with no DOCTYPE must throw');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'invalid_document_structure', 'Missing DOCTYPE rejected with invalid_document_structure (got ' . $e->apiCode() . ')');
}

// --- 3) DTD structural violation: COMPONENTS missing required OPC_UA_SERVER
//        (DTD requires it first, unconditionally). ---
$missingServer = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE CONFIG SYSTEM "Morfeas.dtd">
<CONFIG>
  <CONFIGS_DIR>/home/morfeas/configuration</CONFIGS_DIR>
  <LOGGERS_DIR>/mnt/ramdisk/Morfeas_Loggers/</LOGGERS_DIR>
  <LOGSTAT_DIR>/mnt/ramdisk/</LOGSTAT_DIR>
  <COMPONENTS>
    <SDAQ_HANDLER Disable="false">
      <CANBUS_IF>can0</CANBUS_IF>
    </SDAQ_HANDLER>
  </COMPONENTS>
</CONFIG>
XML;
try {
    log_config_validate_document(load_dom($missingServer), $dtdDir);
    check(false, 'COMPONENTS missing required OPC_UA_SERVER must throw');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'invalid_document_structure', 'Missing OPC_UA_SERVER rejected with invalid_document_structure (got ' . $e->apiCode() . ')');
}

// --- 4) DTD structural violation: wrong element order inside CONFIG
//        (DTD's sequence is fixed: CONFIGS_DIR, LOGGERS_DIR, LOGSTAT_DIR, COMPONENTS). ---
$wrongOrder = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE CONFIG SYSTEM "Morfeas.dtd">
<CONFIG>
  <LOGGERS_DIR>/mnt/ramdisk/Morfeas_Loggers/</LOGGERS_DIR>
  <CONFIGS_DIR>/home/morfeas/configuration</CONFIGS_DIR>
  <LOGSTAT_DIR>/mnt/ramdisk/</LOGSTAT_DIR>
  <COMPONENTS>
    <OPC_UA_SERVER>
      <APP_NAME>Morfeas_Default_app_32</APP_NAME>
    </OPC_UA_SERVER>
  </COMPONENTS>
</CONFIG>
XML;
try {
    log_config_validate_document(load_dom($wrongOrder), $dtdDir);
    check(false, 'CONFIG children out of DTD sequence order must throw');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'invalid_document_structure', 'Wrong element order rejected with invalid_document_structure (got ' . $e->apiCode() . ')');
}

// --- 5) Empty leaf element (DTD allows it -- #PCDATA -- but Core would
//        still choke on an empty DEV_NAME). ---
$emptyDevName = str_replace('<DEV_NAME>Test-IOBox</DEV_NAME>', '<DEV_NAME></DEV_NAME>', base_config());
try {
    log_config_validate_document(load_dom($emptyDevName), $dtdDir);
    check(false, 'Empty DEV_NAME must throw even though the DTD alone would accept it');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'empty_element', 'Empty DEV_NAME rejected with empty_element (got ' . $e->apiCode() . ')');
}

// --- 6) Core accepts 16-byte names, but new names are capped at 15. ---
$name15 = str_repeat('A', 15);
$name16 = str_repeat('A', 16);
$name17 = str_repeat('A', 17);
try {
    log_config_validate_document(load_dom(base_config($name15)), $dtdDir);
    check(true, "DEV_NAME of 15 bytes (Core's IFNAMSIZ-1) is accepted");
} catch (ChannelConfigException $e) {
    check(false, "DEV_NAME of 15 bytes (Core's IFNAMSIZ-1) is accepted (got " . $e->apiCode() . ')');
}
try {
    log_config_validate_document(load_dom(base_config($name16)), $dtdDir);
    check(true, "DEV_NAME of 16 bytes is accepted, because Core's index-based loop accepts it too");
} catch (ChannelConfigException $e) {
    check(false, "DEV_NAME of 16 bytes is accepted, because Core's index-based loop accepts it too (got " . $e->apiCode() . ': ' . $e->getMessage() . ')');
}
$warn16 = log_config_collect_document_warnings(load_dom(base_config($name16)));
check(count($warn16) === 1 && $warn16[0]['code'] === 'dev_name_at_ifnamsiz', 'DEV_NAME of 16 bytes is reported as a dev_name_at_ifnamsiz warning instead');
check(log_config_collect_document_warnings(load_dom(base_config($name15))) === [], 'DEV_NAME of 15 bytes produces no warning');
try {
    log_config_validate_document(load_dom(base_config($name17)), $dtdDir);
    check(false, 'DEV_NAME of 17 bytes (the first length Core rejects) must throw');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'invalid_device_name', 'DEV_NAME of 17 bytes rejected with invalid_device_name (got ' . $e->apiCode() . ')');
}

// --- 7) Duplicate DEV_NAME across IOBOX_HANDLER/MTI_HANDLER. ---
$dupName = base_config('Same-Name', '10.193.135.20', 'Same-Name', '10.193.135.28');
try {
    log_config_validate_document(load_dom($dupName), $dtdDir);
    check(false, 'Duplicate DEV_NAME across IOBOX_HANDLER/MTI_HANDLER must throw');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'duplicate_device_name', 'Duplicate DEV_NAME rejected with duplicate_device_name (got ' . $e->apiCode() . ')');
}

// --- 8) Duplicate IPv4_ADDR across IOBOX_HANDLER/MTI_HANDLER. ---
$dupIp = base_config('Test-IOBox', '10.193.135.20', 'Test_MTI', '10.193.135.20');
try {
    log_config_validate_document(load_dom($dupIp), $dtdDir);
    check(false, 'Duplicate IPv4_ADDR across IOBOX_HANDLER/MTI_HANDLER must throw');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'duplicate_device_ipv4', 'Duplicate IPv4_ADDR rejected with duplicate_device_ipv4 (got ' . $e->apiCode() . ')');
}

// --- 9) Different DEV_NAME + different IPv4_ADDR on two handlers of the
//        SAME type (two IOBOX_HANDLERs) is legal -- only exact duplicates
//        are rejected. ---
$twoIobox = <<<XML
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
    <IOBOX_HANDLER Disable="false">
      <DEV_NAME>IOBox-A</DEV_NAME>
      <IPv4_ADDR>10.193.135.20</IPv4_ADDR>
    </IOBOX_HANDLER>
    <IOBOX_HANDLER Disable="false">
      <DEV_NAME>IOBox-B</DEV_NAME>
      <IPv4_ADDR>10.193.135.21</IPv4_ADDR>
    </IOBOX_HANDLER>
  </COMPONENTS>
</CONFIG>
XML;
try {
    log_config_validate_document(load_dom($twoIobox), $dtdDir);
    check(true, 'Two IOBOX_HANDLERs with distinct DEV_NAME/IPv4_ADDR are both legal');
} catch (ChannelConfigException $e) {
    check(false, 'Two IOBOX_HANDLERs with distinct DEV_NAME/IPv4_ADDR are both legal (got ' . $e->apiCode() . ')');
}

// --- 10) An element outside the DTD's content model (e.g. a typo'd tag)
//         is rejected the same way Core's own DTD-validating parser would
//         refuse to load the file at all. ---
$unknownElement = str_replace('</CONFIG>', '<NOT_A_REAL_TAG>x</NOT_A_REAL_TAG></CONFIG>', base_config());
try {
    log_config_validate_document(load_dom($unknownElement), $dtdDir);
    check(false, 'An element outside the DTD content model must throw');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'invalid_document_structure', 'Element outside DTD content model rejected with invalid_document_structure (got ' . $e->apiCode() . ')');
}

// --- 11) The entity loader must not leak: validating one bad document and
//         one good document back to back must not leave libxml's external
//         entity loader (process-global state) stuck from the first call. ---
try {
    log_config_validate_document(load_dom($missingServer), $dtdDir);
} catch (ChannelConfigException $e) {
    // expected
}
try {
    log_config_validate_document(load_dom(base_config()), $dtdDir);
    check(true, 'A valid document still validates correctly after a prior call threw (entity loader does not leak state)');
} catch (ChannelConfigException $e) {
    check(false, 'A valid document still validates correctly after a prior call threw (got ' . $e->apiCode() . ')');
}

// =====================================================================
// 12) Disable attribute range (Core: Morfeas_XML.c:1013-1030).
//
// The DTD declares Disable as CDATA, so Disable="maybe" is structurally
// valid and DTD validation alone lets it through -- Core then refuses to
// start. Absent means the DTD's declared default "false"; Core sees that
// through xmlGetProp()'s DTD fallback, so an absent attribute must NOT be
// treated as an error.
// =====================================================================

foreach (['maybe', 'yes', 'TRUE', 'True', ' true', '1', ''] as $bad) {
    $doc = str_replace('<SDAQ_HANDLER Disable="false">', '<SDAQ_HANDLER Disable="' . $bad . '">', base_config());
    try {
        log_config_validate_document(load_dom($doc), $dtdDir);
        check(false, "Disable=\"$bad\" must throw");
    } catch (ChannelConfigException $e) {
        check($e->apiCode() === 'invalid_disable_attribute', "Disable=\"$bad\" rejected with invalid_disable_attribute (got " . $e->apiCode() . ')');
    }
}

$noDisable = str_replace('<SDAQ_HANDLER Disable="false">', '<SDAQ_HANDLER>', base_config());
try {
    log_config_validate_document(load_dom($noDisable), $dtdDir);
    check(true, 'An omitted Disable attribute is accepted (the DTD declares a "false" default that xmlGetProp() returns to Core)');
} catch (ChannelConfigException $e) {
    check(false, 'An omitted Disable attribute is accepted (got ' . $e->apiCode() . ': ' . $e->getMessage() . ')');
}

// =====================================================================
// 13) Raw (untrimmed) content, matching Core's XML_node_get_content() +
//     strcmp()/strstr()/inet_pton() on the raw bytes. Trimming first broke
//     equivalence in BOTH directions, so both directions are asserted.
// =====================================================================

// Too loose without this: trimming turns each of these into a value that
// passes, while Core scans the raw bytes and refuses to start.
foreach ([
    ['Test-IOBox ', '10.193.135.20', 'DEV_NAME with a trailing space', 'invalid_device_name'],
    [' Test-IOBox', '10.193.135.20', 'DEV_NAME with a leading space', 'invalid_device_name'],
    ['Test-IOBox', ' 10.193.135.20', 'IPv4_ADDR with a leading space', 'invalid_device_ipv4'],
    ['Test-IOBox', '10.193.135.20 ', 'IPv4_ADDR with a trailing space', 'invalid_device_ipv4'],
] as [$name, $ip, $label, $expected]) {
    try {
        log_config_validate_document(load_dom(base_config($name, $ip)), $dtdDir);
        check(false, "$label must throw (Core sees the raw bytes)");
    } catch (ChannelConfigException $e) {
        check($e->apiCode() === $expected, "$label rejected with $expected (got " . $e->apiCode() . ')');
    }
}

$appNameTrailingSpace = str_replace('<APP_NAME>Morfeas_Default_app_32</APP_NAME>', '<APP_NAME>Morfeas_app </APP_NAME>', base_config());
try {
    log_config_validate_document(load_dom($appNameTrailingSpace), $dtdDir);
    check(false, 'APP_NAME with a trailing space must throw (Core uses strstr() on the raw content)');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'invalid_app_name', 'APP_NAME with a trailing space rejected with invalid_app_name (got ' . $e->apiCode() . ')');
}

// Too strict with trimming: an IPv4_ADDR that is legal but whose sibling
// differs only by surrounding whitespace is two distinct strings to
// strcmp(). There is no legal-name equivalent to assert, because any name
// that trims to the same string differs by whitespace and is therefore
// already rejected by the illegal-character scan above -- the observable
// cost of trimming here is that it merged those two into one "duplicate"
// error and hid the real one.
$tabbedTwin = <<<XML
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
    <SDAQ_HANDLER Disable="false">
      <CANBUS_IF>can0	</CANBUS_IF>
    </SDAQ_HANDLER>
  </COMPONENTS>
</CONFIG>
XML;
try {
    log_config_validate_document(load_dom($tabbedTwin), $dtdDir);
    check(true, 'Two CANBUS_IF values differing only by a trailing tab are distinct to strcmp(), so not a duplicate (trimming would false-reject this)');
} catch (ChannelConfigException $e) {
    check(false, 'Two CANBUS_IF values differing only by a trailing tab are distinct to strcmp() (got ' . $e->apiCode() . ': ' . $e->getMessage() . ')');
}

// =====================================================================
// 14) All three CANBUS_IF duplicate scans (Core: Morfeas_XML.c:1052-1136).
//     They are three separate loops with two different rules: the
//     same-type scans ignore Disable, the cross-type scan honours it.
// =====================================================================

function can_config(string $components): string
{
    return <<<XML
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
XML;
}

$sdaq = fn (string $bus, string $disable = 'false') => "    <SDAQ_HANDLER Disable=\"$disable\"><CANBUS_IF>$bus</CANBUS_IF></SDAQ_HANDLER>";
$nox  = fn (string $bus, string $disable = 'false') => "    <NOX_HANDLER Disable=\"$disable\"><CANBUS_IF>$bus</CANBUS_IF></NOX_HANDLER>";

foreach ([
    [$sdaq('can0') . "\n" . $sdaq('can0'), 'Two enabled SDAQ_HANDLERs on the same bus'],
    [$sdaq('can0') . "\n" . $sdaq('can0', 'true'), 'Two SDAQ_HANDLERs on the same bus with one disabled (Core scan 1 ignores Disable)'],
    [$sdaq('can0', 'true') . "\n" . $sdaq('can0', 'true'), 'Two DISABLED SDAQ_HANDLERs on the same bus (Core scan 1 ignores Disable)'],
    [$nox('can0') . "\n" . $nox('can0'), 'Two enabled NOX_HANDLERs on the same bus'],
    [$nox('can0', 'true') . "\n" . $nox('can0', 'true'), 'Two DISABLED NOX_HANDLERs on the same bus (Core scan 2 ignores Disable)'],
    [$sdaq('can0') . "\n" . $nox('can0'), 'An enabled SDAQ_HANDLER and an enabled NOX_HANDLER on the same bus (Core scan 3, cross-type)'],
] as [$components, $label]) {
    try {
        log_config_validate_document(load_dom(can_config($components)), $dtdDir);
        check(false, "$label must throw");
    } catch (ChannelConfigException $e) {
        check($e->apiCode() === 'duplicate_can_bus', "$label rejected with duplicate_can_bus (got " . $e->apiCode() . ')');
    }
}

// MUST STILL PASS. Core's cross-type scan skips disabled nodes, so a bus
// handed from SDAQ to NOX by disabling one side is legal -- and that is
// exactly what the CAN role transition writes, so rejecting it would break
// a supported UI flow.
foreach ([
    [$sdaq('can0', 'true') . "\n" . $nox('can0'), 'A disabled SDAQ_HANDLER and an enabled NOX_HANDLER on the same bus (Core scan 3 skips disabled nodes)'],
    [$sdaq('can0') . "\n" . $nox('can1'), 'An SDAQ_HANDLER and a NOX_HANDLER on different buses'],
    [$sdaq('can0') . "\n" . $sdaq('CAN0'), 'Two SDAQ_HANDLERs whose bus names differ only in case (strcmp() is case-sensitive)'],
] as [$components, $label]) {
    try {
        log_config_validate_document(load_dom(can_config($components)), $dtdDir);
        check(true, "$label is legal");
    } catch (ChannelConfigException $e) {
        check(false, "$label is legal (got " . $e->apiCode() . ': ' . $e->getMessage() . ')');
    }
}

// Representative mixed-handler configuration must pass without warnings.
$representativeConfig = can_config(
    $sdaq('vcan0', 'true') . "\n"
    . $sdaq('can0') . "\n"
    . $sdaq('can1') . "\n"
    . '    <IOBOX_HANDLER Disable="false"><DEV_NAME>BoxA</DEV_NAME><IPv4_ADDR>192.168.234.141</IPv4_ADDR></IOBOX_HANDLER>' . "\n"
    . '    <IOBOX_HANDLER Disable="false"><DEV_NAME>BoxB</DEV_NAME><IPv4_ADDR>192.168.234.142</IPv4_ADDR></IOBOX_HANDLER>'
);
$dom = load_dom($representativeConfig);
try {
    log_config_validate_document($dom, $dtdDir);
    check(true, 'A mixed-handler configuration (disabled vcan0, live can0/can1, two IOBOX) passes every rule');
} catch (ChannelConfigException $e) {
    check(false, 'The mixed-handler configuration passes every rule (got ' . $e->apiCode() . ': ' . $e->getMessage() . ')');
}
check(log_config_collect_document_warnings($dom) === [], 'The mixed-handler configuration produces no warnings');

// Shared fixture corpus: Core's daemon_config_fixture_test executes these
// same inputs against Morfeas_daemon_config_valid(). This side must stay in
// lockstep for each documented semantic accept/reject decision.
try {
    $sharedCases = shared_daemon_validation_cases();
    check(true, 'Shared Core/Web daemon validation fixture corpus is readable');
    foreach ($sharedCases as $case) {
        $name = (string)($case['name'] ?? 'unnamed');
        $expected = !empty($case['accepted']);
        $dom = load_dom((string)($case['xml'] ?? ''));
        try {
            log_config_validate_document($dom, $dtdDir);
            $actual = true;
        } catch (ChannelConfigException $e) {
            $actual = false;
        }
        check($actual === $expected, "shared fixture $name is " . ($expected ? 'accepted' : 'rejected') . ' by Web validator');
        $expectedWarnings = array_values((array)($case['web_warning_codes'] ?? []));
        $actualWarnings = array_map(
            static fn(array $warning): string => (string)($warning['code'] ?? ''),
            log_config_collect_document_warnings($dom)
        );
        check($actualWarnings === $expectedWarnings, "shared fixture $name has the documented Web warning set");
    }
} catch (Throwable $e) {
    check(false, 'Shared Core/Web daemon validation fixture corpus is usable (' . $e->getMessage() . ')');
}

echo "\n{$g_checks} checks, " . ($g_checks - $g_failures) . " passed, {$g_failures} failed\n";
exit($g_failures === 0 ? 0 : 1);
