<?php

require __DIR__ . '/../../backend/core/system_info.php';

$checks = 0;
$failures = 0;
function timesync_check(bool $condition, string $message): void
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

$failure = "Server: 192.168.234.1\nPoll interval: 34min 8s (min: 32s; max 34min 8s)\nPacket count: 0\n";
$parsed = parse_timesync_status_text($failure);
timesync_check($parsed['server'] === '192.168.234.1', 'parses the failure-state server');
timesync_check($parsed['poll_interval'] === '34min 8s (min: 32s; max 34min 8s)', 'parses a poll interval by label');
timesync_check($parsed['packet_count'] === 0, 'parses a zero packet count');

$synced = "Server: pool.example.org (203.0.113.4)\nPoll interval: 8min 32s\nPacket count: 7\nLeap status: normal\nStratum: 2\n";
$parsed = parse_timesync_status_text($synced);
timesync_check($parsed['server'] === 'pool.example.org (203.0.113.4)', 'parses synchronized output without relying on line count');
timesync_check($parsed['packet_count'] === 7, 'parses a non-zero packet count');

$parsed = parse_timesync_status_text("Server: example\n");
timesync_check($parsed['poll_interval'] === null && $parsed['packet_count'] === null, 'missing status fields remain unavailable');

echo "\n$checks checks, " . ($checks - $failures) . " passed, $failures failed\n";
exit($failures === 0 ? 0 : 1);
