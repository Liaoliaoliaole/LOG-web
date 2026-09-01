<?php

require_once __DIR__ . '/../../backend/core/paths.php';

$g_checks = 0;
$g_failures = 0;

function check(bool $condition, string $message): void
{
    global $g_checks, $g_failures;
    $g_checks++;
    if ($condition) {
        echo "PASS: $message\n";
        return;
    }
    $g_failures++;
    echo "FAIL: $message\n";
}

function make_core_candidate(string $root, string $name): string
{
    $candidate = $root . '/' . $name;
    mkdir($candidate . '/configuration', 0700, true);
    touch($candidate . '/configuration/Morfeas.dtd');
    return $candidate;
}

$testRoot = sys_get_temp_dir() . '/morfeas_core_path_' . bin2hex(random_bytes(6));
mkdir($testRoot, 0700, true);
register_shutdown_function(static function () use ($testRoot): void {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($testRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isLink() || $entry->isFile()) {
            @unlink($entry->getPathname());
        } else {
            @rmdir($entry->getPathname());
        }
    }
    @rmdir($testRoot);
});

$emptyRoot = $testRoot . '/empty';
mkdir($emptyRoot);
check(backend_core_src_dir_in($emptyRoot) === null, 'zero Core candidates is unresolved');

$singleRoot = $testRoot . '/single';
mkdir($singleRoot);
$singleCore = make_core_candidate($singleRoot, 'any-core-name');
check(
    backend_core_src_dir_in($singleRoot) === realpath($singleCore),
    'one Core candidate resolves to its canonical path'
);

$linkedRoot = $testRoot . '/linked';
mkdir($linkedRoot);
$linkedCore = make_core_candidate($linkedRoot, 'real-core');
symlink($linkedCore, $linkedRoot . '/core-link');
check(
    backend_core_src_dir_in($linkedRoot) === realpath($linkedCore),
    'two names for the same Core are deduplicated by realpath'
);

$ambiguousRoot = $testRoot . '/ambiguous';
mkdir($ambiguousRoot);
make_core_candidate($ambiguousRoot, 'core-a');
make_core_candidate($ambiguousRoot, 'core-b');
check(backend_core_src_dir_in($ambiguousRoot) === null, 'two distinct Core candidates are unresolved');

$previousOverride = getenv('MORFEAS_CORE_SRC_DIR');
$overrideCore = make_core_candidate($testRoot, 'override-core');
putenv('MORFEAS_CORE_SRC_DIR=' . $overrideCore);
check(backend_core_src_dir() === realpath($overrideCore), 'explicit Core override wins');

putenv('MORFEAS_CORE_SRC_DIR=' . $testRoot . '/missing-override');
check(backend_core_src_dir() === null, 'an invalid explicit override does not fall back');

if ($previousOverride === false) {
    putenv('MORFEAS_CORE_SRC_DIR');
} else {
    putenv('MORFEAS_CORE_SRC_DIR=' . $previousOverride);
}

echo "\n$g_checks checks, " . ($g_checks - $g_failures) . " passed, $g_failures failed\n";
exit($g_failures === 0 ? 0 : 1);
