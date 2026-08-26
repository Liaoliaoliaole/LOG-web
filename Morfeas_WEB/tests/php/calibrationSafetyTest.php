<?php

define('CALIBRATION_API_LIBRARY_ONLY', true);
require __DIR__ . '/../../backend/api_calibration.php';

$checks = 0;
$failures = 0;

function cal_test_check(bool $condition, string $message): void
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

function cal_test_throws(callable $operation, string $messagePart, string $message, ?int $code = null): void
{
    try {
        $operation();
        cal_test_check(false, $message . ' (unexpectedly accepted)');
    } catch (Throwable $e) {
        $matches = str_contains($e->getMessage(), $messagePart);
        if ($code !== null) {
            $matches = $matches && $e->getCode() === $code;
        }
        cal_test_check(
            $matches,
            $message . sprintf(' (got "%s", code %d)', $e->getMessage(), $e->getCode())
        );
    }
}

function cal_test_point(array $overrides = []): array
{
    return array_merge([
        'Measure' => '0',
        'Reference' => '0',
        'Offset' => '0',
        'Gain' => '1',
        'C2' => '0',
        'C3' => '0',
    ], $overrides);
}

function cal_test_xml(int $used, array $points, string $date = '2026/02/11', string $period = '1'): string
{
    $doc = new DOMDocument('1.0', 'utf-8');
    $root = $doc->appendChild($doc->createElement('SDAQ'));
    $info = $root->appendChild($doc->createElement('SDAQ_info'));
    foreach ([
        'SerialNumber' => '12345',
        'Type' => 'TC1',
        'Available_Channels' => '1',
        'Samplerate' => '1',
        'Max_num_of_cal_points' => '8',
    ] as $name => $value) {
        $info->appendChild($doc->createElement($name, $value));
    }

    $calibration = $root->appendChild($doc->createElement('Calibration_Data'));
    $channel = $calibration->appendChild($doc->createElement('CH1'));
    foreach ([
        'Calibration_date' => $date,
        'Calibration_Period' => $period,
        'Used_Points' => (string)$used,
        'Unit' => 'C',
    ] as $name => $value) {
        $channel->appendChild($doc->createElement($name, $value));
    }

    $pointsNode = $channel->appendChild($doc->createElement('Points'));
    foreach ($points as $idx => $fields) {
        $pointNode = $pointsNode->appendChild($doc->createElement('Point_' . $idx));
        foreach ($fields as $name => $value) {
            $pointNode->appendChild($doc->createElement($name, (string)$value));
        }
    }

    return (string)$doc->saveXML();
}

function cal_test_f32_bits(float $value): string
{
    return bin2hex(pack('f', $value));
}

// F-4 must be fixed in the Web formatter as well as Core. These are the real
// incident values used by the Core regression suite.
foreach ([
    1.2345678e-9,
    -3.8e25,
    200.000030517578125,
    27.6599998,
] as $value) {
    $formatted = cal_num_to_string($value);
    cal_test_check(
        cal_test_f32_bits($value) === cal_test_f32_bits((float)$formatted),
        sprintf('Web numeric formatter round-trips float32 bits for %s (got %s)', (string)$value, $formatted)
    );
}

$singlePrevious = cal_test_xml(1, [
    0 => cal_test_point(['Measure' => '10', 'Reference' => '20', 'Offset' => '-1980', 'Gain' => '200']),
]);
$singlePosted = cal_test_xml(1, [
    0 => cal_test_point(['Measure' => '11', 'Reference' => '22']),
]);
$rebuiltSingle = cal_rebuild_linear_coeffs_for_auto_mode($singlePosted, $singlePrevious);
$rebuiltDoc = new DOMDocument();
$rebuiltDoc->loadXML($rebuiltSingle);
$rebuiltXpath = new DOMXPath($rebuiltDoc);
cal_test_check(
    trim((string)$rebuiltXpath->evaluate('string(/SDAQ/Calibration_Data/CH1/Points/Point_0/Gain)')) === '200',
    'F-2: an existing genuine one-point calibration may retain its own gain'
);
cal_test_check(
    trim((string)$rebuiltXpath->evaluate('string(/SDAQ/Calibration_Data/CH1/Points/Point_0/Offset)')) === '-2178',
    'F-2: retained one-point gain recomputes the matching offset'
);
$fractionalGainPrevious = cal_test_xml(1, [
    0 => cal_test_point(['Gain' => '1.2345678']),
]);
cal_test_check(
    cal_test_f32_bits(cal_previous_gain_for_single_point($fractionalGainPrevious, 'CH1'))
        === cal_test_f32_bits(1.2345678),
    'F-2/F-4: a reused one-point gain is canonicalized to the device float32 before coefficient math'
);

