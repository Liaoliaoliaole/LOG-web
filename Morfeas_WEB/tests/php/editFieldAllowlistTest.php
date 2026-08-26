<?php
/* Plain Edit changes metadata only; identity and audit fields are rejected. */

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

function make_tmp_dir(string $prefix): string
{
    $dir = sys_get_temp_dir() . '/' . $prefix . '_' . uniqid();
    mkdir($dir, 0700, true);
    return $dir;
}

function fresh_xml(string $dir): string
{
    $xmlPath = $dir . '/OPC_UA_Config_' . uniqid() . '.xml';
    file_put_contents($xmlPath, <<<XML
<?xml version="1.0"?>
<NODESet>
  <CHANNEL>
    <ISO_CHANNEL>_TE101</ISO_CHANNEL>
    <INTERFACE_TYPE>SDAQ</INTERFACE_TYPE>
    <ANCHOR>111111111.CH1</ANCHOR>
    <DESCRIPTION>original desc</DESCRIPTION>
    <MIN>0</MIN>
    <MAX>100</MAX>
    <BUILD_DATE>1700000000</BUILD_DATE>
    <MOD_DATE>1700000000</MOD_DATE>
  </CHANNEL>
  <CHANNEL>
    <ISO_CHANNEL>_FT200</ISO_CHANNEL>
    <INTERFACE_TYPE>IOBOX</INTERFACE_TYPE>
    <ANCHOR>344441098.RX1.CH1</ANCHOR>
    <DESCRIPTION>iobox desc</DESCRIPTION>
    <MIN>0</MIN>
    <MAX>50</MAX>
    <UNIT>C</UNIT>
  </CHANNEL>
</NODESet>
XML);
    return $xmlPath;
}

function expect_rejected(string $label, array $data, string $iso = '_TE101'): void
{
    $dir = make_tmp_dir('edit_allow');
    $xmlPath = fresh_xml($dir);
    $before = file_get_contents($xmlPath);
    try {
        iso_update_channel($xmlPath, $iso, $data);
        check(false, "$label must be rejected");
    } catch (ChannelConfigException $e) {
        check($e->apiCode() === 'edit_field_not_allowed', "$label rejected with edit_field_not_allowed (got " . $e->apiCode() . ')');
    }
    check(file_get_contents($xmlPath) === $before, "$label leaves the XML byte-for-byte unchanged");
}

// Identity and audit fields are rejected.

// The original F-11 reproduction: a fabricated but syntactically valid
// SDAQ identity that was never detected on any bus.
expect_rejected('anchor rewrite to a fabricated identity (999999999.CH1)', ['anchor' => '999999999.CH1']);
expect_rejected('iso_channel rename', ['iso_channel' => '_RENAMED']);
expect_rejected('interface_type change', ['interface_type' => 'IOBOX']);
expect_rejected('build_date overwrite (audit field)', ['build_date' => '1234567890']);
// mod_date is the same class of server-owned audit field: without this a
// caller could backdate "last modified" so an identity change looks older
// than it is. Aliases are rejected too, because iso_pick_value() accepts
// all of them.
expect_rejected('mod_date overwrite (audit field)', ['mod_date' => '1234567890']);
expect_rejected('mod_date_unix alias overwrite', ['mod_date_unix' => '1234567890']);
expect_rejected('Build_date_UNIX alias overwrite', ['Build_date_UNIX' => '1234567890']);

// Mixed payload: a legitimate metadata field alongside a forbidden one must
// still be rejected as a whole -- no partial application.
expect_rejected('legitimate description + forbidden anchor in one request', [
    'description' => 'new desc',
    'anchor' => '999999999.CH1',
]);

// A present identity field is rejected even if its value is unchanged.
expect_rejected('anchor present but identical to the stored value', ['anchor' => '111111111.CH1']);

