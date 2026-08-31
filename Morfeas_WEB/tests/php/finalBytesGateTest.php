<?php
/* Validate exactly the OPC_UA_Config.xml bytes that will be written. */

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

function final_bytes_temp_file(string $suffix): string
{
    $dir = sys_get_temp_dir() . '/final_bytes_' . $suffix . '_' . uniqid();
    mkdir($dir, 0700, true);
    $path = $dir . '/OPC_UA_Config.xml';
    file_put_contents($path, "<?xml version=\"1.0\"?>\n<NODESet/>\n");
    return $path;
}

$coreDir = getenv('MORFEAS_CORE_SRC_DIR');
if ($coreDir === false || trim($coreDir) === '') {
    $coreDir = realpath(__DIR__ . '/../../../../LOG-core');
}
$dtdDir = $coreDir === false ? false : realpath($coreDir . '/configuration');
check($dtdDir !== false && is_file($dtdDir . '/Morfeas.dtd'), 'Core Morfeas.dtd is available to validate final bytes (set MORFEAS_CORE_SRC_DIR or check out LOG-core as a sibling of LOG-web)');

// F-24 escaped these values after object validation. Every assertion below
// is made against the bytes actually written and the value parsed back.
$textValues = ['bar', '°C', 'm²/s', 'a>b', '"', "'", 'm<3', 'A&B', '&lt;'];
foreach ($textValues as $index => $value) {
    $xmlPath = final_bytes_temp_file((string)$index);
    iso_add_channel_body($xmlPath, [
        'iso_channel' => '_IO_' . $index,
        'interface_type' => 'IOBOX',
        'anchor' => '117440522.RX1.CH1',
        'description' => $value,
        'min' => '0',
        'max' => '10',
        'unit' => $value,
        'cal_date' => '2026/08/21',
        'cal_period' => '12',
        'alarm_high_val' => '9',
        'alarm_low_val' => '1',
        'alarm_high' => 'On',
        'alarm_low' => 'Off',
    ]);

    $bytes = file_get_contents($xmlPath);
    $parsed = simplexml_load_string($bytes);
    check($parsed !== false, "final bytes are well-formed for value " . var_export($value, true));
    check((string)$parsed->CHANNEL->DESCRIPTION === $value, "DESCRIPTION round-trips " . var_export($value, true));
    check((string)$parsed->CHANNEL->UNIT === $value, "UNIT round-trips " . var_export($value, true));
    if ($dtdDir !== false) {
        try {
            iso_validate_final_xml_bytes($bytes, $dtdDir, true);
            check(true, "Core-compatible final-byte gate accepts " . var_export($value, true));
        } catch (Throwable $e) {
            check(false, "Core-compatible final-byte gate accepts " . var_export($value, true) . ' (' . $e->getMessage() . ')');
        }
    }
}

expect_code(
    static fn() => iso_validate_final_xml_bytes('<NODESet><CHANNEL></NODESet>'),
    'invalid_document_structure',
    'malformed final bytes are rejected'
);

// A writer failure must happen before atomic replacement of the live file.
$xmlPath = final_bytes_temp_file('zero_write');
iso_add_channel_body($xmlPath, [
    'iso_channel' => '_VALID',
    'interface_type' => 'SDAQ',
    'anchor' => '796834087.CH1',
    'description' => 'valid',
    'min' => '0',
    'max' => '1',
]);
$before = hash_file('sha256', $xmlPath);
$bad = simplexml_load_file($xmlPath);
$bad->CHANNEL->INTERFACE_TYPE = 'sdaq';
expect_code(
    static fn() => iso_save_xml($bad, $xmlPath),
    'unsupported_interface',
    'writer rejects lower-case INTERFACE_TYPE in final bytes'
);
check(hash_file('sha256', $xmlPath) === $before, 'failed final-byte validation leaves the formal XML byte-for-byte unchanged');

$bad = simplexml_load_file($xmlPath);
$bad->CHANNEL->ANCHOR = '796834087.CH1 ';
expect_code(
    static fn() => iso_save_xml($bad, $xmlPath),
    'invalid_anchor',
    'writer rejects trailing ANCHOR whitespace'
);
check(hash_file('sha256', $xmlPath) === $before, 'failed ANCHOR validation also leaves the formal XML unchanged');

iso_delete_channel($xmlPath, '_VALID');
$deletedBytes = file_get_contents($xmlPath);
check(!str_contains($deletedBytes, '<ISO_CHANNEL>_VALID</ISO_CHANNEL>'), 'Delete writes a complete final document through the shared byte gate');
if ($dtdDir !== false) {
    try {
        iso_validate_final_xml_bytes($deletedBytes, $dtdDir, true);
        check(true, 'Delete output remains Core-compatible');
    } catch (Throwable $e) {
        check(false, 'Delete output remains Core-compatible (' . $e->getMessage() . ')');
    }
}

// Ctrl+Z recreates a deleted row through the normal Add path. Recreate the
// snapshot here and verify the resulting final bytes, not only the helper's
// return value.
iso_add_channel_body($xmlPath, [
    'iso_channel' => '_VALID',
    'interface_type' => 'SDAQ',
    'anchor' => '796834087.CH1',
    'description' => 'restored by undo',
    'min' => '0',
    'max' => '1',
]);
$undoBytes = file_get_contents($xmlPath);
check(str_contains($undoBytes, '<ISO_CHANNEL>_VALID</ISO_CHANNEL>'), 'Undo-style Add recreates the row in final bytes');
if ($dtdDir !== false) {
    try {
        iso_validate_final_xml_bytes($undoBytes, $dtdDir, true);
        check(true, 'Undo-style Add output remains Core-compatible');
    } catch (Throwable $e) {
        check(false, 'Undo-style Add output remains Core-compatible (' . $e->getMessage() . ')');
    }
}

$source = file_get_contents(__DIR__ . '/../../backend/core/opcua_config.php');
check(!str_contains($source, 'preg_replace_callback(\'/<UNIT>'), 'no serialized UNIT regex rewrite remains after final validation');
check(!str_contains($source, 'html_entity_decode('), 'parsed XML text is not decoded a second time');

echo "\n$checks checks, " . ($checks - $failures) . " passed, $failures failed\n";
exit($failures === 0 ? 0 : 1);
