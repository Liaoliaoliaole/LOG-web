<?php
/*
 * SDAQ Unit ownership regression tests.
 * Run from Morfeas_WEB/: php tests/php/sdaqUnitContractTest.php
 */

require __DIR__ . '/../../backend/core/opcua_config.php';

$checks = 0;
$failures = 0;

function check(bool $condition, string $message): void
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

function expect_code(callable $operation, string $code, string $message): void
{
    try {
        $operation();
        check(false, $message . ' (unexpectedly accepted)');
    } catch (ChannelConfigException $e) {
        check($e->apiCode() === $code, $message . ' (got ' . $e->apiCode() . ')');
    }
}

$dir = sys_get_temp_dir() . '/sdaq_unit_contract_' . uniqid();
mkdir($dir, 0700, true);
$xmlPath = $dir . '/OPC_UA_Config.xml';
file_put_contents($xmlPath, "<?xml version=\"1.0\"?>\n<NODESet/>\n");

$before = hash_file('sha256', $xmlPath);
expect_code(
    static fn() => iso_add_channel_body($xmlPath, [
        'iso_channel' => '_SDAQ_BAD',
        'interface_type' => 'SDAQ',
        'anchor' => '796834087.CH1',
        'description' => 'runtime unit',
        'min' => '0',
        'max' => '1',
        'unit' => 'bar',
    ]),
    'sdaq_unit_not_allowed',
    'backend rejects a SDAQ Add payload carrying UNIT'
);
check(hash_file('sha256', $xmlPath) === $before, 'rejected SDAQ Add leaves final XML bytes unchanged');

iso_add_channel_body($xmlPath, [
    'iso_channel' => '_SDAQ_OK',
    'interface_type' => 'SDAQ',
    'anchor' => '796834087.CH1',
    'description' => 'runtime unit',
    'min' => '0',
    'max' => '1',
]);
$bytes = file_get_contents($xmlPath);
$parsed = simplexml_load_string($bytes);
check($parsed !== false && !isset($parsed->CHANNEL->UNIT), 'valid SDAQ Add writes no UNIT element');

$addJs = file_get_contents(__DIR__ . '/../../tool-bar/addCh.js');
check(
    str_contains($addJs, "setDisabled(unitInput, isMulti || typeSel.value === 'SDAQ')"),
    'Add UI disables Unit for single and range SDAQ'
);
check(
    str_contains($addJs, "if (type !== 'SDAQ') {\n      payload.unit = unit;"),
    'Add UI omits Unit from SDAQ payloads'
);

$indexJs = file_get_contents(__DIR__ . '/../../assets/index.js');
check(
    str_contains($indexJs, "if (type && type !== 'SDAQ')")
        && str_contains($indexJs, 'entry.UNIT = channel.unit'),
    'Export emits Unit and calibration only for non-SDAQ channels'
);

$editJs = file_get_contents(__DIR__ . '/../../linker-table/editCh.js');
check(
    str_contains($editJs, "const sdaqRuntimeMetadata = state.sourceFamily === 'SDAQ'")
        && str_contains($editJs, 'setDisabled(unitInput, sdaqRuntimeMetadata)'),
    'Edit UI renders SDAQ Unit as read-only runtime metadata'
);

$restoreSource = file_get_contents(__DIR__ . '/../../backend/services/channel_restore_service.php');
check(
    str_contains($restoreSource, "'unit'           => \$data['interface_type'] === 'SDAQ' ? null")
        && str_contains($restoreSource, "'cal_date'       => \$data['interface_type'] === 'SDAQ' ? null")
        && str_contains($restoreSource, "'cal_period'     => \$data['interface_type'] === 'SDAQ' ? null"),
    'Local JSON canonical rewrite removes SDAQ Unit and calibration fields'
);

echo "\n$checks checks, " . ($checks - $failures) . " passed, $failures failed\n";
exit($failures === 0 ? 0 : 1);
