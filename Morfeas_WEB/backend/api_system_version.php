<?php
// backend/api_system_version.php
// Returns live system version info for About -> System Version page.

header('Content-Type: application/json');

function sv_find_git_root(string $start): ?string
{
    $dir = rtrim($start, '/');
    while ($dir !== '' && $dir !== '/') {
        if (is_dir($dir . '/.git') || is_file($dir . '/.git')) {
            return $dir;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }
    return null;
}

function sv_git_cmd(string $repoRoot, string $cmd): ?string
{
    $safeRepo = escapeshellarg($repoRoot);
    $safeCmd = $cmd;
    $out = @shell_exec("git -C {$safeRepo} {$safeCmd} 2>/dev/null");
    if (!is_string($out)) {
        return null;
    }
    $out = trim($out);
    return $out === '' ? null : $out;
}

function sv_last_updated_unix(string $webRoot): int
{
    $max = @filemtime($webRoot) ?: 0;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($webRoot, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $item) {
            $name = $item->getFilename();
            if ($name === '.git') {
                continue;
            }
            if (!$item->isFile()) {
                continue;
            }
            $mtime = (int)$item->getMTime();
            if ($mtime > $max) {
                $max = $mtime;
            }
        }
    } catch (Throwable $e) {
        // Keep current max fallback.
    }
    return $max;
}

try {
    $backendDir = __DIR__;
    $webRoot = dirname($backendDir);
    $gitRoot = sv_find_git_root($webRoot);

    $branch = null;
    $commit = null;

    if ($gitRoot !== null) {
        $branch = sv_git_cmd($gitRoot, 'rev-parse --abbrev-ref HEAD');
        $commit = sv_git_cmd($gitRoot, 'rev-parse --short HEAD');
    }

    $lastUpdatedUnix = sv_last_updated_unix($webRoot);
    $lastUpdatedIso = $lastUpdatedUnix > 0 ? date('Y-m-d H:i:s', $lastUpdatedUnix) : '';

    echo json_encode([
        'ok' => true,
        'version' => [
            'branch' => $branch ?? 'unknown',
            'commit' => $commit ?? 'unknown',
            'label' => (($branch ?? 'unknown') . ' @ ' . ($commit ?? 'unknown')),
        ],
        'last_updated_unix' => $lastUpdatedUnix,
        'last_updated' => $lastUpdatedIso,
    ], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_PRETTY_PRINT);
}

