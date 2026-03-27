#!/bin/bash
set -e

export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

# ========================================
#LOG(Morfeas) Update Script
# ========================================
# This script performs system update checks and applies updates
# when triggered by the frontend web interface.
#
# It supports two modes:
#   --check-only   : Checks if updates are available without applying them.
#   --update(default)  : Applies updates, and restarts services if needed.
#
# Features:
#   - Git-based version comparison
#   - Log rotation (max 2 logs kept)
#   - Update flag file creation/removal for frontend notification
#   - Automatic apache restart if WEB updates are applied
#
# Exit Codes:
#   0   - Success / No updates available
#   1   - General failure (permissions, directory issues, etc.)
#   2   - Network error / Cannot reach git server
#   100 - Update available (when running in check-only mode)
#
# ==========================================
#
# NOTE ON SYSTEMD CONFIGURATION:
# ------------------------------------------
# For PHP or the update process to correctly access /tmp
# (for flag file creation or any temp operation), the Apache2
# systemd unit has been configured with:
#
#   sudo mkdir -p /etc/systemd/system/apache2.service.d/
#   sudo nano /etc/systemd/system/apache2.service.d/no-private-tmp.conf
#
# Content:
#   [Service]
#   PrivateTmp=false
#
# Reload systemd and restart apache
#   sudo systemctl daemon-reexec
#   sudo systemctl restart apache2
#
# This disables the PrivateTmp sandboxing feature, allowing Apache2
# and the update script to share the real /tmp directory.
# ------------------------------------------
#
# ========================================

# ========================================
# Morfeas Update Script
# ========================================
umask 0002

MAX_LOGS=2
UPDATE_LOGS_DIR="/mnt/ramdisk/Morfeas_Loggers"  
MORFEAS_WEB_DIR="/var/www/html/morfeas_web"
FLAG_DIR="/var/lib/morfeas"
FLAG_FILE="$FLAG_DIR/update_needed"
POST_DEPLOY_SCRIPT="$MORFEAS_WEB_DIR/deploy/post_update_deploy.sh"

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

    if [ $web_update_needed -eq 1 ]; then
        print_status "Update Available"
        touch "$FLAG_FILE"
        log_line "INFO" "Update available. flag_file=$FLAG_FILE created. exit_code=100"
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

    if [ $web_updated -eq 1 ]; then
        sudo rm -f "$FLAG_FILE"
        log_line "INFO" "flag_file=$FLAG_FILE removed after successful web update."
        print_status "Restarting Apache..."
        sleep 3
        sudo systemctl restart apache2
        log_line "INFO" "Apache restarted."
    else
        print_status "No updates applied"
        log_line "INFO" "No repository updates were applied."
    fi
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
