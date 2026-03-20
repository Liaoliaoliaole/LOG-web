#!/bin/bash
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

LOG_FILE="/tmp/daily_update_check.log"
FLAG_FILE="/tmp/update_needed"
LOGGER_DIR="/mnt/ramdisk/Morfeas_Loggers"
LOGGER_FILE="$LOGGER_DIR/LOG_daily_update_check.log"

# Run update check and overwrite log
/var/www/html/morfeas_web/update.sh --check-only > "$LOG_FILE" 2>&1
exit_code=$?

{
    echo "Update.sh exited with $exit_code"
    if [ $exit_code -eq 100 ]; then
        echo "Update available. Creating flag file."
        [ -f "$FLAG_FILE" ] || {
            touch "$FLAG_FILE"
        }
    elif [ $exit_code -eq 0 ]; then
        echo "No update. Removing flag if exists."
        rm -f "$FLAG_FILE"
    else
        echo "Check failed. Leaving flag status unchanged."
    fi
} >> "$LOG_FILE"

# Mirror daily check log into System Status logger directory.
mkdir -p "$LOGGER_DIR"
cp -f "$LOG_FILE" "$LOGGER_FILE"
