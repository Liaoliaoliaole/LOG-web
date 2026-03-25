#!/bin/bash
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

LOG_FILE="/tmp/daily_update_check.log"
FLAG_DIR="/var/lib/morfeas"
FLAG_FILE="$FLAG_DIR/update_needed"
LOGGER_DIR="/mnt/ramdisk/Morfeas_Loggers"
LOGGER_FILE="$LOGGER_DIR/LOG_daily_update_check.log"

log_line() {
    local level="$1"
    shift
    local ts
    ts=$(date +"%Y-%m-%dT%H:%M:%S%z")
    printf '[%s] [UPDATE_CRON] [%s] %s\n' "$ts" "$level" "$*" >> "$LOG_FILE"
}

mkdir -p "$FLAG_DIR"

# Run update check and overwrite log
/var/www/html/morfeas_web/update.sh --check-only > "$LOG_FILE" 2>&1
exit_code=$?

log_line "INFO" "update.sh finished with exit_code=$exit_code"
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
mkdir -p "$LOGGER_DIR"
cp -f "$LOG_FILE" "$LOGGER_FILE"
