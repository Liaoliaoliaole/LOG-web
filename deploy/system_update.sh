#!/bin/bash
set -e

export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

# ========================================
# LOG(Morfeas) Update Script
# ========================================
# Canonical entrypoint for web+core update flow.

umask 0002

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

MAX_LOGS=2
UPDATE_LOGS_DIR="/mnt/ramdisk/Morfeas_Loggers"
MORFEAS_WEB_DIR="$REPO_ROOT"
FLAG_DIR="/var/lib/morfeas"
FLAG_FILE="$FLAG_DIR/update_needed"
POST_DEPLOY_SCRIPT="$MORFEAS_WEB_DIR/deploy/post_deploy.sh"
CORE_UPDATE_SCRIPT="$MORFEAS_WEB_DIR/deploy/core_update.sh"
MIN_VALID_YEAR=2020
RESTART_STATE_DIR="/run/morfeas_update"
APACHE_RESTART_MARKER="$RESTART_STATE_DIR/apache2.restart"
JOURNALD_RESTART_MARKER="$RESTART_STATE_DIR/systemd-journald.restart"
PROGRESS_FILE="$RESTART_STATE_DIR/update_progress.env"
CURRENT_MODE=""
PROGRESS_STATE="idle"
PROGRESS_PHASE="idle"
PROGRESS_COMPONENT="system"
PROGRESS_PERCENT="0"
WEB_STATUS="idle"
CORE_STATUS="idle"
UPDATED_AT_UNIX="0"

# Create state and logs dirs if needed
mkdir -p "$UPDATE_LOGS_DIR"
mkdir -p "$FLAG_DIR"

# Keep strict total count = MAX_LOGS (including the new file created below).
# So we keep at most MAX_LOGS-1 old files before creating the next one.
MAX_OLD_LOGS=$((MAX_LOGS - 1))
if [ "$MAX_OLD_LOGS" -lt 0 ]; then
    MAX_OLD_LOGS=0
fi

find "$UPDATE_LOGS_DIR" -maxdepth 1 -type f -name "LOG_update_*.log*" -printf '%T@ %p\n' | \
    sort -nr | awk -v keep="$MAX_OLD_LOGS" 'NR>keep { $1=""; sub(/^ /, ""); print }' | \
    while IFS= read -r log_file; do
        [ -n "$log_file" ] || continue
        sudo rm -f "$log_file"
    done

# Log setup
date=$(date +"%Y-%m-%d_%H-%M-%S")
log_file="$UPDATE_LOGS_DIR/LOG_update_$date.log"
touch "$log_file"
chgrp morfeas "$log_file" 2>/dev/null || true
chmod 664 "$log_file" 2>/dev/null || true

log_line() {
    local level="$1"
    shift
    local ts
    ts=$(date +"%Y-%m-%dT%H:%M:%S%z")
    printf '[%s] [UPDATE] [%s] %s\n' "$ts" "$level" "$*"
}

