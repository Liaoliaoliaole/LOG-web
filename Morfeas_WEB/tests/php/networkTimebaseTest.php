<?php

require __DIR__ . '/../../backend/services/network_service.php';

$checks = 0;
$failures = 0;
function network_time_check(bool $condition, string $message): void
{
    global $checks, $failures;
    $checks++;
    if (!$condition) {
        $failures++;
        echo "FAIL: $message\n";
        return;
    }
    echo "PASS: $message\n";
}

$bootNow = network_boottime_now();
$bootId = network_boot_id();
$pending = ['state' => 'pending', 'boot_id' => $bootId, 'expires_boottime' => $bootNow + 60, 'expires_at' => 1];
network_time_check(!network_pending_expired($pending), 'a current-boot future deadline remains active despite a stale wall deadline');
network_time_check(network_pending_is_active($pending), 'pending state uses the boot-time deadline');

$pending['expires_boottime'] = max(1, $bootNow - 1);
network_time_check(network_pending_expired($pending), 'a passed boot-time deadline expires');

$pending['expires_boottime'] = $bootNow + 60;
$pending['boot_id'] = 'different-boot';
network_time_check(network_pending_expired($pending), 'a reboot conservatively expires a pending rollback window');

echo "\n$checks checks, " . ($checks - $failures) . " passed, $failures failed\n";
exit($failures === 0 ? 0 : 1);
