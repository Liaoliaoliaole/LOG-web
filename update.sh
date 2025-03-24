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
#   - Automatic service restart if updates are applied
#
# Exit Codes:
#   0   - Success / No updates available
#   1   - General failure (permissions, directory issues, etc.)
#   2   - Network error / Cannot reach git server
#   100 - Update available (when running in check-only mode)
#
# ==========================================

# ========================================
# Morfeas Update Script
# ========================================
MAX_LOGS=2
UPDATE_LOGS_DIR="/mnt/ramdisk/Morfeas_Loggers"
MORFEAS_WEB_DIR="/var/www/html/morfeas_web"
MORFEAS_CORE_DIR="/opt/Morfeas_project/Morfeas_core"
FLAG_FILE="/tmp/update_needed"

# Create logs dir if needed
mkdir -p "$UPDATE_LOGS_DIR"

# Clean old logs
find "$UPDATE_LOGS_DIR" -maxdepth 1 -name "Morfeas_update_*.log" -printf '%T@ %p\n' | \
    sort -nr | tail -n +$((MAX_LOGS + 1)) | cut -d' ' -f2- | xargs -r rm -f

# Log setup
date=$(date +"%Y-%m-%d_%H-%M-%S")
log_file="$UPDATE_LOGS_DIR/Morfeas_update_$date.log"

print_status() {
    echo -e "\n======================================"
    echo " $1"
    echo "======================================"
}

check_updates() {
    print_status "Running CHECK-ONLY Mode"
    web_update_needed=0
    core_update_needed=0

    if [ -d "$MORFEAS_WEB_DIR" ]; then
        cd "$MORFEAS_WEB_DIR"
        git fetch origin
        if [ $? -ne 0 ]; then
            echo "Error: Network issue or cannot reach WEB git server."
            exit 2
        fi
        local_branch=$(git rev-parse --abbrev-ref HEAD)
        [ "$(git rev-parse HEAD)" != "$(git rev-parse origin/$local_branch)" ] && web_update_needed=1
    fi

    if [ -d "$MORFEAS_CORE_DIR" ]; then
        cd "$MORFEAS_CORE_DIR"
        git fetch origin
        if [ $? -ne 0 ]; then
            echo "Error: Network issue or cannot reach CORE git server."
            exit 2
        fi
        core_branch=$(git rev-parse --abbrev-ref HEAD)
        [ "$(git rev-parse HEAD)" != "$(git rev-parse origin/$core_branch)" ] && core_update_needed=1
    fi

    if [ $web_update_needed -eq 1 ] || [ $core_update_needed -eq 1 ]; then
        print_status "Update Available"
        touch "$FLAG_FILE"
        exit 100
    else
        print_status "System is UP-TO-DATE"
        rm -f "$FLAG_FILE"
        exit 0
    fi
}

perform_update() {
    print_status "Running FULL UPDATE Mode"
    core_updated=0
    web_updated=0

    # Update Morfeas Core if needed
    if [ -d "$MORFEAS_CORE_DIR" ]; then
        cd "$MORFEAS_CORE_DIR"
        git fetch origin
        if [ $? -ne 0 ]; then
            echo "Error: Network issue or cannot reach CORE git server."
            exit 2
        fi
        core_branch=$(git rev-parse --abbrev-ref HEAD)
        if [ "$(git rev-parse HEAD)" != "$(git rev-parse origin/$core_branch)" ]; then
            git pull
            make clean && make -j"$(nproc)" && sudo make install
            core_updated=1
            echo "Core updated"
        fi
    fi

    if [ -d "$MORFEAS_WEB_DIR" ]; then
        cd "$MORFEAS_WEB_DIR"
        git fetch origin
        if [ $? -ne 0 ]; then
            echo "Error: Network issue or cannot reach WEB git server."
            exit 2
        fi
        web_branch=$(git rev-parse --abbrev-ref HEAD)
        if [ "$(git rev-parse HEAD)" != "$(git rev-parse origin/$web_branch)" ]; then
            git pull
            web_updated=1
            echo "Web updated"
        fi
    fi

    if [ $core_updated -eq 1 ] || [ $web_updated -eq 1 ]; then
        print_status "Restarting Services..."
        sleep 3
        sudo systemctl restart Morfeas_system
        sudo systemctl restart apache2
    else
        print_status "No updates applied"
    fi
    
    rm -f "$FLAG_FILE"
    if [ -f "$FLAG_FILE" ]; then
    echo "Warning: Failed to remove $FLAG_FILE. Check ownership and permissions:"
    ls -l "$FLAG_FILE"
else
    echo "Update flag $FLAG_FILE removed successfully."
fi
}

main() {
    print_status "Morfeas Update Script STARTED - $(date)"

    case "$1" in
        --check-only)
            check_updates
            ;;
        --update|"")
            perform_update
            ;;
        *)
            echo "Usage: $0 [--check-only | --update]"
            exit 1
            ;;
    esac

    print_status "Morfeas Update Script COMPLETED - $(date)"
}

main "$@" &> "$log_file"
exit $?
