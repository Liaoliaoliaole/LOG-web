#!/bin/bash

# ========================================
#LOG(Morfeas) Update Script
# ========================================
# This script performs system update checks and applies updates
# when triggered by the frontend web interface.
#
# It supports two modes:
#   --check-only   : Checks if updates are available without applying them.
#   (default run)  : Downloads, applies updates, and restarts services if needed.
#
# Update logs are stored for debugging and audit purposes.
# Exit Codes:
#   0   - Success / No updates available
#   1   - General failure (permissions, directory issues, etc.)
#   2   - Network error / Cannot reach update server (git server)
#   100 - Update available (when running in check-only mode)
#
# ==========================================

# Helper function for status messages
print_status() {
    echo -e "\n======================================"
    echo " $1"
    echo "======================================"
}

# ---------------------------
# GLOBAL SETTINGS IN LOG DEVICE(Pi)
# ---------------------------
MAX_LOGS=2
UPDATE_LOGS_DIR="/mnt/ramdisk/Morfeas_Loggers"
MORFEAS_WEB_DIR="/var/www/html/morfeas_web"
MORFEAS_CORE_DIR="/opt/Morfeas_project/Morfeas_core"

# Auto-clean old logs
LOGS=$(find "$UPDATE_LOGS_DIR" -maxdepth 1 -name "Morfeas_update_*.log" -printf '%T@ %p\n' | sort -nr | tail -n +$((MAX_LOGS + 1)) | cut -d' ' -f2-)
if [ -n "$LOGS" ]; then
    print_status "Cleaning up old update logs:"
    echo "$LOGS" | xargs rm -f
fi

# Setup date and log file
date=$(date +"%Y-%m-%d_%H-%M-%S")
log_file="$UPDATE_LOGS_DIR/Morfeas_update_$date.log"

# Flags
core_updated=0
web_updated=0

# ---------------------------
# Main Process
# ---------------------------
{
    print_status "Morfeas Update Script Started: $(date)"

    # ---------------------------
    # CHECK-ONLY MODE
    # ---------------------------
    if [ "$1" == "--check-only" ]; then
        print_status "Running in Check-Only Mode..."

        web_update_needed=0
        core_update_needed=0

        # -- Check Web --
        if [ -d "$MORFEAS_WEB_DIR" ]; then
            cd "$MORFEAS_WEB_DIR" || { echo "Cannot access $MORFEAS_WEB_DIR"; exit 1; }
            git fetch origin
            if [ $? -ne 0 ]; then
                echo "Error: Network issue or cannot reach update server."
                exit 2
            fi
            WEB_BRANCH=$(git rev-parse --abbrev-ref HEAD)
            WEB_LOCAL=$(git rev-parse HEAD)
            WEB_REMOTE=$(git rev-parse "origin/$WEB_BRANCH")
            echo "Web Branch: $WEB_BRANCH"
            echo "Web Local:  $WEB_LOCAL"
            echo "Web Remote: $WEB_REMOTE"
            [ "$WEB_LOCAL" != "$WEB_REMOTE" ] && web_update_needed=1
        fi

        # -- Check Core --
        if [ -d "$MORFEAS_CORE_DIR" ]; then
            cd "$MORFEAS_CORE_DIR" || { echo "Cannot access $MORFEAS_CORE_DIR"; exit 1; }
            git fetch origin
            if [ $? -ne 0 ]; then
                echo "Error: Network issue or cannot reach update server."
                exit 2
            fi
            CORE_BRANCH=$(git rev-parse --abbrev-ref HEAD)
            CORE_LOCAL=$(git rev-parse HEAD)
            CORE_REMOTE=$(git rev-parse "origin/$CORE_BRANCH")
            echo "Core Branch: $CORE_BRANCH"
            echo "Core Local:  $CORE_LOCAL"
            echo "Core Remote: $CORE_REMOTE"
            [ "$CORE_LOCAL" != "$CORE_REMOTE" ] && core_update_needed=1
        fi

        # --- Final Check Decision ---
        if [ $web_update_needed -eq 1 ] || [ $core_update_needed -eq 1 ]; then
            print_status "Update available (Core or Web)."
            exit 100
        else
            print_status "System is already up-to-date."
            exit 0
        fi
    fi

    # ---------------------------
    # FULL UPDATE PROCESS
    # ---------------------------
    print_status "Running Full Update Mode..."

    # ----- Core Update -----
    if [ -d "$MORFEAS_CORE_DIR" ]; then
        cd "$MORFEAS_CORE_DIR" || exit 1
        git fetch origin
        CORE_BRANCH=$(git rev-parse --abbrev-ref HEAD)
        CORE_LOCAL=$(git rev-parse HEAD)
        CORE_REMOTE=$(git rev-parse "origin/$CORE_BRANCH")
        if [ "$CORE_LOCAL" != "$CORE_REMOTE" ]; then
            print_status "Updating Morfeas Core..."
            git pull || exit 1
            make clean && make -j"$(nproc)" || exit 1
            sudo make install || exit 1
            core_updated=1
            echo "Core updated successfully."
        else
            echo "Core already up-to-date."
        fi
    fi

    # ----- Web Update -----
    if [ -d "$MORFEAS_WEB_DIR" ]; then
        cd "$MORFEAS_WEB_DIR" || exit 1
        git fetch origin
        WEB_BRANCH=$(git rev-parse --abbrev-ref HEAD)
        WEB_LOCAL=$(git rev-parse HEAD)
        WEB_REMOTE=$(git rev-parse "origin/$WEB_BRANCH")
        if [ "$WEB_LOCAL" != "$WEB_REMOTE" ]; then
            print_status "Updating Morfeas Web..."
            git pull || exit 1
            web_updated=1
            echo "Web updated successfully."
        else
            echo "Web already up-to-date."
        fi
    fi

    print_status "Update check completed."

    # ---------------------------
    # Restart Services If Needed
    # ---------------------------
    if [ $core_updated -eq 1 ] || [ $web_updated -eq 1 ]; then
        print_status "Scheduling service restart in 5 sec..."
        (
            sleep 5
            echo "Restarting Morfeas and Apache services..."
            sudo systemctl restart Morfeas_system
            sudo systemctl restart apache2
            echo "Services restarted."
        ) &
    else
        print_status "No updates applied. No restart needed."
        exit 0
    fi

    print_status "Morfeas Update Script Finished: $(date)"
    echo "Log file: $log_file"

} &> "$log_file"
RETVAL=$?
exit $RETVAL