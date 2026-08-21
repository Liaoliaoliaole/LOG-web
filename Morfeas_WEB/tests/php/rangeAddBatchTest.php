<?php
/*
 * tests/php/rangeAddBatchTest.php
 *
 * Regression test for channel_add_sdaq_range_from_pool() (SDAQ Multilinking
 * Range Add), which replaced addCh.js's previous "loop calling single Add
 * per record" pattern. Per the fix plan section 5.5, a batch must be
 * validated and written as a single atomic all-or-nothing operation inside
 * one XML lock: any single item being unavailable, invalid, or duplicated
 * (against the file or against another item in the same batch) must reject
 * the whole batch with zero writes -- never CH1 written while CH2 fails.
 *
 * Run: php tests/php/rangeAddBatchTest.php   (from Morfeas_WEB/)
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
    // A TC16-shaped device (16 channels) with 8 registered channels detected
    // in this reading -- enough headroom that each scenario below (happy
    // path, unavailable target, batch-internal duplicate, ISO_CHANNEL
    // collision, existing-file collision) can use its own fresh channel(s)
    // instead of accidentally re-consuming one another's.
    $meas = [];
    foreach (range(1, 8) as $ch) {
        $meas[] = [
            'Channel' => $ch,
            'CNT' => 10,
            'Channel_Status' => ['Channel_status_val' => 0, 'No_Sensor' => false, 'Over_Range' => false, 'Out_of_Range' => false],
            'Unit' => 'C',
            'Last_Meas' => 20.0 + $ch,
        ];
    }
    $fixture = [
        'CANBus_interface' => 'CAN1',
        'SDAQs_data' => [[
            'Address' => 9,
            'SDAQ_type' => 'SDAQ-TC16',
            'Serial_number' => 555000111,
            'SDAQ_Status' => ['Registration_status' => 'Done'],
            'SDAQ_info' => ['Number_of_channels' => 16],
            'Meas' => $meas,
        ]],
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

function written_channels(string $xmlPath): array
{
    $xml = simplexml_load_file($xmlPath);
    $out = [];
    foreach ($xml->CHANNEL as $ch) {
        $out[(string)$ch->ISO_CHANNEL] = (string)$ch->ANCHOR;
    }
    return $out;
}

function batch_item(string $anchor, string $iso): array
{
    return ['anchor' => $anchor, 'iso_channel' => $iso, 'description' => 'd', 'min' => '0', 'max' => '1'];
}

$dir = make_tmp_dir('range_add_test');
$sdaqJson = write_sdaq_fixture($dir);
$xmlPath = write_empty_opcua_xml($dir);

// SDAQ runtime Unit must not enter a batch payload. Reject the complete
// request before the first XML write.
$unitBatch = [batch_item('555000111.CH1', '_Unit_A'), batch_item('555000111.CH2', '_Unit_B')];
$unitBatch[1]['unit'] = 'C';
$beforeUnitHash = sha1_file($xmlPath);
try {
    channel_add_sdaq_range_from_pool($xmlPath, $unitBatch, [$sdaqJson], [], [], [], []);
    check(false, 'Range Add rejects any item carrying SDAQ Unit');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'sdaq_unit_not_allowed', 'Range Add rejects SDAQ Unit with sdaq_unit_not_allowed (got ' . $e->apiCode() . ')');
}
check(sha1_file($xmlPath) === $beforeUnitHash, 'SDAQ Unit contract failure leaves the range XML byte-for-byte unchanged');

// --- 1) Happy path: 3 valid channels, one atomic batch, all persisted with
//        canonical serial anchors. ---
channel_add_sdaq_range_from_pool(
    $xmlPath,
    [
        batch_item('555000111.CH1', '_Range_A'),
        batch_item('555000111.CH2', '_Range_B'),
        batch_item('555000111.CH3', '_Range_C'),
    ],
    [$sdaqJson], [], [], [], []
);
$written = written_channels($xmlPath);
check(count($written) === 3, 'Happy-path batch persists exactly 3 channels (got ' . count($written) . ')');
check(($written['_Range_A'] ?? null) === '555000111.CH1', 'Batch item 1 persisted with canonical anchor (got ' . var_export($written['_Range_A'] ?? null, true) . ')');
check(($written['_Range_B'] ?? null) === '555000111.CH2', 'Batch item 2 persisted with canonical anchor (got ' . var_export($written['_Range_B'] ?? null, true) . ')');
check(($written['_Range_C'] ?? null) === '555000111.CH3', 'Batch item 3 persisted with canonical anchor (got ' . var_export($written['_Range_C'] ?? null, true) . ')');

// --- 2) Partial-failure batch: item 1 and 2 are valid, item 3 targets an
//        already-linked channel (CH1, just linked above). The ENTIRE batch
//        must be rejected and the file must be byte-for-byte unchanged --
//        this is the exact "CH1 succeeds, CH2 fails" class the old
//        for-loop-of-single-POSTs pattern could produce, now closed. ---
$beforeHash = sha1_file($xmlPath);
try {
    channel_add_sdaq_range_from_pool(
        $xmlPath,
        [
            batch_item('555000111.rx-does-not-exist', '_Range_D'), // fabricated, never in pool
            batch_item('555000111.CH1', '_Range_E'), // already linked from step 1
        ],
        [$sdaqJson], [], [], [], []
    );
    check(false, 'A batch with any unavailable item must throw');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'candidate_not_available', 'Batch with a fabricated item rejects with candidate_not_available (got ' . $e->apiCode() . ')');
}
check(sha1_file($xmlPath) === $beforeHash, 'XML file is byte-for-byte unchanged after a partial-failure batch (no partial write)');
check(!isset(written_channels($xmlPath)['_Range_D']) && !isset(written_channels($xmlPath)['_Range_E']), 'Neither batch item from the rejected batch was written');

// --- 3) Batch-internal duplicate: same candidate requested twice in one
//        batch (channel 4, still unlinked at this point). Must reject the
//        whole batch, not silently dedupe or write one and conflict-reject
//        the other. ---
$beforeHash2 = sha1_file($xmlPath);
try {
    channel_add_sdaq_range_from_pool(
        $xmlPath,
        [
            batch_item('555000111.CH4', '_Dup_A'),
            batch_item('555000111.ch4', '_Dup_B'), // same physical channel, different case in the client-submitted locator text
        ],
        [$sdaqJson], [], [], [], []
    );
    check(false, 'A batch requesting the same candidate twice must throw');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'duplicate_source', 'Batch-internal duplicate candidate rejects with duplicate_source (got ' . $e->apiCode() . ')');
}
check(sha1_file($xmlPath) === $beforeHash2, 'XML file is unchanged after a batch-internal duplicate-candidate rejection');
check(!isset(written_channels($xmlPath)['_Dup_A']), 'Neither side of the batch-internal duplicate was written');

// --- 4) Batch-internal ISO_CHANNEL collision: two items with the same
//        iso_channel but different (both otherwise-valid, still-unlinked)
//        candidates -- channels 5 and 6. ---
try {
    channel_add_sdaq_range_from_pool(
        $xmlPath,
        [
            batch_item('555000111.CH5', '_Same_ISO'),
            batch_item('555000111.CH6', '_Same_ISO'),
        ],
        [$sdaqJson], [], [], [], []
    );
    check(false, 'A batch requesting the same ISO_CHANNEL twice must throw');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'channel_conflict', 'Batch-internal duplicate ISO_CHANNEL rejects with channel_conflict (got ' . $e->apiCode() . ')');
}
check(!isset(written_channels($xmlPath)['_Same_ISO']), 'Neither side of the batch-internal ISO_CHANNEL collision was written');

// --- 5) Conflict against the existing file: one fresh, otherwise-valid item
//        (channel 5, still unlinked -- item 4's batch was fully rejected
//        above) plus one item whose ISO_CHANNEL already exists in the file
//        (_Range_A, from step 1). Whole batch rejected, the valid item is
//        not written either. ---
$beforeHash3 = sha1_file($xmlPath);
try {
    channel_add_sdaq_range_from_pool(
        $xmlPath,
        [
            batch_item('555000111.CH5', '_Range_F'),
            batch_item('555000111.CH6', '_Range_A'), // ISO_CHANNEL _Range_A already exists from step 1
        ],
        [$sdaqJson], [], [], [], []
    );
    check(false, 'A batch item colliding with an existing ISO_CHANNEL must throw for the whole batch');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'channel_conflict', 'Batch item colliding with existing ISO_CHANNEL rejects with channel_conflict (got ' . $e->apiCode() . ')');
}
check(sha1_file($xmlPath) === $beforeHash3, 'XML file is unchanged after rejecting a batch with an existing-file collision');
check(!isset(written_channels($xmlPath)['_Range_F']), 'The otherwise-valid batch item was not written either (all-or-nothing)');

// --- 6) Empty batch must be a clean 400, not a silent no-op or crash. ---
try {
    channel_add_sdaq_range_from_pool($xmlPath, [], [$sdaqJson], [], [], [], []);
    check(false, 'An empty batch must throw');
} catch (ChannelConfigException $e) {
    check($e->apiCode() === 'missing_field', 'Empty batch rejects with missing_field (got ' . $e->apiCode() . ')');
}

// --- 7) Final sanity: exactly the 3 happy-path channels exist, nothing
//        extra leaked in from any of the rejected batches above. ---
$final = written_channels($xmlPath);
check(count($final) === 3, 'After all rejected batches, still exactly 3 channels in the file (got ' . count($final) . ')');

echo "\n{$g_checks} checks, " . ($g_checks - $g_failures) . " passed, {$g_failures} failed\n";
exit($g_failures === 0 ? 0 : 1);
