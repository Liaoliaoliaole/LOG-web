#!/bin/bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

API_ROOT="${API_ROOT:-http://127.0.0.1/backend}"
BUS="${BUS:-}"
ADDR="${ADDR:-}"
CH="${CH:-}"
TTL_CHECK=0

for arg in "$@"; do
    case "$arg" in
        --ttl-check)
            TTL_CHECK=1
            ;;
        *)
            echo "[pi-multi-user-smoke] Unknown argument: $arg" >&2
            exit 2
            ;;
    esac
done

SESSION_A="pi-smoke-a-$(date +%s)-$$"
SESSION_B="pi-smoke-b-$(date +%s)-$$"
LOCK_HELD=0
LAST_BODY=""
LAST_STATUS=""

log() {
    echo "[pi-multi-user-smoke] $*"
}

fail() {
    echo "[pi-multi-user-smoke] ERROR: $*" >&2
    exit 1
}

cleanup() {
    if [ "$LOCK_HELD" -eq 1 ] && [ -n "$BUS" ] && [ -n "$ADDR" ]; then
        curl -sS \
            -X POST \
            -H "Accept: application/json" \
            -H "Content-Type: application/json" \
            -H "X-Morfeas-Session: $SESSION_A" \
            --data "{\"action\":\"edit_end\",\"bus\":\"$BUS\",\"addr\":$ADDR,\"tool\":\"calibration\"}" \
            "$API_ROOT/api_calibration.php" >/dev/null 2>&1 || true
    fi
}

trap cleanup EXIT

api_call() {
    local method="$1"
    local url="$2"
    local session="$3"
    local body="${4:-}"

    LAST_BODY="$(mktemp /tmp/pi_multi_user_smoke.XXXXXX)"
    if [ -n "$body" ]; then
        LAST_STATUS="$(
            curl -sS -o "$LAST_BODY" -w '%{http_code}' \
                -X "$method" \
                -H "Accept: application/json" \
                -H "Content-Type: application/json" \
                -H "X-Morfeas-Session: $session" \
                --data "$body" \
                "$url"
        )"
    else
        LAST_STATUS="$(
            curl -sS -o "$LAST_BODY" -w '%{http_code}' \
                -X "$method" \
                -H "Accept: application/json" \
                -H "X-Morfeas-Session: $session" \
                "$url"
        )"
    fi
}

json_path() {
    local path="$1"
    python3 - "$LAST_BODY" "$path" <<'PY'
import json
import sys

body_path, path = sys.argv[1], sys.argv[2]
with open(body_path, 'r', encoding='utf-8') as fh:
    data = json.load(fh)

value = data
for part in path.split('.'):
    if isinstance(value, dict):
        value = value.get(part)
    else:
        value = None
        break

if isinstance(value, bool):
    print('true' if value else 'false')
elif value is None:
    print('')
else:
    print(value)
PY
}

assert_status() {
    local expected="$1"
    [ "$LAST_STATUS" = "$expected" ] || fail "Expected HTTP $expected, got $LAST_STATUS. Body: $(cat "$LAST_BODY")"
}

assert_json_equals() {
    local path="$1"
    local expected="$2"
    local actual
    actual="$(json_path "$path")"
    [ "$actual" = "$expected" ] || fail "Expected JSON $path=$expected, got '$actual'. Body: $(cat "$LAST_BODY")"
}

assert_body_contains() {
    local needle="$1"
    grep -Fq "$needle" "$LAST_BODY" || fail "Expected response body to contain '$needle'. Body: $(cat "$LAST_BODY")"
}

require_device_context() {
    [ -n "$BUS" ] || fail "BUS is required"
    [ -n "$ADDR" ] || fail "ADDR is required"
    [ -n "$CH" ] || fail "CH is required"
}

run_lint_checks() {
    log "Running PHP syntax checks"
    php -l "$REPO_ROOT/Morfeas_WEB/backend/core/concurrency.php" >/dev/null
    php -l "$REPO_ROOT/Morfeas_WEB/backend/core/session_registry.php" >/dev/null
    php -l "$REPO_ROOT/Morfeas_WEB/backend/core/request.php" >/dev/null
    php -l "$REPO_ROOT/Morfeas_WEB/backend/core/opcua_config.php" >/dev/null
    php -l "$REPO_ROOT/Morfeas_WEB/backend/repositories/log_config_repository.php" >/dev/null
    php -l "$REPO_ROOT/Morfeas_WEB/backend/api_calibration.php" >/dev/null
    php -l "$REPO_ROOT/Morfeas_WEB/backend/api_ftp_backup.php" >/dev/null
    php -l "$REPO_ROOT/Morfeas_WEB/backend/api_system_update.php" >/dev/null
    php -l "$REPO_ROOT/Morfeas_WEB/backend/api_system_power.php" >/dev/null

    if command -v node >/dev/null 2>&1; then
        log "Running JS parse checks"
        node -e "const fs=require('fs'); ['${REPO_ROOT}/Morfeas_WEB/assets/config.js','${REPO_ROOT}/Morfeas_WEB/linker-table/calibration.js','${REPO_ROOT}/Morfeas_WEB/linker-table/sdaq_scale.js'].forEach((file)=>new Function(fs.readFileSync(file,'utf8')))"
    else
        log "Node not installed; skipping JS parse checks"
    fi
}

