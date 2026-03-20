#!/bin/bash

CONFIG_DIR="/home/morfeas/configuration"
FTP_BACKUP_CLI="/var/www/html/morfeas_web/Morfeas_WEB/backend/cli/ftp_backup_cli.php"
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

if [[ ! -f "$FTP_BACKUP_CLI" ]]; then
    log_cli "ERROR" "FTP backup CLI script missing at $FTP_BACKUP_CLI. Backup not performed."
    exit 1
fi

ENGINE_NUMBER=$(php -r '
$cfg = @file_get_contents($argv[1]);
$json = is_string($cfg) ? json_decode($cfg, true) : null;
$dir = is_array($json) ? trim((string)($json["dir"] ?? "")) : "";
echo $dir;
' "$FTP_CONFIG_FILE")

if [[ -z "$ENGINE_NUMBER" ]]; then
    log_cli "ERROR" "Engine number not found in $FTP_CONFIG_FILE. Backup not performed."
    exit 1
fi

php "$FTP_BACKUP_CLI" backup >> "$LOG_FILE" 2>&1

if [[ $? -eq 0 ]]; then
    log_cli "INFO" "Backup created successfully for engine number $ENGINE_NUMBER."
else
    log_cli "ERROR" "Backup failed for engine number $ENGINE_NUMBER."
    cp "$LOG_FILE" "/mnt/ramdisk/Morfeas_Loggers/LOG_FTP_backup.log"
    exit 1
fi

cp "$LOG_FILE" "/mnt/ramdisk/Morfeas_Loggers/LOG_FTP_backup.log"

if ! php "$FTP_BACKUP_CLI" upload-log >> "$LOG_FILE" 2>&1; then
    log_cli "ERROR" "Log upload failed for engine number $ENGINE_NUMBER."
fi

exit 0
