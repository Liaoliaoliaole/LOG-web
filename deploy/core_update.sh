#!/bin/bash
set -euo pipefail

export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

LOCK_FILE="/var/lock/morfeas_core_update.lock"
PROGRESS_STATE_DIR="/run/morfeas_update"
PROGRESS_FILE="$PROGRESS_STATE_DIR/update_progress.env"
CURRENT_MODE=""
PROGRESS_STATE="idle"
PROGRESS_PHASE="idle"
PROGRESS_COMPONENT="system"
PROGRESS_PERCENT="0"
WEB_STATUS="idle"
CORE_STATUS="idle"
UPDATED_AT_UNIX="0"

CORE_CANDIDATES=(
  "/opt/Morfeas_project/Morfeas_core"
)

log_line() {
  local level="$1"
  shift
  printf '[CORE-UPDATE] [%s] %s\n' "$level" "$*"
}

load_progress_state() {
  PROGRESS_STATE="idle"
  PROGRESS_PHASE="idle"
  PROGRESS_COMPONENT="system"
  PROGRESS_PERCENT="0"
  WEB_STATUS="idle"
  CORE_STATUS="idle"
  UPDATED_AT_UNIX="0"

  if [ -f "$PROGRESS_FILE" ]; then
    while IFS='=' read -r key value; do
      case "$key" in
        STATE) PROGRESS_STATE="$value" ;;
        MODE) CURRENT_MODE="${CURRENT_MODE:-$value}" ;;
        PHASE) PROGRESS_PHASE="$value" ;;
        COMPONENT) PROGRESS_COMPONENT="$value" ;;
        PERCENT) PROGRESS_PERCENT="$value" ;;
        WEB_STATUS) WEB_STATUS="$value" ;;
        CORE_STATUS) CORE_STATUS="$value" ;;
        UPDATED_AT_UNIX) UPDATED_AT_UNIX="$value" ;;
      esac
    done < "$PROGRESS_FILE"
  fi
}

write_progress_state() {
  local tmp_file
  mkdir -p "$PROGRESS_STATE_DIR"
  tmp_file="$(mktemp "$PROGRESS_STATE_DIR/update_progress.env.XXXXXX")"
  cat > "$tmp_file" <<EOF
STATE=$PROGRESS_STATE
MODE=$CURRENT_MODE
PHASE=$PROGRESS_PHASE
COMPONENT=$PROGRESS_COMPONENT
PERCENT=$PROGRESS_PERCENT
WEB_STATUS=$WEB_STATUS
CORE_STATUS=$CORE_STATUS
UPDATED_AT_UNIX=$UPDATED_AT_UNIX
EOF
  chgrp morfeas "$tmp_file" 2>/dev/null || true
  chmod 664 "$tmp_file" 2>/dev/null || true
  mv -f "$tmp_file" "$PROGRESS_FILE"
}

set_core_progress() {
  local phase="$1"
  local percent="$2"
  local core_status="$3"

  load_progress_state
  CURRENT_MODE="${CURRENT_MODE:-update}"
  PROGRESS_STATE="running"
  PROGRESS_PHASE="$phase"
  PROGRESS_COMPONENT="core"
  PROGRESS_PERCENT="$percent"
  CORE_STATUS="$core_status"
  UPDATED_AT_UNIX="$(date +%s)"
  write_progress_state
}

mark_core_failed() {
  local exit_code="$1"
  if [ "$CURRENT_MODE" = "update" ] && [ "$exit_code" -ne 0 ]; then
    load_progress_state
    PROGRESS_STATE="failed"
    if [ "$PROGRESS_PHASE" = "idle" ]; then
      PROGRESS_PHASE="core_failed"
    fi
    CORE_STATUS="failed"
    UPDATED_AT_UNIX="$(date +%s)"
    write_progress_state
  fi
}

require_root() {
  if [ "$(id -u)" -ne 0 ]; then
    log_line "ERROR" "must run as root"
    exit 1
  fi
}

