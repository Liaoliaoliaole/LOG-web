<?php
// backend/api_system_version.php
// Returns live system version info for About -> System Version page.

require __DIR__ . '/core/request.php';

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

function sv_git_info(?string $repoRoot): array
{
    if ($repoRoot === null) {
        return [
            'repo_root' => null,
            'branch' => 'unknown',
            'commit' => 'unknown',
            'label' => 'unknown @ unknown',
            'commit_unix' => 0,
            'commit_date' => 'unknown',
        ];
    }

    $branch = sv_git_cmd($repoRoot, 'rev-parse --abbrev-ref HEAD') ?? 'unknown';
    $commit = sv_git_cmd($repoRoot, 'rev-parse --short HEAD') ?? 'unknown';
    $commitUnix = (int)(sv_git_cmd($repoRoot, 'log -1 --format=%ct') ?? '0');
    $commitDate = $commitUnix > 0 ? date('Y-m-d H:i:s', $commitUnix) : 'unknown';

    return [
        'repo_root' => $repoRoot,
        'branch' => $branch,
        'commit' => $commit,
        'label' => ($branch . ' @ ' . $commit),
        'commit_unix' => $commitUnix,
        'commit_date' => $commitDate,
    ];
}

function sv_detect_core_repo(): ?string
{
    $candidates = [
        getenv('MORFEAS_CORE_INSTALL_DIR') ?: null,
        '/opt/Morfeas_project/Morfeas_core',
        '/home/morfeas/Morfeas_project/Morfeas_core',
        '/home/pi/Morfeas_project/Morfeas_core',
        '/home/morfeas/LOG_project/LOG-core',
        '/opt/Morfeas_project/LOG-core',
    ];

    foreach ($candidates as $cand) {
        if (!is_string($cand) || $cand === '') {
            continue;
        }
        if (!is_dir($cand)) {
            continue;
        }
        $root = sv_find_git_root($cand);
        if ($root !== null) {
            return $root;
        }
    }
    return null;
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
    $webGitRoot = sv_find_git_root($webRoot);
    $coreGitRoot = sv_detect_core_repo();

    $webInfo = sv_git_info($webGitRoot);
    $coreInfo = sv_git_info($coreGitRoot);

    $lastUpdatedUnix = sv_last_updated_unix($webRoot);
    $lastUpdatedIso = $lastUpdatedUnix > 0 ? date('Y-m-d H:i:s', $lastUpdatedUnix) : '';

    echo json_encode([
        'ok' => true,
        // Backward-compatible fields (existing UI).
        'version' => $webInfo,
        'last_updated_unix' => $lastUpdatedUnix,
        'last_updated' => $lastUpdatedIso,
        // Extended fields for split Web/Core display.
        'web' => array_merge($webInfo, [
            'files_last_updated_unix' => $lastUpdatedUnix,
            'files_last_updated' => $lastUpdatedIso,
        ]),
        'core' => $coreInfo,
    ], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    api_fail_response('Failed to read system version', 500, 'api_system_version', $e);
}