$multiPrevious = cal_test_xml(5, [
    0 => cal_test_point(['Gain' => '200']),
    1 => cal_test_point(['Measure' => '1', 'Reference' => '200', 'Gain' => '400']),
    2 => cal_test_point(['Measure' => '2', 'Reference' => '600', 'Gain' => '800']),
    3 => cal_test_point(['Measure' => '3', 'Reference' => '1400', 'Gain' => '1600']),
    4 => cal_test_point(['Measure' => '4', 'Reference' => '3000', 'Gain' => '1600']),
]);
cal_test_throws(
    static fn() => cal_rebuild_linear_coeffs_for_auto_mode($singlePosted, $multiPrevious),
    'single-point',
    'F-2: reducing a multi-point table to one point is rejected instead of inheriting Point_0 gain',
    409
);

$zeroPrevious = cal_test_xml(0, []);
$rebuiltOffsetOnly = cal_rebuild_linear_coeffs_for_auto_mode($singlePosted, $zeroPrevious);
$offsetOnlyDoc = new DOMDocument();
$offsetOnlyDoc->loadXML($rebuiltOffsetOnly);
$offsetOnlyXpath = new DOMXPath($offsetOnlyDoc);
cal_test_check(
    trim((string)$offsetOnlyXpath->evaluate('string(/SDAQ/Calibration_Data/CH1/Points/Point_0/Gain)')) === '1'
        && trim((string)$offsetOnlyXpath->evaluate('string(/SDAQ/Calibration_Data/CH1/Points/Point_0/Offset)')) === '11',
    'F-2: a new 0-to-1 calibration is explicitly defined as unity-gain offset correction'
);
$zeroWithInactiveHistory = cal_test_xml(0, [
    4 => cal_test_point(['Measure' => '40', 'Gain' => '2.00052e-15']),
]);
$rebuiltOffsetWithHistory = cal_rebuild_linear_coeffs_for_auto_mode($singlePosted, $zeroWithInactiveHistory);
$historyDoc = new DOMDocument();
$historyDoc->loadXML($rebuiltOffsetWithHistory);
$historyXpath = new DOMXPath($historyDoc);
cal_test_check(
    trim((string)$historyXpath->evaluate('string(/SDAQ/Calibration_Data/CH1/Points/Point_0/Gain)')) === '1',
    'F-2/R-1: 0-to-1 unity-gain calibration does not depend on inactive physical-slot history'
);
cal_test_throws(
    static fn() => cal_rebuild_linear_coeffs_for_auto_mode($singlePosted, null),
    'no previous one-point table',
    'F-2: a missing live baseline cannot silently fall back to gain=1',
    409
);

$polynomialPrevious = cal_test_xml(1, [
    0 => cal_test_point(['Gain' => '200', 'C2' => '1e-15']),
]);
cal_test_throws(
    static fn() => cal_rebuild_linear_coeffs_for_auto_mode($singlePosted, $polynomialPrevious),
    'polynomial',
    'F-2: a one-point polynomial source is rejected instead of silently replacing its model',
    409
);
$nanPolynomialPrevious = cal_test_xml(1, [
    0 => cal_test_point(['Gain' => '200', 'C2' => '-nan']),
]);
cal_test_throws(
    static fn() => cal_rebuild_linear_coeffs_for_auto_mode($singlePosted, $nanPolynomialPrevious),
    'non-finite',
    'F-2 edge: non-finite source coefficients cannot qualify as a reusable linear one-point table',
    502
);

cal_test_throws(
    static fn() => cal_build_scale_xml_payload($multiPrevious, 1, 0, 100, 0, 10, 'C'),
    'existing calibration',
    'F-1: Scale cannot overwrite an active calibration while retaining its provenance',
    409
);

