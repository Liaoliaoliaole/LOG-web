#!/bin/bash

CONFIG_DIR="/home/morfeas/configuration"
PHP_SCRIPT="/var/www/html/morfeas_web/Morfeas_WEB/morfeas_php/ftp_api.php"
LOG_FILE="/tmp/ftp_debug.log"
FTP_CONFIG_FILE="$CONFIG_DIR/ftp_config.json"

log_cli() {
    local level="$1"
    local message="$2"
    local timestamp
    timestamp=$(date "+%Y-%m-%d %H:%M:%S")
    echo "[$timestamp] [CLI] [$level] $message" >> "$LOG_FILE"
}

if [[ ! -f "$LOG_FILE" ]]; then
    sudo touch "$LOG_FILE"
    sudo chown www-data:www-data "$LOG_FILE"
    sudo chmod 666 "$LOG_FILE"
else
    if [[ ! -w "$LOG_FILE" ]]; then
        sudo chmod 666 "$LOG_FILE"
    fi
fi

if [[ ! -f "$FTP_CONFIG_FILE" ]]; then
    log_cli "ERROR" "FTP config file not found. No Valid Engine Number Provided. Backup not performed."
    exit 1
fi

ENGINE_NUMBER=$(jq -r '.dir' "$FTP_CONFIG_FILE")

if [[ -z "$ENGINE_NUMBER" ]]; then
    log_cli "ERROR" "Engine number not found in $FTP_CONFIG_FILE. Backup not performed."
    exit 1
fi

BACKUP_DIR="$CONFIG_DIR/$ENGINE_NUMBER"
if [[ ! -d "$BACKUP_DIR" ]]; then
    mkdir -p "$BACKUP_DIR"
    if [[ $? -ne 0 ]]; then
        log_cli "ERROR" "Failed to create backup directory $BACKUP_DIR."
        exit 1
    fi
fi

php "$PHP_SCRIPT" <<EOF
{
    "action":"backup"
}
EOF

if [[ $? -eq 0 ]]; then
    log_cli "INFO" "Backup created successfully for engine number $ENGINE_NUMBER."
else
    log_cli "ERROR" "Backup failed for engine number $ENGINE_NUMBER."
fi

cp "$LOG_FILE" "/mnt/ramdisk/Morfeas_Loggers/LOG_FTP_backup.log"

php "$PHP_SCRIPT" <<EOF
{
    "action": "uploadLog"
}
EOF

exit 0
