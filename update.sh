#!/bin/bash

# ========================================
# Morfeas Update Script with Optional Check Mode
# ========================================

# Helper function for status messages
print_status() {
    echo -e "\n======================================"
    echo " $1"
    echo "======================================"
}

# Limit to last 5 update logs
MAX_LOGS=5
UPDATE_LOGS_DIR="/mnt/ramdisk/Morfeas_Loggers"

# Remove old logs if more than MAX_LOGS exist
LOG_COUNT=$(ls -1t "$UPDATE_LOGS_DIR"/Morfeas_update_*.log 2>/dev/null | wc -l)
if [ "$LOG_COUNT" -gt "$MAX_LOGS" ]; then
    ls -1t "$UPDATE_LOGS_DIR"/Morfeas_update_*.log | tail -n +$((MAX_LOGS + 1)) | xargs rm -f
fi

# Setup date and log file
date=$(date +"%Y-%m-%d_%H-%M-%S")
log_file="$UPDATE_LOGS_DIR/Morfeas_update_$date.log"

# Directories
MORFEAS_WEB_DIR="/var/www/html/morfeas_web"
MORFEAS_CORE_DIR="/opt/Morfeas_project/Morfeas_core"

# Update flags
core_updated=0
web_updated=0

# ---------------------------
# CHECK-ONLY MODE
# ---------------------------
if [ "$1" == "--check-only" ]; then
    {
        print_status "Starting check-only mode..."

        web_update_needed=0
        core_update_needed=0

        # -- Check Web --
        if [ -d "$MORFEAS_WEB_DIR" ]; then
            cd "$MORFEAS_WEB_DIR" || { echo "Cannot access $MORFEAS_WEB_DIR"; exit 1; }
            git fetch origin
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
            CORE_BRANCH=$(git rev-parse --abbrev-ref HEAD)
            CORE_LOCAL=$(git rev-parse HEAD)
            CORE_REMOTE=$(git rev-parse "origin/$CORE_BRANCH")
            echo "Core Branch: $CORE_BRANCH"
            echo "Core Local:  $CORE_LOCAL"
            echo "Core Remote: $CORE_REMOTE"
            [ "$CORE_LOCAL" != "$CORE_REMOTE" ] && core_update_needed=1
        fi

        # --- Final Decision ---
        if [ $web_update_needed -eq 1 ] || [ $core_update_needed -eq 1 ]; then
            echo "Update available (Core or Web)."
            exit 100
        else
            echo "System is already up-to-date."
            exit 0
        fi
    } &> "$log_file"
    exit
fi

# ---------------------------
# FULL UPDATE PROCESS
# ---------------------------
{
    print_status "Starting full update process..."

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

    print_status "Update files check completed."

    # ---------------------------
    # Restart Services If Needed
    # ---------------------------
    if [ $core_updated -eq 1 ] || [ $web_updated -eq 1 ]; then
        print_status "Scheduling restart in background..."
        (
            sleep 5
            echo "Restarting Morfeas and Apache services..."
            sudo systemctl restart Morfeas_system
            sudo systemctl restart apache2
            echo "Services restarted."
        ) &
    else
        print_status "No updates applied, no restart needed."
    fi

    print_status "Update process completed."
    echo "Log file: $log_file"

} &> "$log_file"

exit 0
