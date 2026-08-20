<?php
/*
 * tests/php/includeGraphTest.php
 *
 * Guards one invariant across the whole backend: every file-level include
 * must be require_once/include_once, never plain require/include.
 *
 * This exists because of a live HTTP 500 on 2026-08-20. api_channels.php
 * loaded two services with plain `require`:
 *
 *     require __DIR__ . '/services/channel_service.php';
 *     require __DIR__ . '/services/channel_restore_service.php';
 *
 * F-16 then added `require_once channel_restore_service.php` INSIDE
 * channel_service.php (Add needs restore_check_device_handler()). Line 7
 * pulled the restore service in transitively, line 8 pulled it in again --
 * plain require does not care that it is already loaded -- and PHP fatalled
 * with "Cannot redeclare function". The whole channel API returned an
 * empty-bodied 500.
 *
 * Every unit test passed throughout, and always would have: each test file
 * requires exactly one entry point, so no test ever reproduces an
 * api_*.php's real include list. That is the gap this file closes. It is a
 * static check on purpose -- actually including an api_*.php would execute
 * its HTTP dispatch.
 *
 * Run: php tests/php/includeGraphTest.php   (from Morfeas_WEB/)
 */

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

$backend = realpath(__DIR__ . '/../../backend');
if ($backend === false) {
    echo "SKIPPED: backend/ not found\n";
    exit(0);
}

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($backend));
$offenders = [];
$scanned = 0;

foreach ($files as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $scanned++;
    $rel = substr($file->getPathname(), strlen($backend) + 1);
    foreach (file($file->getPathname()) as $i => $line) {
        // Only file-level includes, i.e. a line that STARTS with the
        // keyword. Conditional/indented includes inside functions are a
        // different pattern and out of scope for this rule.
        if (preg_match('/^(require|include)\s*[\(\s]/', $line)) {
            $offenders[] = $rel . ':' . ($i + 1) . '  ' . trim($line);
        }
    }
}

check($scanned > 0, "Scanned backend PHP files (found $scanned)");
check(
    $offenders === [],
    'Every file-level include in backend/ is require_once/include_once'
        . ($offenders === [] ? '' : ", found:\n    " . implode("\n    ", $offenders))
);

// The specific pair that caused the incident: channel_service.php requires
// the restore service for restore_check_device_handler(), and
// api_channels.php requires both. Assert the shape directly, so that if
// someone reverts either side the reason is right here.
$channelService = file_get_contents($backend . '/services/channel_service.php');
$apiChannels = file_get_contents($backend . '/api_channels.php');
check(
    str_contains($channelService, "require_once __DIR__ . '/channel_restore_service.php'"),
    'channel_service.php pulls in the restore service itself (Add reuses restore_check_device_handler(); it must not depend on the caller having loaded it)'
);
check(
    !preg_match('/^require\s/m', $apiChannels),
    'api_channels.php has no plain top-level require -- it loads both channel_service.php and channel_restore_service.php, which now overlap'
);

echo "\n{$g_checks} checks, " . ($g_checks - $g_failures) . " passed, {$g_failures} failed\n";
exit($g_failures === 0 ? 0 : 1);
