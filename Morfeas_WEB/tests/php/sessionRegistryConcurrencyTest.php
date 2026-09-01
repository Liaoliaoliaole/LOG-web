<?php
/*
 * backend_session_registry_acquire_lock()'s blockedByTypes/blockedByPredicate/
 * exclusive parameters exist to close two real races: Restore starting while
 * an SDAQ calibration edit is active (or vice versa), and two tabs of the
 * same browser (sharing one session id via localStorage) both acquiring the
 * "exclusive" system_action/restore lock. A plain check-then-acquire pair
 * cannot close either race -- these tests exist specifically to prove the
 * check and the acquire happen in one critical section, not just that each
 * one works in isolation.
 */

// Isolate this run's lock files from any real running Morfeas_WEB instance
// on this machine, and from that instance's own real Restore/edit locks --
// without this, running this test file against a live LOG could spuriously
// block a real Restore, be blocked by one, or race with real lock cleanup.
// backend_runtime_root_dir() reads this override before falling back to the
// hardcoded production path, so this must be set before the first call into
// session_registry.php (i.e. before the require below).
$testRuntimeRoot = sys_get_temp_dir() . '/session_registry_test_' . uniqid();
putenv('MORFEAS_WEB_RUNTIME_ROOT=' . $testRuntimeRoot);
register_shutdown_function(static function () use ($testRuntimeRoot) {
    // glob('*') does not match dotfiles (e.g. the session_registry mutex's
    // own .internal_*.lck file, created alongside the *.json lock records
    // in the same locks/ directory) -- GLOB_BRACE with an explicit {*,.*}
    // pattern is needed to actually remove everything this run created.
    foreach (['locks', 'undos'] as $subdir) {
        $dir = $testRuntimeRoot . '/' . $subdir;
        $entries = glob($dir . '/{*,.*}', GLOB_BRACE) ?: [];
        foreach ($entries as $entry) {
            if (is_file($entry)) {
                @unlink($entry);
            }
        }
        @rmdir($dir);
    }
    @rmdir($testRuntimeRoot);
});

require __DIR__ . '/../../backend/core/session_registry.php';

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

// ============================================================
// 1) Baseline: acquire/release still work with no new parameters
//    (regression guard for every other existing caller).
// ============================================================
$a = backend_session_registry_acquire_lock('t1', 'r1', 'session-AAAAAAAAAAAAAAAA', 5, 'edit', []);
check($a['acquired'] === true, '1: a plain acquire on a free resource still succeeds');
backend_session_registry_release_lock('t1', 'r1', 'session-AAAAAAAAAAAAAAAA');

$futureRecord = [
    'expires_at' => 1,
    'boot_id' => backend_session_registry_boot_id(),
    'expires_boottime' => backend_session_registry_boottime_now() + 60,
];
check(!backend_session_registry_is_expired($futureRecord), '1 regression: a current-boot future lock is not expired by a wall-clock change');
$futureRecord['boot_id'] = 'different-boot';
check(backend_session_registry_is_expired($futureRecord), '1 regression: a lock from another boot is expired conservatively');

// ============================================================
// 2) blockedByTypes: a matching active lock of a listed type blocks
//    acquisition, and nothing is written for the resource being acquired.
// ============================================================
backend_session_registry_acquire_lock('sdaq_edit', 'can0:5', 'session-EDITORAAAAAAAAA', 300, 'edit', []);

$b = backend_session_registry_acquire_lock(
    'system_action',
    'restore',
    'session-RESTORERAAAAAAA',
    300,
    'running',
    ['action' => 'restore'],
    ['channel_edit', 'device_config', 'sdaq_edit'],
    static fn(array $r): bool => (string)($r['mode'] ?? '') === 'edit'
);
check($b['acquired'] === false, '2: acquire is refused when a blockedByTypes lock is active');
check(($b['blocked_by']['resource_type'] ?? null) === 'sdaq_edit', '2: blocked_by identifies the actual conflicting lock (got ' . json_encode($b['blocked_by'] ?? null) . ')');
check(
    backend_session_registry_get_lock('system_action', 'restore') === null,
    '2: a blocked acquire writes nothing for the resource it was trying to acquire'
);

backend_session_registry_release_lock('sdaq_edit', 'can0:5', 'session-EDITORAAAAAAAAA');

