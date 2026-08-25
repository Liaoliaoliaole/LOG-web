<?php
/* Static guard: backend file-level imports must use require_once/include_once. */

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