$uncalibratedScale = cal_build_scale_xml_payload($zeroPrevious, 1, 0, 100, 0, 10, 'C');
$scaleDoc = new DOMDocument();
$scaleDoc->loadXML($uncalibratedScale);
$scaleXpath = new DOMXPath($scaleDoc);
cal_test_check(
    trim((string)$scaleXpath->evaluate('string(/SDAQ/Calibration_Data/CH1/Used_Points)')) === '2',
    'F-1: Scale remains available for a channel with no active calibration'
);
cal_test_check(
    trim((string)$scaleXpath->evaluate('string(/SDAQ/Calibration_Data/CH1/Calibration_date)')) === date('Y/m/d')
        && trim((string)$scaleXpath->evaluate('string(/SDAQ/Calibration_Data/CH1/Calibration_Period)')) === '0',
    'F-1: a permitted Scale write starts new table metadata instead of copying stale date/period'
);
cal_test_throws(
    static fn() => cal_build_scale_xml_payload($zeroPrevious, 1, 0, 100, 10, 10, 'C'),
    'EngHigh must differ from EngLow',
    'F-7: Scale rejects a zero engineering output range',
    400
);
cal_test_throws(
    static fn() => cal_build_scale_xml_payload($zeroPrevious, 1, 0, 100, 1, 1.00000001, 'C'),
    'after float32 conversion',
    'F-7 edge: engineering endpoints that collapse to the same float32 are rejected',
    400
);
cal_test_throws(
    static fn() => cal_build_scale_xml_payload($zeroPrevious, 1, 1, 1.00000001, 0, 100, 'C'),
    'strictly increasing after float32 conversion',
    'F-8 edge: Scale raw endpoints that collapse to one float32 are rejected',
    400
);

$descendingPoints = cal_test_xml(2, [
    0 => cal_test_point(['Measure' => '10', 'Reference' => '0']),
    1 => cal_test_point(['Measure' => '0', 'Reference' => '100']),
]);
cal_test_throws(
    static fn() => cal_rebuild_linear_coeffs_for_auto_mode($descendingPoints, $zeroPrevious),
    'must be greater',
    'F-3: Web coefficient generation rejects descending Measure rows instead of sorting them',
    400
);
$float32Collision = cal_test_xml(2, [
    0 => cal_test_point(['Measure' => '1', 'Reference' => '0']),
    1 => cal_test_point(['Measure' => '1.00000001', 'Reference' => '100']),
]);
cal_test_throws(
    static fn() => cal_validate_calibration_xml($float32Collision, ['C']),
    'after float32 conversion',
    'F-8: distinct decimal Measure values that collapse to one float32 are rejected',
    400
);
$reverseResponse = cal_test_xml(2, [
    0 => cal_test_point(['Measure' => '0', 'Reference' => '100']),
    1 => cal_test_point(['Measure' => '10', 'Reference' => '0']),
]);
$rebuiltReverse = cal_rebuild_linear_coeffs_for_auto_mode($reverseResponse, $zeroPrevious);
cal_validate_calibration_xml($rebuiltReverse, ['C']);
cal_test_check(true, 'F-3: Reference may descend when Measure remains strictly ascending');

$postedOneWithIgnoredTail = cal_test_xml(1, [
    0 => cal_test_point(),
    4 => cal_test_point(['Offset' => '2.00052e-15']),
]);
cal_test_throws(
    static fn() => cal_validate_calibration_xml($postedOneWithIgnoredTail, ['C']),
    'Point_4 is outside Used_Points=1',
    'posted Point_N beyond Used_Points remains a structural error',
    400
);

$fivePoints = [];
for ($pointIndex = 0; $pointIndex < 5; $pointIndex++) {
    $fivePoints[$pointIndex] = cal_test_point([
        'Measure' => (string)($pointIndex * 10),
        'Reference' => (string)($pointIndex * 10),
    ]);
}
$currentFive = cal_test_xml(5, $fivePoints);
$postedTwo = cal_test_xml(2, array_slice($fivePoints, 0, 2));
$rebuiltTwo = cal_rebuild_linear_coeffs_for_auto_mode($postedTwo, $currentFive);
cal_validate_calibration_xml($rebuiltTwo, ['C']);
cal_assert_point_table_changed($rebuiltTwo, $currentFive);
cal_test_check(true, 'normal 5-to-2 recalibration is not blocked by inactive physical slots');
$postedWithInvalidMax = str_replace(
    '<Max_num_of_cal_points>8</Max_num_of_cal_points>',
    '<Max_num_of_cal_points>not-a-limit</Max_num_of_cal_points>',
    $postedTwo
);
cal_validate_calibration_xml($postedWithInvalidMax, ['C'], $zeroPrevious);
cal_test_check(true, 'W-7: validation uses the live device limit rather than posted SDAQ_info text');

$currentScaleWithResidue = cal_test_xml(0, [
    2 => cal_test_point(['Measure' => '20', 'Reference' => '20']),
]);
$scaleWithResidue = cal_build_scale_xml_payload($currentScaleWithResidue, 1, 4, 20, 0, 100, 'C');
cal_validate_calibration_xml($scaleWithResidue, ['C']);
$scaleWithResidueDoc = new DOMDocument();
$scaleWithResidueDoc->loadXML($scaleWithResidue);
$scaleWithResidueXpath = new DOMXPath($scaleWithResidueDoc);
cal_test_check(
    trim((string)$scaleWithResidueXpath->evaluate('string(/SDAQ/Calibration_Data/CH1/Used_Points)')) === '2',
    'normal 0-to-2 Scale remains available when the device has inactive historical values'
);

