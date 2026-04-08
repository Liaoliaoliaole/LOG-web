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

check_updates() {
    print_status "Running CHECK-ONLY Mode"
    web_update_needed=0
    core_update_needed=0

    if [ -d "$MORFEAS_WEB_DIR" ]; then
        cd "$MORFEAS_WEB_DIR"
        if ! git fetch origin; then
            log_line "ERROR" "Network issue or cannot reach WEB git server during check-only."
            exit 2
        fi
        local_branch=$(git rev-parse --abbrev-ref HEAD)
        log_line "INFO" "Checking branch=$local_branch for remote changes."
        if [ "$(git rev-parse HEAD)" != "$(git rev-parse origin/$local_branch)" ]; then
            web_update_needed=1
        fi
    fi

    if [ -x "$CORE_UPDATE_SCRIPT" ]; then
        set +e
        "$CORE_UPDATE_SCRIPT" --check-only
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
            *)
                log_line "ERROR" "Core check-only failed with exit_code=$core_check_ec"
                exit 1
                ;;
        esac
    else
        log_line "ERROR" "Core update script missing or not executable: $CORE_UPDATE_SCRIPT"
        exit 1
    fi

    if [ $web_update_needed -eq 1 ] || [ $core_update_needed -eq 1 ]; then
        print_status "Update Available"
        touch "$FLAG_FILE"
        log_line "INFO" "Update available (web=$web_update_needed core=$core_update_needed). flag_file=$FLAG_FILE created. exit_code=100"
        exit 100
    else
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

    if [ -d "$MORFEAS_WEB_DIR" ]; then
        cd "$MORFEAS_WEB_DIR"
        if ! git fetch origin; then
            log_line "ERROR" "Network issue or cannot reach WEB git server during update."
            exit 2
        fi
        web_branch=$(git rev-parse --abbrev-ref HEAD)
        log_line "INFO" "Update mode on branch=$web_branch."
        if [ "$(git rev-parse HEAD)" != "$(git rev-parse origin/$web_branch)" ]; then
            git pull
            web_updated=1
            log_line "INFO" "Web repository updated via git pull."
        fi
    fi

    print_status "Running post-update deployment..."
    if [ -x "$POST_DEPLOY_SCRIPT" ]; then
        log_line "INFO" "Executing post-deploy script: $POST_DEPLOY_SCRIPT"
        "$POST_DEPLOY_SCRIPT"
    else
        log_line "WARN" "post-update deploy script not found or not executable: $POST_DEPLOY_SCRIPT"
    fi

    print_status "Running CORE update..."
    if [ -x "$CORE_UPDATE_SCRIPT" ]; then
        set +e
        "$CORE_UPDATE_SCRIPT" --update
        core_update_ec=$?
        set -e
        if [ "$core_update_ec" -eq 0 ]; then
            core_updated=1
            log_line "INFO" "Core update script completed successfully."
        elif [ "$core_update_ec" -eq 2 ]; then
            log_line "ERROR" "Network issue or cannot reach CORE git server during update."
            exit 2
        else
            log_line "ERROR" "Core update script failed with exit_code=$core_update_ec"
            exit 1
        fi
    else
        log_line "ERROR" "Core update script missing or not executable: $CORE_UPDATE_SCRIPT"
        exit 1
    fi

    if [ $web_updated -eq 1 ]; then
        sudo rm -f "$FLAG_FILE"
        log_line "INFO" "flag_file=$FLAG_FILE removed after successful web update."
        print_status "Restarting Apache..."
        sleep 3
        sudo systemctl restart apache2
        log_line "INFO" "Apache restarted."
    else
        print_status "No WEB updates applied"
        log_line "INFO" "No WEB repository updates were applied."
    fi
    log_line "INFO" "Update mode completed (web_updated=$web_updated core_flow_ok=$core_updated)."
    sudo rm -f "$FLAG_FILE"
}

main() {
    print_status "Morfeas Update Script STARTED"
    log_line "INFO" "mode=${1:---update(default)} log_file=$log_file"

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