// SDAQ Unit is runtime-owned; Core never reads it from XML.
expect_rejected('unit on a SDAQ channel', ['unit' => 'HACKED']);
expect_rejected('cal_date on a SDAQ channel', ['cal_date' => '2026/08/26']);
expect_rejected('cal_period on a SDAQ channel', ['cal_period' => '12']);
expect_rejected(
    'mixed legitimate metadata plus SDAQ calibration fields',
    ['description' => 'should not persist', 'cal_date' => '2026/08/26', 'cal_period' => '12']
);

// Add has no ordinary Edit allowlist, so it must enforce the same ownership
// boundary itself. This also proves a direct POST cannot persist SDAQ XML
// calibration provenance.
$dirAdd = make_tmp_dir('sdaq_add_calibration');
$xmlPathAdd = fresh_xml($dirAdd);
$beforeAdd = file_get_contents($xmlPathAdd);
try {
    iso_add_channel_body($xmlPathAdd, [
        'iso_channel' => '_TE102',
        'interface_type' => 'SDAQ',
        'anchor' => '222222222.CH1',
        'description' => 'new SDAQ',
        'min' => '0',
        'max' => '100',
        'cal_date' => '2026/08/26',
        'cal_period' => '12',
    ]);
    check(false, 'SDAQ Add with calibration metadata must be rejected');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'sdaq_calibration_metadata_not_allowed', 'SDAQ Add rejects calibration metadata with the runtime-owned error (got ' . $e->apiCode() . ')');
}
check(file_get_contents($xmlPathAdd) === $beforeAdd, 'Rejected SDAQ Add leaves XML byte-for-byte unchanged');

// =====================================================================
// Still allowed -- guarding against an over-tight allowlist
// =====================================================================

$dir = make_tmp_dir('edit_allow_ok');
$xmlPath = fresh_xml($dir);

iso_update_channel($xmlPath, '_TE101', [
    'description' => 'edited desc',
    'min' => '5',
    'max' => '95',
    'alarm_high' => 'yes',
    'alarm_high_val' => '90',
    'alarm_low' => 'yes',
    'alarm_low_val' => '10',
]);
$xml = simplexml_load_file($xmlPath);
$sdaq = null;
foreach ($xml->CHANNEL as $ch) {
    if ((string)$ch->ISO_CHANNEL === '_TE101') { $sdaq = $ch; }
}
check($sdaq !== null, 'SDAQ channel still present after a legitimate metadata edit');
check((string)$sdaq->DESCRIPTION === 'edited desc', 'description is editable (got ' . (string)$sdaq->DESCRIPTION . ')');
check((string)$sdaq->MIN === '5', 'min is editable (got ' . (string)$sdaq->MIN . ')');
check((string)$sdaq->MAX === '95', 'max is editable (got ' . (string)$sdaq->MAX . ')');
check((string)$sdaq->ALARM_HIGH === 'yes', 'alarm_high is editable');
check((string)$sdaq->ALARM_HIGH_VAL === '90', 'alarm_high_val is editable');
check((string)$sdaq->ALARM_LOW === 'yes', 'alarm_low is editable');
check((string)$sdaq->ALARM_LOW_VAL === '10', 'alarm_low_val is editable');

// Identity must be untouched by a legitimate metadata edit.
check((string)$sdaq->ANCHOR === '111111111.CH1', 'anchor is unchanged by a metadata-only edit');
check((string)$sdaq->ISO_CHANNEL === '_TE101', 'iso_channel is unchanged by a metadata-only edit');
check((string)$sdaq->INTERFACE_TYPE === 'SDAQ', 'interface_type is unchanged by a metadata-only edit');
check((string)$sdaq->BUILD_DATE === '1700000000', 'build_date is preserved across a metadata-only edit');
// Rejecting a client-supplied mod_date must not stop the SERVER from
// maintaining it -- the audit trail still has to advance on a real edit.
check((int)$sdaq->MOD_DATE > 1700000000, 'mod_date is still advanced by the server on a legitimate edit (got ' . (string)$sdaq->MOD_DATE . ')');

