<?php

require __DIR__ . '/../../backend/services/system_status_service.php';

$checks = 0;
$failures = 0;
function system_status_clock_check(bool $condition, string $message): void
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

$entry = system_status_build_sdaq_entry([
    'CANBus_interface' => 'can1',
    'Last_clock_step_UNIX' => 1700000000,
    'Last_clock_step_delta_sec' => -7200,
], '/tmp/logstat_SDAQs_can1.json');
$rows = array_column($entry['connections'], 'value', 'name');
system_status_clock_check(
    ($rows['SDAQnet_(can1)_last_clock_step_UNIX'] ?? null) === 1700000000,
    'SDAQ clock-correction time is exposed in System Status'
);
system_status_clock_check(
    ($rows['SDAQnet_(can1)_last_clock_step_delta_sec'] ?? null) === -7200,
    'SDAQ clock-correction delta is exposed in System Status'
);

$entry = system_status_build_sdaq_entry(['CANBus_interface' => 'can1'], '/tmp/logstat_SDAQs_can1.json');
$names = array_column($entry['connections'], 'name');
system_status_clock_check(
    !in_array('SDAQnet_(can1)_last_clock_step_UNIX', $names, true),
    'System Status omits clock-correction fields before any correction is detected'
);

echo "\n$checks checks, " . ($checks - $failures) . " passed, $failures failed\n";
exit($failures === 0 ? 0 : 1);
