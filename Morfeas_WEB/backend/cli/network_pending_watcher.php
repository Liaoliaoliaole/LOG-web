<?php

require __DIR__ . '/../services/network_service.php';

$pendingId = trim((string) ($argv[1] ?? ''));
$sleepSec = (int) ($argv[2] ?? 0);

if ($pendingId === '') {
    fwrite(STDERR, "pending_id argument is required\n");
    exit(2);
}

if ($sleepSec > 0) {
    sleep($sleepSec);
}

try {
    network_auto_rollback_if_expired($pendingId);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
