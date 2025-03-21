#!/bin/bash
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

LOG_FILE="/tmp/daily_update_check.log"
FLAG_FILE="/var/run/morfeas/update_needed"

# Run update check and overwrite log
/var/www/html/morfeas_web/update.sh --check-only > "$LOG_FILE" 2>&1
exit_code=$?

{
    echo "Update.sh exited with $exit_code"
    if [ $exit_code -eq 100 ]; then
        echo "Update available. Creating flag file."
        touch "$FLAG_FILE"
        chown www-data:www-data "$FLAG_FILE"
        chmod 777 "$FLAG_FILE"
    else
        echo "No update. Removing flag file if exists."
        rm -f "$FLAG_FILE"
    fi
} >> "$LOG_FILE"