run_device_lock_checks() {
    require_device_context

    log "Checking initial edit_status"
    api_call GET "$API_ROOT/api_calibration.php?action=edit_status&bus=$BUS&addr=$ADDR" "$SESSION_A"
    assert_status 200
    assert_json_equals "ok" "true"

    log "Verifying calibration_save is rejected without a lock"
    api_call POST "$API_ROOT/api_calibration.php" "$SESSION_B" \
        "{\"action\":\"calibration_save\",\"bus\":\"$BUS\",\"addr\":$ADDR,\"xmlContent\":\"<SDAQ/>\"}"
    assert_status 409
    assert_body_contains "Start editing before saving calibration or scale changes"

    log "Verifying scale save is rejected without a lock"
    api_call POST "$API_ROOT/api_calibration.php" "$SESSION_B" \
        "{\"action\":\"scale\",\"bus\":\"$BUS\",\"addr\":$ADDR,\"ch\":$CH,\"rawLow\":0,\"rawHigh\":1,\"engLow\":0,\"engHigh\":1,\"engUnit\":\"A\"}"
    assert_status 409
    assert_body_contains "Start editing before saving calibration or scale changes"

    log "Acquiring edit lock with session A"
    api_call POST "$API_ROOT/api_calibration.php" "$SESSION_A" \
        "{\"action\":\"edit_start\",\"bus\":\"$BUS\",\"addr\":$ADDR,\"tool\":\"calibration\"}"
    assert_status 200
    assert_json_equals "ok" "true"
    LOCK_HELD=1

    log "Verifying lock ownership for session A"
    api_call GET "$API_ROOT/api_calibration.php?action=edit_status&bus=$BUS&addr=$ADDR" "$SESSION_A"
    assert_status 200
    assert_json_equals "data.locked" "true"
    assert_json_equals "data.lock.owned_by_current_session" "true"

    log "Verifying session B cannot acquire the same lock"
    api_call POST "$API_ROOT/api_calibration.php" "$SESSION_B" \
        "{\"action\":\"edit_start\",\"bus\":\"$BUS\",\"addr\":$ADDR,\"tool\":\"scale\"}"
    assert_status 409
    assert_body_contains "another session"

    log "Verifying session B cannot renew the same lock"
    api_call POST "$API_ROOT/api_calibration.php" "$SESSION_B" \
        "{\"action\":\"edit_renew\",\"bus\":\"$BUS\",\"addr\":$ADDR,\"tool\":\"scale\"}"
    assert_status 409

    log "Verifying session A can renew the lock"
    api_call POST "$API_ROOT/api_calibration.php" "$SESSION_A" \
        "{\"action\":\"edit_renew\",\"bus\":\"$BUS\",\"addr\":$ADDR,\"tool\":\"calibration\"}"
    assert_status 200
    assert_json_equals "ok" "true"

    log "Verifying restore is blocked while the edit lock is active"
    api_call POST "$API_ROOT/api_ftp_backup.php" "$SESSION_B" \
        "{\"action\":\"restore\",\"file\":\"__pi_smoke_fake_backup__.zip\"}"
    assert_status 409
    assert_body_contains "Restore unavailable"

    if [ "$TTL_CHECK" -eq 1 ]; then
        log "Waiting for edit-lock TTL expiry"
        sleep 35
        LOCK_HELD=0

        log "Verifying session B can acquire lock after TTL expiry"
        api_call POST "$API_ROOT/api_calibration.php" "$SESSION_B" \
            "{\"action\":\"edit_start\",\"bus\":\"$BUS\",\"addr\":$ADDR,\"tool\":\"calibration\"}"
        assert_status 200
        assert_json_equals "ok" "true"

        log "Releasing session B lock after TTL test"
        api_call POST "$API_ROOT/api_calibration.php" "$SESSION_B" \
            "{\"action\":\"edit_end\",\"bus\":\"$BUS\",\"addr\":$ADDR,\"tool\":\"calibration\"}"
        assert_status 200
    else
        log "Releasing edit lock with session A"
        api_call POST "$API_ROOT/api_calibration.php" "$SESSION_A" \
            "{\"action\":\"edit_end\",\"bus\":\"$BUS\",\"addr\":$ADDR,\"tool\":\"calibration\"}"
        assert_status 200
        assert_json_equals "ok" "true"
        LOCK_HELD=0
    fi

    log "Checking final unlocked status"
    api_call GET "$API_ROOT/api_calibration.php?action=edit_status&bus=$BUS&addr=$ADDR" "$SESSION_A"
    assert_status 200
    assert_json_equals "data.locked" "false"
}

main() {
    run_lint_checks
    run_device_lock_checks
    log "All PI smoke checks passed"
}

main "$@"