// ============================================================
// 3) blockedByPredicate: a lock of a listed type that the predicate
//    rejects (e.g. mode != 'edit') does not block.
// ============================================================
backend_session_registry_acquire_lock('sdaq_edit', 'can0:6', 'session-RENEWERAAAAAAA', 300, 'not-edit-mode', []);

$c = backend_session_registry_acquire_lock(
    'system_action',
    'restore',
    'session-RESTORERBBBBBBB',
    300,
    'running',
    [],
    ['sdaq_edit'],
    static fn(array $r): bool => (string)($r['mode'] ?? '') === 'edit'
);
check($c['acquired'] === true, '3: a listed-type lock the predicate rejects does not block acquisition');
backend_session_registry_release_lock('sdaq_edit', 'can0:6', 'session-RENEWERAAAAAAA');
backend_session_registry_release_lock('system_action', 'restore', 'session-RESTORERBBBBBBB');

// ============================================================
// 4) exclusive: true -- the same session already holding the resource must
//    NOT be allowed to acquire it again (two tabs, one localStorage session
//    id). exclusive is the default-false behavior's opposite; both are
//    checked so this is a real regression guard, not just a new-behavior test.
// ============================================================
$sameSession = 'session-SAMETABATABATA';
backend_session_registry_acquire_lock('system_action', 'restore', $sameSession, 300, 'running', [], [], null, true);

$d = backend_session_registry_acquire_lock('system_action', 'restore', $sameSession, 300, 'running', [], [], null, true);
check($d['acquired'] === false, '4: exclusive=true refuses re-acquisition by the SAME session (two tabs sharing one session id)');

$e = backend_session_registry_acquire_lock('system_action', 'restore', $sameSession, 300, 'running', [], [], null, false);
check($e['acquired'] === true, '4 regression: exclusive=false (the default) still lets the same session renew its own lock, unchanged from before');

backend_session_registry_release_lock('system_action', 'restore', $sameSession);

// ============================================================
// 5) Atomicity: blockedByTypes is checked in the SAME critical section as
//    the acquire, not as a separate check-then-acquire pair. Proven the
//    same way P0's lock tests proved this elsewhere in this codebase:
//    backend_with_named_lock() throws on same-request re-entrancy, so
//    calling acquire_lock() from inside a closure that already holds the
//    session_registry lock must throw -- if blockedByTypes were checked via
//    a second, separate lock acquisition, this would either deadlock or
//    (worse) silently take a second uncoordinated lock instead.
// ============================================================
try {
    backend_session_registry_with_lock(function () {
        backend_session_registry_acquire_lock(
            'system_action',
            'restore',
            'session-REENTRANTAAAAAA',
            300,
            'running',
            [],
            ['sdaq_edit']
        );
    });
    check(false, '5: acquire_lock() must run inside the single session_registry critical section (nesting should have thrown re-entrancy)');
} catch (RuntimeException $e) {
    check(strpos($e->getMessage(), 're-entrancy') !== false, '5: acquire_lock() (with blockedByTypes) shares the single session_registry lock (' . $e->getMessage() . ')');
}

// ============================================================
// 6) The real bidirectional scenario, exactly as wired in
//    api_channels.php / api_ftp_backup.php (Restore blocked by an active
//    SDAQ edit) and api_calibration.php (SDAQ edit_start blocked by an
//    active Restore) -- both directions, using the same parameter shapes.
// ============================================================
backend_session_registry_acquire_lock('sdaq_edit', 'can1:12', 'session-CALEDITAAAAAAAA', 300, 'edit', ['tool' => 'calibration']);
$restoreBlocked = backend_session_registry_acquire_lock(
    'system_action', 'restore', 'session-RESTOREAAAAAAAA', 300, 'running', ['action' => 'restore'],
    ['channel_edit', 'device_config', 'sdaq_edit'],
    static fn(array $r): bool => (string)($r['mode'] ?? '') === 'edit',
    true
);
check($restoreBlocked['acquired'] === false, '6a: Restore is blocked while an SDAQ calibration edit is active');
backend_session_registry_release_lock('sdaq_edit', 'can1:12', 'session-CALEDITAAAAAAAA');