// Non-SDAQ interfaces DO own their Unit in XML and must stay editable --
// this is the case an over-broad "reject unit everywhere" rule would break.
$dir2 = make_tmp_dir('edit_allow_iobox');
$xmlPath2 = fresh_xml($dir2);
iso_update_channel($xmlPath2, '_FT200', ['unit' => 'bar', 'description' => 'iobox edited']);
$xml2 = simplexml_load_file($xmlPath2);
$iobox = null;
foreach ($xml2->CHANNEL as $ch) {
    if ((string)$ch->ISO_CHANNEL === '_FT200') { $iobox = $ch; }
}
check($iobox !== null, 'IOBOX channel still present after edit');
check((string)$iobox->UNIT === 'bar', 'unit IS editable on a non-SDAQ channel (got ' . (string)$iobox->UNIT . ')');
check((string)$iobox->DESCRIPTION === 'iobox edited', 'description editable on a non-SDAQ channel');
check((string)$iobox->ANCHOR === '344441098.RX1.CH1', 'IOBOX anchor unchanged by a metadata-only edit');

iso_update_channel($xmlPath2, '_FT200', ['cal_date' => '2026/08/26', 'cal_period' => '12']);
$xml2 = simplexml_load_file($xmlPath2);
$iobox = null;
foreach ($xml2->CHANNEL as $ch) {
    if ((string)$ch->ISO_CHANNEL === '_FT200') { $iobox = $ch; }
}
check((string)$iobox->CAL_DATE === '2026/08/26', 'cal_date remains editable on a non-SDAQ channel');
check((string)$iobox->CAL_PERIOD === '12', 'cal_period remains editable on a non-SDAQ channel');

// =====================================================================
// Replace must NOT be affected -- it legitimately carries `anchor` and
// reaches the write through iso_update_channel_body(), bypassing this
// wrapper entirely. If this ever starts failing, the allowlist has been
// wired into the shared body function by mistake and Replace is broken.
// =====================================================================
$dir3 = make_tmp_dir('edit_allow_replace');
$xmlPath3 = fresh_xml($dir3);
iso_with_xml_lock($xmlPath3, function () use ($xmlPath3) {
    iso_update_channel_body($xmlPath3, '_TE101', ['anchor' => '222222222.CH1']);
});
$xml3 = simplexml_load_file($xmlPath3);
$replaced = null;
foreach ($xml3->CHANNEL as $ch) {
    if ((string)$ch->ISO_CHANNEL === '_TE101') { $replaced = $ch; }
}
check($replaced !== null && (string)$replaced->ANCHOR === '222222222.CH1', "Replace's path (iso_update_channel_body) can still write an anchor (got " . ($replaced ? (string)$replaced->ANCHOR : 'null') . ')');
check((string)$replaced->DESCRIPTION === 'original desc', 'Replace preserves description while changing only the anchor');

// Replace is allowed to carry an anchor, but must not preserve or accept
// runtime-owned SDAQ XML calibration metadata.
iso_with_xml_lock($xmlPath3, function () use ($xmlPath3) {
    iso_update_channel_body($xmlPath3, '_TE101', [
        'anchor' => '333333333.CH1',
        'cal_date' => '2026/08/26',
        'cal_period' => '12',
    ]);
});
$xml3 = simplexml_load_file($xmlPath3);
$replaced = null;
foreach ($xml3->CHANNEL as $ch) {
    if ((string)$ch->ISO_CHANNEL === '_TE101') { $replaced = $ch; }
}
check($replaced !== null && !isset($replaced->CAL_DATE) && !isset($replaced->CAL_PERIOD), 'SDAQ Replace clears runtime-owned CAL_DATE/CAL_PERIOD instead of persisting them');

echo "\n{$g_checks} checks, " . ($g_checks - $g_failures) . " passed, {$g_failures} failed\n";
exit($g_failures === 0 ? 0 : 1);
