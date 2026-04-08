#!/bin/bash
set -euo pipefail

export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

LOCK_FILE="/var/lock/morfeas_core_update.lock"

CORE_CANDIDATES=(
  "/opt/Morfeas_project/Morfeas_core"
  "/home/morfeas/Morfeas_project/Morfeas_core"
  "/home/pi/Morfeas_project/Morfeas_core"
  "/home/morfeas/LOG_project/LOG-core"
  "/opt/Morfeas_project/LOG-core"
)

log_line() {
  local level="$1"
  shift
  printf '[CORE-UPDATE] [%s] %s\n' "$level" "$*"
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
    if [ -x "$dir/build_core_only.sh" ] && [ -d "$dir/.git" ]; then
      printf '%s\n' "$dir"
      return 0
    fi
  done
  return 1
}

core_ahead_behind() {
  local branch="$1"
  local -n out_ahead="$2"
  local -n out_behind="$3"
  local remote_ref="origin/$branch"
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

core_check_updates() {
  local core_root="$1"
  local branch
  local ahead=0
  local behind=0

  cd "$core_root"
  if ! git fetch origin; then
    log_line "ERROR" "cannot reach core git server"
    exit 2
  fi
  branch="$(git rev-parse --abbrev-ref HEAD)"
  if ! core_ahead_behind "$branch" ahead behind; then
    log_line "ERROR" "cannot compute ahead/behind for branch=$branch"
    exit 1
  fi

  if [ "$behind" -gt 0 ] && [ "$ahead" -eq 0 ]; then
    log_line "INFO" "core update available on branch=$branch"
    exit 100
  fi

  if [ "$behind" -eq 0 ] && [ "$ahead" -gt 0 ]; then
    log_line "WARN" "core local branch is ahead of origin (ahead=$ahead behind=0); no remote update needed"
    exit 0
  fi

  if [ "$behind" -gt 0 ] && [ "$ahead" -gt 0 ]; then
    log_line "ERROR" "core branch diverged from origin (ahead=$ahead behind=$behind); manual rebase/merge required"
    exit 3
  fi

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
  pgrep -f '[M]orfeas_SDAQ_if' >/dev/null || { log_line "ERROR" "Morfeas_SDAQ_if not running"; return 1; }
  return 0
}

core_apply_update() {
  local core_root="$1"
  local branch
  local core_updated=0
  local ahead=0
  local behind=0

  cd "$core_root"
  if ! git fetch origin; then
    log_line "ERROR" "cannot reach core git server"
    exit 2
  fi
  branch="$(git rev-parse --abbrev-ref HEAD)"
  if ! core_ahead_behind "$branch" ahead behind; then
    log_line "ERROR" "cannot compute ahead/behind for branch=$branch"
    exit 1
  fi

  if [ "$behind" -gt 0 ] && [ "$ahead" -eq 0 ]; then
    log_line "INFO" "core update detected on branch=$branch, pulling changes"
    git pull --ff-only
    core_updated=1
  elif [ "$behind" -eq 0 ] && [ "$ahead" -gt 0 ]; then
    log_line "WARN" "core local branch is ahead of origin (ahead=$ahead behind=0); skipping pull/build"
  elif [ "$behind" -gt 0 ] && [ "$ahead" -gt 0 ]; then
    log_line "ERROR" "core branch diverged from origin (ahead=$ahead behind=$behind); cannot fast-forward"
    exit 3
  else
    log_line "INFO" "core already up to date on branch=$branch (ahead=0 behind=0)"
  fi

  if [ "$core_updated" -eq 1 ]; then
    log_line "INFO" "running build_core_only.sh"
    ./build_core_only.sh
  fi

  if ! core_health_check; then
    exit 1
  fi

  log_line "INFO" "core update flow completed"
  exit 0
}

main() {
  local mode="${1:---update}"
  local core_root

  require_root

  mkdir -p "$(dirname "$LOCK_FILE")"
  exec 9>"$LOCK_FILE"
  if ! flock -n 9; then
    log_line "ERROR" "another core update is already running"
    exit 1
  fi

  if ! core_root="$(find_core_root)"; then
    log_line "ERROR" "Morfeas core repository not found"
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