print_status() {
    log_line "INFO" "===== $1 ====="
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
    mkdir -p "$RESTART_STATE_DIR"
    tmp_file="$(mktemp "$RESTART_STATE_DIR/update_progress.env.XXXXXX")"
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

set_progress() {
    local state="$1"
    local phase="$2"
    local component="$3"
    local percent="$4"

    PROGRESS_STATE="$state"
    PROGRESS_PHASE="$phase"
    PROGRESS_COMPONENT="$component"
    PROGRESS_PERCENT="$percent"
    UPDATED_AT_UNIX="$(date +%s)"
    write_progress_state
}

mark_update_failed() {
    local exit_code="$1"
    if [ "$CURRENT_MODE" = "update" ] && [ "$exit_code" -ne 0 ]; then
        load_progress_state
        PROGRESS_STATE="failed"
        if [ "$PROGRESS_PHASE" = "idle" ]; then
            PROGRESS_PHASE="failed"
        fi
        UPDATED_AT_UNIX="$(date +%s)"
        write_progress_state
    fi
}

clear_restart_markers() {
    mkdir -p "$RESTART_STATE_DIR"
    rm -f "$APACHE_RESTART_MARKER" "$JOURNALD_RESTART_MARKER"
}

schedule_apache_restart() {
    log_line "INFO" "Scheduling delayed apache2 restart."
    nohup /bin/bash -lc 'sleep 2; systemctl restart apache2' >/dev/null 2>&1 &
}

apply_deferred_restarts() {
    local apache_restart_needed="$1"

    if [ -f "$JOURNALD_RESTART_MARKER" ]; then
        log_line "INFO" "Applying deferred systemd-journald restart."
        systemctl restart systemd-journald
        rm -f "$JOURNALD_RESTART_MARKER"
    fi

    if [ "$apache_restart_needed" -eq 1 ] || [ -f "$APACHE_RESTART_MARKER" ]; then
        rm -f "$APACHE_RESTART_MARKER"
        schedule_apache_restart
    fi
}

check_clock_sane() {
    local current_year
    current_year="$(date +%Y)"
    if [ "$current_year" -lt "$MIN_VALID_YEAR" ]; then
        log_line "ERROR" "System clock appears invalid (year=$current_year, expected >= $MIN_VALID_YEAR). Skipping update check. This usually means the RTC battery is dead/removed and no NTP source is reachable. Check 'timedatectl status' and 'hwclock -r'."
        exit 4
    fi
}

repo_ahead_behind() {
    local branch="$1"
    local -n out_ahead="$2"
    local -n out_behind="$3"
    local remote_ref="origin/$branch"
    local counts

    if ! git rev-parse --verify "$remote_ref" >/dev/null 2>&1; then
        log_line "ERROR" "Remote ref not found: $remote_ref"
        return 1
    fi

    counts="$(git rev-list --left-right --count "HEAD...$remote_ref")" || return 1
    read -r out_ahead out_behind <<<"$counts"
    if ! [[ "$out_ahead" =~ ^[0-9]+$ && "$out_behind" =~ ^[0-9]+$ ]]; then
        log_line "ERROR" "Invalid WEB ahead/behind output: '$counts'"
        return 1
    fi
    return 0
}

check_updates() {
    print_status "Running CHECK-ONLY Mode"
    web_update_needed=0
    core_update_needed=0
    web_ahead=0
    web_behind=0
    CURRENT_MODE="check"
    WEB_STATUS="checking"
    CORE_STATUS="checking"
    set_progress "running" "web_check" "web" "10"

    if [ -d "$MORFEAS_WEB_DIR" ]; then
        cd "$MORFEAS_WEB_DIR"
        if ! git fetch origin; then
            log_line "ERROR" "Network issue or cannot reach WEB git server during check-only."
            exit 2
        fi
        local_branch=$(git rev-parse --abbrev-ref HEAD)
        log_line "INFO" "Checking branch=$local_branch for remote changes."
        if ! repo_ahead_behind "$local_branch" web_ahead web_behind; then
            log_line "ERROR" "Cannot compute WEB ahead/behind."
            exit 1
        fi

        if [ "$web_behind" -gt 0 ] && [ "$web_ahead" -eq 0 ]; then
            web_update_needed=1
        elif [ "$web_behind" -eq 0 ] && [ "$web_ahead" -gt 0 ]; then
            log_line "WARN" "WEB local branch is ahead of origin (ahead=$web_ahead behind=0); no remote update needed."
        elif [ "$web_behind" -gt 0 ] && [ "$web_ahead" -gt 0 ]; then
            log_line "ERROR" "WEB branch diverged from origin (ahead=$web_ahead behind=$web_behind); manual rebase/merge required."
            exit 1
        fi
    fi

    if [ -f "$CORE_UPDATE_SCRIPT" ]; then
        set_progress "running" "core_check" "core" "35"
        set +e
        bash "$CORE_UPDATE_SCRIPT" --check-only
        core_check_ec=$?
        set -e

        case "$core_check_ec" in
            0)
                core_update_needed=0
                ;;
            100)
                core_update_needed=1
                ;;
            2)
                log_line "ERROR" "Network issue or cannot reach CORE git server during check-only."
                exit 2
                ;;
            3)
                log_line "ERROR" "CORE branch diverged from origin. Manual rebase/merge required."
                exit 1
                ;;
            *)
                log_line "ERROR" "Core check-only failed with exit_code=$core_check_ec"
                exit 1
                ;;
        esac
    else
        log_line "ERROR" "Core update script missing: $CORE_UPDATE_SCRIPT"
        exit 1
    fi

    if [ $web_update_needed -eq 1 ] || [ $core_update_needed -eq 1 ]; then
        WEB_STATUS=$([ "$web_update_needed" -eq 1 ] && printf 'update_available' || printf 'up_to_date')
        CORE_STATUS=$([ "$core_update_needed" -eq 1 ] && printf 'update_available' || printf 'up_to_date')
        set_progress "completed" "check_done" "system" "100"
        print_status "Update Available"
        touch "$FLAG_FILE"
        log_line "INFO" "Update available (web=$web_update_needed core=$core_update_needed). flag_file=$FLAG_FILE created. exit_code=100"
        exit 100
    else
        WEB_STATUS="up_to_date"
        CORE_STATUS="up_to_date"
        set_progress "completed" "check_done" "system" "100"
        print_status "System is UP-TO-DATE"
        sudo rm -f "$FLAG_FILE"
        log_line "INFO" "No update. flag_file=$FLAG_FILE removed if present. exit_code=0"
        exit 0
    fi
}

