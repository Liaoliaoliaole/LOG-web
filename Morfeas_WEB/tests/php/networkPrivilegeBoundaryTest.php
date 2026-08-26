<?php
/* Static guard for the restricted root helper used by network changes. */

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

$webRoot = realpath(__DIR__ . '/../../..');
if ($webRoot === false) {
    echo "FAIL: web root not found\n";
    exit(1);
}

$service = file_get_contents($webRoot . '/Morfeas_WEB/backend/services/network_service.php');
$helper = file_get_contents($webRoot . '/deploy/morfeas-network-files');
$sudoers = file_get_contents($webRoot . '/sudoers/Morfeas_web_allow');
$cron = file_get_contents($webRoot . '/cron/system_update_check.sh');

check(is_string($service) && is_string($helper) && is_string($sudoers) && is_string($cron), 'Network privilege files are readable');
if (!is_string($service) || !is_string($helper) || !is_string($sudoers) || !is_string($cron)) {
    exit(1);
}

check(str_contains($service, 'NETWORK_FILE_HELPER'), 'Network writes use the fixed root helper');

// Quote-agnostic on purpose. An earlier version of this guard matched only
// the single-quoted spelling, so reintroducing the same privileged call as
// network_exec_ok("cp ...") passed every check in this file.
$privilegedCopy = '/network_exec(?:_ok)?\s*\(\s*[\'"](?:cp|mv)\s/';
check(preg_match($privilegedCopy, $service) !== 1, 'PHP does not invoke privileged cp or mv directly, in either quote style');

// The helper must receive file contents on stdin. Handing it a path
// reintroduces a check-then-use race the caller can win, because the temp
// file belongs to the same unprivileged user that invokes the helper.
check(
    str_contains($service, "network_file_helper_command('write', \$key) . ' < ' . escapeshellarg(\$tmp)"),
    'Web passes new file contents to the helper on stdin, not as a path argument'
);
check(
    preg_match('/^\s*write_file\(\)\s*\{[^}]*\bcat\s*>\s*"\$staged"/m', $helper) === 1,
    'Helper reads the new contents from stdin'
);
check(
    preg_match('/install\b[^\n]*"\$staged"\s+"\$target"/', $helper) === 1,
    'Helper installs from its own root-only staging file'
);
check(str_contains($helper, 'backup-disable-ifupdown'), 'Helper supports only the required ifupdown transition');
check(str_contains($helper, 'networkmanager)') && str_contains($helper, 'timesyncd)'), 'Helper has an explicit fixed target map');
check(!str_contains($service, '/tmp/morfeas_network_backup_'), 'Web never creates a caller-owned /tmp network backup directory');
check(str_contains($service, '/run/morfeas_network_backup_'), 'Web passes the root-owned /run backup namespace to the helper');
check(str_contains($helper, 'ensure_backup_dir()'), 'Helper owns backup-directory creation and validation');
check(str_contains($helper, 'install -d -o root -g root -m 0700 -- "$backup_dir"'), 'Helper creates backup directories root:root 0700');
check(str_contains($helper, "stat -c '%u:%g:%a' -- \"\$backup_dir\""), 'Helper rejects a backup directory not owned root:root with mode 0700');
check(preg_match('/backup_networkmanager\(\).*?ensure_backup_dir\s+"\$backup_dir".*?cp -a/s', $helper) === 1, 'NetworkManager backup validates root-owned directory before copying');
check(preg_match('/backup_disable_ifupdown\(\).*?ensure_backup_dir\s+"\$backup_dir".*?cp -a/s', $helper) === 1, 'ifupdown backup validates root-owned directory before copying');
check(!str_contains($sudoers, '/bin/cp') && !str_contains($sudoers, '/bin/mv') && !str_contains($sudoers, '/usr/bin/make install'), 'sudoers no longer grants generic cp, mv, or make install');
check(str_contains($sudoers, '/usr/local/sbin/morfeas-network-files *'), 'sudoers grants the restricted network helper');
check(
    preg_match('/set \+e\n"\$SYSTEM_UPDATE_SCRIPT" --check-only > "\$LOG_FILE" 2>&1\nexit_code=\$\?\nset -e/', $cron) === 1,
    'Cron captures the intentional update-available exit code'
);

echo "\n{$g_checks} checks, " . ($g_checks - $g_failures) . " passed, {$g_failures} failed\n";
exit($g_failures === 0 ? 0 : 1);