find_core_root() {
  local dir
  for dir in "${CORE_CANDIDATES[@]}"; do
    if git -C "$dir" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
      printf '%s\n' "$dir"
      return 0
    fi
  done
  return 1
}

resolve_core_build_script() {
  local core_root="$1"
  local candidate

  for candidate in \
    "$core_root/build_core_only.sh" \
    "$core_root/build_core_full.sh"; do
    if [ -f "$candidate" ]; then
      printf '%s\n' "$candidate"
      return 0
    fi
  done

  return 1
}

core_ahead_behind() {
  local remote_ref="$1"
  local -n out_ahead="$2"
  local -n out_behind="$3"
  local counts

  if ! git rev-parse --verify "$remote_ref" >/dev/null 2>&1; then
    log_line "ERROR" "remote ref not found: $remote_ref"
    return 1
  fi

  counts="$(git rev-list --left-right --count "HEAD...$remote_ref")" || return 1
  read -r out_ahead out_behind <<<"$counts"
  if ! [[ "$out_ahead" =~ ^[0-9]+$ && "$out_behind" =~ ^[0-9]+$ ]]; then
    log_line "ERROR" "invalid ahead/behind output: '$counts'"
    return 1
  fi
  return 0
}

resolve_core_tracking_ref() {
  local branch
  local upstream

  branch="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || true)"
  if [ -z "$branch" ] || [ "$branch" = "HEAD" ]; then
    log_line "WARN" "core branch is detached (HEAD); skipping remote update check"
    return 1
  fi

  upstream="$(git rev-parse --abbrev-ref --symbolic-full-name '@{u}' 2>/dev/null || true)"
  if [ -n "$upstream" ]; then
    printf '%s\n' "$upstream"
    return 0
  fi

  if git rev-parse --verify "origin/$branch" >/dev/null 2>&1; then
    printf 'origin/%s\n' "$branch"
    return 0
  fi

  log_line "WARN" "no remote tracking branch for core branch=$branch; skipping remote update check"
  return 1
}

core_check_updates() {
  local core_root="$1"
  local branch
  local remote_ref
  local ahead=0
  local behind=0

  cd "$core_root"
  CURRENT_MODE="check"
  set_core_progress "core_check" "40" "checking"
  if ! git fetch origin; then
    log_line "ERROR" "cannot reach core git server"
    exit 2
  fi
  branch="$(git rev-parse --abbrev-ref HEAD)"
  if ! remote_ref="$(resolve_core_tracking_ref)"; then
    log_line "INFO" "core remote tracking unavailable; treat as up to date in check-only"
    exit 0
  fi
  if ! core_ahead_behind "$remote_ref" ahead behind; then
    log_line "ERROR" "cannot compute ahead/behind for branch=$branch"
    exit 1
  fi

  if [ "$behind" -gt 0 ] && [ "$ahead" -eq 0 ]; then
    set_core_progress "core_check_done" "45" "update_available"
    log_line "INFO" "core update available on branch=$branch"
    exit 100
  fi

  if [ "$behind" -eq 0 ] && [ "$ahead" -gt 0 ]; then
    set_core_progress "core_check_done" "45" "ahead"
    log_line "WARN" "core local branch is ahead of origin (ahead=$ahead behind=0); no remote update needed"
    exit 0
  fi

  if [ "$behind" -gt 0 ] && [ "$ahead" -gt 0 ]; then
    log_line "ERROR" "core branch diverged from origin (ahead=$ahead behind=$behind); manual rebase/merge required"
    exit 3
  fi

  set_core_progress "core_check_done" "45" "up_to_date"
  log_line "INFO" "core up to date on branch=$branch (ahead=0 behind=0)"
  exit 0
}