// The resource_id === 'restore' predicate below matches api_calibration.php's
// real edit_start call exactly: 'system_action' alone is not specific enough
// -- system_power and system_update also use that type for their own,
// unrelated locks (see test 7 below), so edit_start must not treat those as
// "a Restore is in progress".
$restoreResourceIdPredicate = static fn(array $r): bool => (string)($r['resource_id'] ?? '') === 'restore';

backend_session_registry_acquire_lock('system_action', 'restore', 'session-RESTOREBBBBBBBB', 300, 'running', ['action' => 'restore'], [], null, true);
$editBlocked = backend_session_registry_acquire_lock(
    'sdaq_edit', 'can1:12', 'session-CALEDITBBBBBBBB', 300, 'edit', ['tool' => 'calibration'],
    ['system_action'],
    $restoreResourceIdPredicate
);
check($editBlocked['acquired'] === false, '6b: SDAQ edit_start is blocked while a Restore is in progress');
backend_session_registry_release_lock('system_action', 'restore', 'session-RESTOREBBBBBBBB');

// Regression: with no conflicting lock at all, both directions still work.
$restoreOk = backend_session_registry_acquire_lock(
    'system_action', 'restore', 'session-RESTORECCCCCCCC', 300, 'running', ['action' => 'restore'],
    ['channel_edit', 'device_config', 'sdaq_edit'],
    static fn(array $r): bool => (string)($r['mode'] ?? '') === 'edit',
    true
);
check($restoreOk['acquired'] === true, '6c regression: Restore still succeeds when nothing is blocking it');
backend_session_registry_release_lock('system_action', 'restore', 'session-RESTORECCCCCCCC');

$editOk = backend_session_registry_acquire_lock(
    'sdaq_edit', 'can1:12', 'session-CALEDITCCCCCCCC', 300, 'edit', ['tool' => 'calibration'],
    ['system_action'],
    $restoreResourceIdPredicate
);
check($editOk['acquired'] === true, '6d regression: SDAQ edit_start still succeeds when no Restore is running');
backend_session_registry_release_lock('sdaq_edit', 'can1:12', 'session-CALEDITCCCCCCCC');

// ============================================================
// 7) system_power and system_update also use the 'system_action' resource
//    type for their own locks (see api_system_power.php/api_system_update.php).
//    edit_start's blockedByTypes=['system_action'] check must not mistake
//    either of those for a Restore in progress -- a reboot or an OS update
//    running has nothing to do with whether SDAQ calibration editing is safe.
// ============================================================
backend_session_registry_acquire_lock('system_action', 'system_power', 'session-POWERAAAAAAAAA', 300, 'running', []);
$editDuringPower = backend_session_registry_acquire_lock(
    'sdaq_edit', 'can1:12', 'session-CALEDITDDDDDDDD', 300, 'edit', ['tool' => 'calibration'],
    ['system_action'],
    $restoreResourceIdPredicate
);
check($editDuringPower['acquired'] === true, '7a: an active system_power lock does not block SDAQ edit_start (only system_action/restore should)');
backend_session_registry_release_lock('system_action', 'system_power', 'session-POWERAAAAAAAAA');
if ($editDuringPower['acquired']) {
    backend_session_registry_release_lock('sdaq_edit', 'can1:12', 'session-CALEDITDDDDDDDD');
}

backend_session_registry_acquire_lock('system_action', 'system_update', 'session-UPDATEAAAAAAAA', 300, 'running', []);
$editDuringUpdate = backend_session_registry_acquire_lock(
    'sdaq_edit', 'can1:12', 'session-CALEDITEEEEEEEE', 300, 'edit', ['tool' => 'calibration'],
    ['system_action'],
    $restoreResourceIdPredicate
);
check($editDuringUpdate['acquired'] === true, '7b: an active system_update lock does not block SDAQ edit_start either (got blocked_by=' . json_encode($editDuringUpdate['blocked_by'] ?? null) . ')');
backend_session_registry_release_lock('system_action', 'system_update', 'session-UPDATEAAAAAAAA');
if ($editDuringUpdate['acquired']) {
    backend_session_registry_release_lock('sdaq_edit', 'can1:12', 'session-CALEDITEEEEEEEE');
}

echo "\n{$g_checks} checks, " . ($g_checks - $g_failures) . " passed, {$g_failures} failed\n";
exit($g_failures === 0 ? 0 : 1);
