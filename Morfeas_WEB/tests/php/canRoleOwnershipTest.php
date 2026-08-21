<?php
/* Regression coverage for CAN transition rollback ownership. */

require __DIR__ . '/../../backend/services/can_role_service.php';

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

$dir = sys_get_temp_dir() . '/can_role_owner_' . uniqid();
mkdir($dir, 0700, true);
$path = $dir . '/Morfeas_Config.xml';

$before = '<CONFIG>before</CONFIG>';
$committed = '<CONFIG>transition</CONFIG>';
file_put_contents($path, $committed);
can_role_restore_owned_xml($path, $before, hash('sha256', $committed));
check(file_get_contents($path) === $before, 'rollback restores the baseline while the transition still owns the committed version');

file_put_contents($path, $committed);
$digest = hash('sha256', $committed);
$newer = '<CONFIG>newer UI write</CONFIG>';
file_put_contents($path, $newer);
try {
    can_role_restore_owned_xml($path, $before, $digest);
    check(false, 'rollback must reject a file changed by another operation');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), 'refusing to overwrite'), 'rollback reports lost ownership instead of overwriting newer config');
}
check(file_get_contents($path) === $newer, 'failed rollback leaves the newer configuration byte-for-byte unchanged');

try {
    backend_with_named_lock('operation:can-role-transition', static function (): void {
        backend_with_named_lock('operation:can-role-transition', static function (): void {});
    });
    check(false, 'the CAN operation lock must not be re-entered by the same request');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), 're-entrancy'), 'CAN operation lock reports an overlapping same-request transition immediately');
}

if (function_exists('pcntl_fork')) {
    $marker = $dir . '/child-holds-lock';
    $pid = pcntl_fork();
    if ($pid === 0) {
        backend_with_named_lock('operation:can-role-transition', static function () use ($marker): void {
            file_put_contents($marker, 'held');
            usleep(300000);
        });
        exit(0);
    }
    for ($i = 0; $i < 100 && !is_file($marker); $i++) {
        usleep(10000);
    }
    $started = microtime(true);
    backend_with_named_lock('operation:can-role-transition', static function (): void {});
    $elapsed = microtime(true) - $started;
    pcntl_waitpid($pid, $status);
    check($elapsed >= 0.15, 'a concurrent CAN transition waits for the operation owner instead of interleaving');
    check(pcntl_wexitstatus($status) === 0, 'the first CAN transition releases the operation lock cleanly');
}

echo "\n$checks checks, " . ($checks - $failures) . " passed, $failures failed\n";
exit($failures === 0 ? 0 : 1);