core_health_check() {
  if ! systemctl is-active --quiet Morfeas_system.service; then
    log_line "ERROR" "Morfeas_system.service is not active"
    return 1
  fi
  pgrep -f '[M]orfeas_daemon' >/dev/null || { log_line "ERROR" "Morfeas_daemon not running"; return 1; }
  pgrep -f '[M]orfeas_opc_ua' >/dev/null || { log_line "ERROR" "Morfeas_opc_ua not running"; return 1; }
  if ! pgrep -f '[M]orfeas_SDAQ_if' >/dev/null; then
    log_line "WARN" "Morfeas_SDAQ_if not running; this may be expected on systems without active SDAQ interfaces"
  fi
  return 0
}

core_apply_update() {
  local core_root="$1"
  local branch
  local remote_ref
  local build_script
  local core_updated=0
  local ahead=0
  local behind=0

  cd "$core_root"
  CURRENT_MODE="update"
  set_core_progress "core_check" "55" "checking"
  if ! git fetch origin; then
    log_line "ERROR" "cannot reach core git server"
    exit 2
  fi
  branch="$(git rev-parse --abbrev-ref HEAD)"
  if ! remote_ref="$(resolve_core_tracking_ref)"; then
    log_line "WARN" "core remote tracking unavailable; skipping pull/build and only running health-check"
    if ! core_health_check; then
      exit 1
    fi
    log_line "INFO" "core update flow completed (no remote tracking)"
    exit 0
  fi
  if ! core_ahead_behind "$remote_ref" ahead behind; then
    log_line "ERROR" "cannot compute ahead/behind for branch=$branch"
    exit 1
  fi

  if [ "$behind" -gt 0 ] && [ "$ahead" -eq 0 ]; then
    set_core_progress "core_pull" "65" "updating"
    log_line "INFO" "core update detected on branch=$branch, fast-forwarding from $remote_ref"
    git merge --ff-only "$remote_ref"
    core_updated=1
  elif [ "$behind" -eq 0 ] && [ "$ahead" -gt 0 ]; then
    set_core_progress "core_check_done" "60" "ahead"
    log_line "WARN" "core local branch is ahead of origin (ahead=$ahead behind=0); skipping pull/build"
  elif [ "$behind" -gt 0 ] && [ "$ahead" -gt 0 ]; then
    log_line "ERROR" "core branch diverged from origin (ahead=$ahead behind=$behind); cannot fast-forward"
    exit 3
  else
    log_line "INFO" "core already up to date on branch=$branch (ahead=0 behind=0)"
  fi

  if [ "$core_updated" -eq 1 ]; then
    if ! build_script="$(resolve_core_build_script "$core_root")"; then
      log_line "ERROR" "core build script not found in $core_root (expected build_core_only.sh or build_core_full.sh)"
      exit 1
    fi
    set_core_progress "core_build" "78" "building"
    log_line "INFO" "running $(basename "$build_script")"
    bash "$build_script"
  fi

  set_core_progress "core_health" "90" "verifying"
  if ! core_health_check; then
    exit 1
  fi

  set_core_progress "core_done" "94" "done"
  log_line "INFO" "core update flow completed"
  exit 0
}

main() {
  local mode="${1:---update}"
  local core_root

  require_root
  trap 'mark_core_failed $?' EXIT

  mkdir -p "$(dirname "$LOCK_FILE")"
  exec 9>"$LOCK_FILE"
  if ! flock -n 9; then
    log_line "ERROR" "another core update is already running"
    exit 1
  fi

  if ! core_root="$(find_core_root)"; then
    log_line "ERROR" "Morfeas core repository not found (searched: ${CORE_CANDIDATES[*]})"
    exit 1
  fi
  log_line "INFO" "core_root=$core_root mode=$mode"

  case "$mode" in
    --check-only)
      core_check_updates "$core_root"
      ;;
    --update)
      core_apply_update "$core_root"
      ;;
    *)
      log_line "ERROR" "Usage: $0 [--check-only|--update]"
      exit 1
      ;;
  esac
}

main "$@"
