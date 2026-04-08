#!/bin/bash
set -euo pipefail

export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

CONFIG_DIR="/home/morfeas/configuration"
FTP_BACKUP_CLI="/var/www/html/morfeas_web/Morfeas_WEB/backend/cli/ftp_backup_cli.php"
LOG_FILE="/tmp/ftp_debug.log"
FTP_CONFIG_FILE="$CONFIG_DIR/ftp_config.json"
LOGGER_MIRROR_FILE="/mnt/ramdisk/Morfeas_Loggers/LOG_FTP_backup.log"

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
    exec sudo "$0" "$@"
fi

ensure_log_file() {
    local log_dir
    log_dir=$(dirname "$LOG_FILE")
    mkdir -p "$log_dir"
    touch "$LOG_FILE"
    chown www-data:www-data "$LOG_FILE" 2>/dev/null || true
    chmod 666 "$LOG_FILE" 2>/dev/null || true
}

mirror_log() {
    local mirror_dir
    mirror_dir=$(dirname "$LOGGER_MIRROR_FILE")
    mkdir -p "$mirror_dir" 2>/dev/null || true
    cp "$LOG_FILE" "$LOGGER_MIRROR_FILE" 2>/dev/null || true
    chown www-data:morfeas "$LOGGER_MIRROR_FILE" 2>/dev/null || true
    chmod 664 "$LOGGER_MIRROR_FILE" 2>/dev/null || true
}

log_cli() {
    local level="$1"
    local message="$2"
    local timestamp
    timestamp=$(date "+%Y-%m-%d %H:%M:%S")
    printf '[%s] [CLI] [%s] %s\n' "$timestamp" "$level" "$message" >> "$LOG_FILE" 2>/dev/null || true
}

ensure_log_file

if [[ ! -f "$FTP_CONFIG_FILE" ]]; then
    log_cli "ERROR" "FTP config file not found. No Valid Engine Number Provided. Backup not performed."
    mirror_log
    exit 1
fi

if [[ ! -f "$FTP_BACKUP_CLI" ]]; then
    log_cli "ERROR" "FTP backup CLI script missing at $FTP_BACKUP_CLI. Backup not performed."
    mirror_log
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
    mirror_log
    exit 1
fi

if php "$FTP_BACKUP_CLI" backup >> "$LOG_FILE" 2>&1; then
    log_cli "INFO" "Backup created successfully for engine number $ENGINE_NUMBER."
else
    log_cli "ERROR" "Backup failed for engine number $ENGINE_NUMBER."
    mirror_log
    exit 1
fi

mirror_log

if ! php "$FTP_BACKUP_CLI" upload-log >> "$LOG_FILE" 2>&1; then
    log_cli "ERROR" "Log upload failed for engine number $ENGINE_NUMBER."
    mirror_log
fi

exit 0