$postedZero = cal_test_xml(0, []);
$currentThree = cal_test_xml(3, array_slice($fivePoints, 0, 3));
cal_validate_calibration_xml($postedZero, ['C']);
cal_assert_point_table_changed($postedZero, $currentThree);
cal_test_check(true, 'clearing an active table to Used_Points=0 is not blocked by physical history');

cal_validate_calibration_metadata_xml(cal_test_xml(0, [], '2026/08/26', '255'));
cal_test_check(true, 'F-6 Web boundary: Calibration_Period=255 is accepted');
cal_test_throws(
    static fn() => cal_validate_calibration_metadata_xml(cal_test_xml(0, [], '2026/08/26', '256')),
    'between 0 and 255',
    'F-6 Web boundary: Calibration_Period=256 is rejected before Core',
    400
);

cal_test_check(
    cal_require_point_table_save_mode('auto-linear') === 'auto-linear',
    'point-table calibration save mode remains available'
);
cal_test_throws(
    static fn() => cal_require_point_table_save_mode('legacy'),
    'metadata editing is not supported',
    'independent metadata-only legacy mode is removed from the Web API',
    410
);
cal_test_throws(
    static fn() => cal_require_point_table_save_mode(''),
    'metadata editing is not supported',
    'an old client omitting mode cannot fall back to metadata-only behavior',
    410
);

$unchangedTable = cal_test_xml(1, [
    0 => cal_test_point(['Measure' => '27.6599998', 'Reference' => '27.6599998']),
]);
$dateOnlyTable = cal_test_xml(1, [
    0 => cal_test_point(['Measure' => '27.6599998', 'Reference' => '27.6599998']),
], '2026/08/26', '1');
cal_test_throws(
    static fn() => cal_assert_point_table_changed($dateOnlyTable, $unchangedTable),
    'metadata editing is not supported',
    'date-only XML relabelled as auto-linear is rejected by the backend',
    410
);

$periodOnlyTable = cal_test_xml(1, [
    0 => cal_test_point(['Measure' => '27.6599998', 'Reference' => '27.6599998']),
], '2026/02/11', '12');
cal_test_throws(
    static fn() => cal_assert_point_table_changed($periodOnlyTable, $unchangedTable),
    'metadata editing is not supported',
    'period-only XML relabelled as auto-linear is rejected by the backend',
    410
);

$formatOnlyTable = cal_test_xml(1, [
    0 => cal_test_point(['Measure' => '27.66', 'Reference' => '27.66']),
], '2026/08/26', '12');
cal_test_throws(
    static fn() => cal_assert_point_table_changed($formatOnlyTable, $unchangedTable),
    'metadata editing is not supported',
    'float32-equivalent text formatting cannot disguise a metadata-only save',
    410
);

$changedPointTable = cal_test_xml(1, [
    0 => cal_test_point(['Measure' => '27.6599998', 'Reference' => '28']),
], '2026/08/26', '12');
cal_assert_point_table_changed($changedPointTable, $unchangedTable);
cal_test_check(true, 'a real active-point change remains eligible for point-table calibration save');

$tooManyPoints = cal_test_xml(9, array_fill(0, 9, cal_test_point()));
cal_test_throws(
    static fn() => cal_validate_calibration_xml($tooManyPoints, ['C']),
    'exceeds Max_num_of_cal_points=8',
    'Used_Points one past the device maximum is rejected in Web preflight',
    400
);

$threePoints = cal_test_xml(3, array_slice($fivePoints, 0, 3));
$spoofedMax = str_replace(
    '<Max_num_of_cal_points>8</Max_num_of_cal_points>',
    '<Max_num_of_cal_points>99</Max_num_of_cal_points>',
    $threePoints
);
$liveMaxTwo = str_replace(
    '<Max_num_of_cal_points>8</Max_num_of_cal_points>',
    '<Max_num_of_cal_points>2</Max_num_of_cal_points>',
    $zeroPrevious
);
cal_test_throws(
    static fn() => cal_validate_calibration_xml($spoofedMax, ['C'], $liveMaxTwo),
    'exceeds Max_num_of_cal_points=2',
    'W-7: posted Max_num_of_cal_points cannot override the freshly read device limit',
    400
);

echo "\n$checks checks, " . ($checks - $failures) . " passed, $failures failed\n";
exit($failures === 0 ? 0 : 1);
