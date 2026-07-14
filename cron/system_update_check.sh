#!/bin/bash
set -euo pipefail

export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

LOG_FILE="/tmp/daily_update_check.log"
FLAG_DIR="/var/lib/morfeas"
FLAG_FILE="$FLAG_DIR/update_needed"
LOGGER_DIR="/mnt/ramdisk/Morfeas_Loggers"
LOGGER_FILE="$LOGGER_DIR/LOG_daily_update_check.log"
SYSTEM_UPDATE_SCRIPT="/var/www/html/morfeas_web/deploy/system_update.sh"

log_line() {
    local level="$1"
    shift
    local ts
    ts=$(date +"%Y-%m-%dT%H:%M:%S%z")
    printf '[%s] [UPDATE_CRON] [%s] %s\n' "$ts" "$level" "$*" >> "$LOG_FILE"
}

mkdir -p "$FLAG_DIR"
mkdir -p "$LOGGER_DIR"
chown root:morfeas "$LOGGER_DIR" 2>/dev/null || true
chmod 2775 "$LOGGER_DIR" 2>/dev/null || true

# Run update check and overwrite log
"$SYSTEM_UPDATE_SCRIPT" --check-only > "$LOG_FILE" 2>&1
exit_code=$?

log_line "INFO" "system_update_check finished with exit_code=$exit_code"
if [ $exit_code -eq 100 ]; then
    if [ -f "$FLAG_FILE" ]; then
        log_line "INFO" "Update available. flag_file=$FLAG_FILE already exists."
    else
        touch "$FLAG_FILE"
        log_line "INFO" "Update available. flag_file=$FLAG_FILE created."
    fi
elif [ $exit_code -eq 0 ]; then
    rm -f "$FLAG_FILE"
    log_line "INFO" "No update. flag_file=$FLAG_FILE removed if present."
else
    log_line "WARN" "Check failed. flag_file status unchanged."
fi

# Mirror daily check log into System Status logger directory.
cp -f "$LOG_FILE" "$LOGGER_FILE"
chgrp morfeas "$LOGGER_FILE" 2>/dev/null || true
chmod 664 "$LOGGER_FILE" 2>/dev/null || true