perform_update() {
    print_status "Running FULL UPDATE Mode"
    web_updated=0
    core_updated=0
    apache_restart_needed=0
    web_ahead=0
    web_behind=0
    CURRENT_MODE="update"
    WEB_STATUS="checking"
    CORE_STATUS="pending"

    clear_restart_markers
    set_progress "running" "update_start" "system" "2"

    if [ -d "$MORFEAS_WEB_DIR" ]; then
        cd "$MORFEAS_WEB_DIR"
        if ! git fetch origin; then
            log_line "ERROR" "Network issue or cannot reach WEB git server during update."
            exit 2
        fi
        web_branch=$(git rev-parse --abbrev-ref HEAD)
        log_line "INFO" "Update mode on branch=$web_branch."
        if ! repo_ahead_behind "$web_branch" web_ahead web_behind; then
            log_line "ERROR" "Cannot compute WEB ahead/behind."
            exit 1
        fi

        if [ "$web_behind" -gt 0 ] && [ "$web_ahead" -eq 0 ]; then
            WEB_STATUS="updating"
            set_progress "running" "web_update" "web" "18"
            git pull --ff-only
            web_updated=1
            log_line "INFO" "Web repository updated via git pull."
        elif [ "$web_behind" -eq 0 ] && [ "$web_ahead" -gt 0 ]; then
            WEB_STATUS="ahead"
            log_line "WARN" "WEB local branch is ahead of origin (ahead=$web_ahead behind=0); skipping pull."
        elif [ "$web_behind" -gt 0 ] && [ "$web_ahead" -gt 0 ]; then
            log_line "ERROR" "WEB branch diverged from origin (ahead=$web_ahead behind=$web_behind); cannot fast-forward."
            exit 1
        else
            WEB_STATUS="up_to_date"
        fi
    fi

    print_status "Running post-update deployment..."
    if [ -x "$POST_DEPLOY_SCRIPT" ]; then
        log_line "INFO" "Executing post-deploy script: $POST_DEPLOY_SCRIPT"
        WEB_STATUS="deploying"
        set_progress "running" "web_deploy" "web" "35"
        MORFEAS_DEFER_SERVICE_RESTARTS=1 "$POST_DEPLOY_SCRIPT"
    else
        log_line "WARN" "post-update deploy script not found or not executable: $POST_DEPLOY_SCRIPT"
    fi
    WEB_STATUS="done"
    CORE_STATUS="checking"
    set_progress "running" "core_check" "core" "52"

    print_status "Running CORE update..."
    if [ -f "$CORE_UPDATE_SCRIPT" ]; then
        set +e
        bash "$CORE_UPDATE_SCRIPT" --update
        core_update_ec=$?
        set -e
        if [ "$core_update_ec" -eq 0 ]; then
            core_updated=1
            log_line "INFO" "Core update script completed successfully."
        elif [ "$core_update_ec" -eq 2 ]; then
            log_line "ERROR" "Network issue or cannot reach CORE git server during update."
            exit 2
        elif [ "$core_update_ec" -eq 3 ]; then
            log_line "ERROR" "CORE branch diverged from origin; cannot fast-forward."
            exit 1
        else
            log_line "ERROR" "Core update script failed with exit_code=$core_update_ec"
            exit 1
        fi
    else
        log_line "ERROR" "Core update script missing: $CORE_UPDATE_SCRIPT"
        exit 1
    fi
    CORE_STATUS="done"
    set_progress "running" "service_finalize" "system" "92"

    if [ $web_updated -eq 1 ]; then
        sudo rm -f "$FLAG_FILE"
        log_line "INFO" "flag_file=$FLAG_FILE removed after successful web update."
        apache_restart_needed=1
    else
        print_status "No WEB updates applied"
        log_line "INFO" "No WEB repository updates were applied."
    fi
    if [ -f "$JOURNALD_RESTART_MARKER" ] || [ "$apache_restart_needed" -eq 1 ] || [ -f "$APACHE_RESTART_MARKER" ]; then
        set_progress "running" "service_restart" "system" "96"
    fi
    apply_deferred_restarts "$apache_restart_needed"
    log_line "INFO" "Update mode completed (web_updated=$web_updated core_flow_ok=$core_updated)."
    sudo rm -f "$FLAG_FILE"
    set_progress "completed" "completed" "system" "100"
}

main() {
    trap 'mark_update_failed $?' EXIT
    print_status "Morfeas Update Script STARTED"
    log_line "INFO" "mode=${1:---update(default)} log_file=$log_file"
    check_clock_sane

    case "$1" in
        --check-only)
            check_updates
            ;;
        --update|"")
            perform_update
            ;;
        *)
            log_line "ERROR" "Usage: $0 [--check-only | --update]"
            exit 1
            ;;
    esac

    print_status "Morfeas Update Script COMPLETED"
}

main "$@" &> "$log_file"
exit $?
