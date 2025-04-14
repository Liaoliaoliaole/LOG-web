#!/bin/bash

CONFIG_DIR="/home/morfeas/configuration"
PHP_SCRIPT="/var/www/html/morfeas_web/Morfeas_WEB/morfeas_php/ftp_api.php"

LOG_FILE="/tmp/ftp_debug.log"

FTP_CONFIG_FILE="$CONFIG_DIR/ftp_config.json"

if [[ ! -f "$FTP_CONFIG_FILE" ]]; then
    # If the file doesn't exist, log it and exit
    echo "$(date) - FTP config file not found. No Valid Engine Number Provided. Backup not performed." >> "$LOG_FILE"
    exit 1
fi

ENGINE_NUMBER=$(jq -r '.dir' "$FTP_CONFIG_FILE")

if [[ -z "$ENGINE_NUMBER" ]]; then
    echo "$(date) - Engine number not found in $FTP_CONFIG_FILE. Backup not performed." >> "$LOG_FILE"
    exit 1
fi

BACKUP_DIR="$CONFIG_DIR/$ENGINE_NUMBER"
if [[ ! -d "$BACKUP_DIR" ]]; then
    mkdir -p "$BACKUP_DIR"
    if [[ $? -ne 0 ]]; then
        echo "$(date) - Failed to create backup directory $BACKUP_DIR." >> "$LOG_FILE"
        exit 1
    fi
fi

php "$PHP_SCRIPT" <<EOF
{
    "action": "backup"
}
EOF

if [[ $? -eq 0 ]]; then
    echo "$(date) - Backup created successfully for engine number $ENGINE_NUMBER." >> "$LOG_FILE"
else
    echo "$(date) - Backup failed for engine number $ENGINE_NUMBER." >> "$LOG_FILE"
fi

exit 0
