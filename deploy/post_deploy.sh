#!/bin/bash
set -euo pipefail

export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

LOG_TAG="[morfeas-post-deploy]"
APACHE_RESTART_REQUIRED=0
JOURNALD_RESTART_REQUIRED=0
RESTART_STATE_DIR="/run/morfeas_update"
DEFER_SERVICE_RESTARTS="${MORFEAS_DEFER_SERVICE_RESTARTS:-0}"

log_info() {
    echo "$LOG_TAG $*"
}

log_warn() {
    echo "$LOG_TAG WARN: $*" >&2
}

mark_restart_required() {
    local service_name="$1"
    mkdir -p "$RESTART_STATE_DIR"
    touch "$RESTART_STATE_DIR/${service_name}.restart"
}

require_root() {
    if [ "$(id -u)" -ne 0 ]; then
        echo "$LOG_TAG ERROR: must run as root" >&2
        exit 1
    fi
}

install_regular_file() {
    local src="$1"
    local dst="$2"
    local mode="$3"
    local owner="${4:-root}"
    local group="${5:-root}"

    if [ ! -f "$src" ]; then
        log_warn "source missing: $src"
        return 1
    fi

    install -o "$owner" -g "$group" -m "$mode" "$src" "$dst"
}

install_sudoers_file() {
    local src="$1"
    local dst="$2"
    local tmp
    tmp="$(mktemp /tmp/morfeas_sudoers.XXXXXX)"

    install_regular_file "$src" "$tmp" 440 root root
    visudo -cf "$tmp" >/dev/null
    install -o root -g root -m 440 "$tmp" "$dst"
    visudo -cf "$dst" >/dev/null
    rm -f "$tmp"
}

ensure_executable() {
    local path="$1"
    if [ -f "$path" ]; then
        chmod 755 "$path"
    fi
}

ensure_root_cron_line() {
    local line="$1"
    local tmp
    tmp="$(mktemp /tmp/morfeas_cron.XXXXXX)"

    crontab -l >"$tmp" 2>/dev/null || true
    if ! grep -Fqx "$line" "$tmp"; then
        echo "$line" >>"$tmp"
        crontab "$tmp"
        log_info "added root cron: $line"
    fi
    rm -f "$tmp"
}

remove_root_cron_line() {
    local line="$1"
    local tmp
    local filtered
    tmp="$(mktemp /tmp/morfeas_cron.XXXXXX)"
    filtered="$(mktemp /tmp/morfeas_cron_filtered.XXXXXX)"

    crontab -l >"$tmp" 2>/dev/null || true
    grep -Fvx "$line" "$tmp" >"$filtered" || true
    if ! cmp -s "$tmp" "$filtered"; then
        crontab "$filtered"
        log_info "removed root cron: $line"
    fi

    rm -f "$tmp" "$filtered"
}

ensure_apache_private_tmp() {
    local dir="/etc/systemd/system/apache2.service.d"
    local file="$dir/no-private-tmp.conf"
    local desired="[Service]
PrivateTmp=false
"

    mkdir -p "$dir"
    if [ ! -f "$file" ] || [ "$(cat "$file")" != "$desired" ]; then
        printf "%s" "$desired" >"$file"
        chmod 644 "$file"
        systemctl daemon-reload
        log_info "updated apache2 PrivateTmp override"
        APACHE_RESTART_REQUIRED=1
    fi
}

ensure_apache_servername_conf() {
    local src="$REPO_ROOT/apache_site_conf/morfeas-servername.conf"
    local dst="/etc/apache2/conf-available/morfeas-servername.conf"

    if [ ! -f "$src" ]; then
        log_warn "apache ServerName snippet missing: $src"
        return 1
    fi

    if [ ! -f "$dst" ] || ! cmp -s "$src" "$dst"; then
        install_regular_file "$src" "$dst" 644 root root
        APACHE_RESTART_REQUIRED=1
        log_info "installed apache ServerName snippet"
    fi

    if [ ! -e "/etc/apache2/conf-enabled/morfeas-servername.conf" ]; then
        a2enconf morfeas-servername >/dev/null
        APACHE_RESTART_REQUIRED=1
        log_info "enabled apache conf: morfeas-servername"
    fi
}

ensure_journald_persistent() {
    local dir="/etc/systemd/journald.conf.d"
    local file="$dir/10-morfeas-journal.conf"
    local desired="[Journal]
Storage=persistent
SystemMaxUse=100M
"

    mkdir -p "$dir"
    mkdir -p /var/log/journal

    if [ ! -f "$file" ] || [ "$(cat "$file")" != "$desired" ]; then
        printf "%s" "$desired" >"$file"
        chmod 644 "$file"
        JOURNALD_RESTART_REQUIRED=1
        log_info "updated journald persistent storage override"
    fi
}

ensure_logger_dir_access() {
    local dir="/mnt/ramdisk/Morfeas_Loggers"
    local api_log="$dir/morfeas_web_api.log"
    local state_dir="/var/lib/morfeas"

    mkdir -p "$dir"

    # Allow shared write access between morfeas services and www-data.
    chown root:morfeas "$dir"
    chmod 2775 "$dir"

    # Normalize existing logger files so morfeas/root/www-data can safely append later.
    find "$dir" -maxdepth 1 -type f -name '*.log' -exec chgrp morfeas {} +
    find "$dir" -maxdepth 1 -type f -name '*.log' -exec chmod 664 {} +

    # Ensure web API internal log is always writable by apache worker.
    touch "$api_log"
    chown www-data:morfeas "$api_log"
    chmod 664 "$api_log"

    if ! id -nG www-data | tr ' ' '\n' | grep -qx 'morfeas'; then
        usermod -a -G morfeas www-data
        APACHE_RESTART_REQUIRED=1
        log_info "added www-data to morfeas group"
    fi

    mkdir -p "$state_dir"
    chmod 755 "$state_dir"
}

main() {
    require_root

    log_info "start"
    mkdir -p "$RESTART_STATE_DIR"

    ensure_logger_dir_access

    install_regular_file \
        "$REPO_ROOT/logrotate/morfeas-loggers" \
        "/etc/logrotate.d/morfeas-loggers" \
        644 root root
    log_info "installed /etc/logrotate.d/morfeas-loggers"

    install_sudoers_file \
        "$REPO_ROOT/sudoers/Morfeas_update_allow" \
        "/etc/sudoers.d/Morfeas_update_allow"
    log_info "installed /etc/sudoers.d/Morfeas_update_allow"

    if [ -f "$REPO_ROOT/sudoers/Morfeas_web_allow" ]; then
        install_sudoers_file \
            "$REPO_ROOT/sudoers/Morfeas_web_allow" \
            "/etc/sudoers.d/Morfeas_web_allow"
        log_info "installed /etc/sudoers.d/Morfeas_web_allow"
    else
        log_warn "optional sudoers file missing: Morfeas_web_allow"
    fi

    if [ -f "$REPO_ROOT/sudoers/Morfeas_web_journal_allow" ]; then
        install_sudoers_file \
            "$REPO_ROOT/sudoers/Morfeas_web_journal_allow" \
            "/etc/sudoers.d/Morfeas_web_journal_allow"
        log_info "installed /etc/sudoers.d/Morfeas_web_journal_allow"
    else
        log_warn "optional sudoers file missing: Morfeas_web_journal_allow"
    fi

    ensure_executable "$REPO_ROOT/deploy/system_update.sh"
    ensure_executable "$REPO_ROOT/deploy/core_update.sh"
    ensure_executable "$REPO_ROOT/deploy/post_deploy.sh"
    ensure_executable "$REPO_ROOT/deploy/backup.sh"
    ensure_executable "$REPO_ROOT/cron/system_update_check.sh"

    ensure_root_cron_line "@reboot sleep 30 && /var/www/html/morfeas_web/cron/system_update_check.sh"
    ensure_root_cron_line "0 0 * * * /var/www/html/morfeas_web/cron/system_update_check.sh"
    ensure_root_cron_line "0 0 * * * /var/www/html/morfeas_web/deploy/backup.sh"
    remove_root_cron_line "@reboot sleep 30 && /var/www/html/morfeas_web/cron/update_cron_wrapper.sh"
    remove_root_cron_line "0 0 * * * /var/www/html/morfeas_web/cron/update_cron_wrapper.sh"
    remove_root_cron_line "0 0 * * * /var/www/html/morfeas_web/backup.sh"

    ensure_apache_private_tmp
    ensure_apache_servername_conf
    ensure_journald_persistent

    if [ "$APACHE_RESTART_REQUIRED" -eq 1 ]; then
        if [ "$DEFER_SERVICE_RESTARTS" = "1" ]; then
            mark_restart_required apache2
            log_info "deferred apache2 restart"
        else
            systemctl restart apache2
            log_info "restarted apache2 for updated systemd override"
        fi
    fi
    if [ "$JOURNALD_RESTART_REQUIRED" -eq 1 ]; then
        if [ "$DEFER_SERVICE_RESTARTS" = "1" ]; then
            mark_restart_required systemd-journald
            log_info "deferred systemd-journald restart"
        else
            systemctl restart systemd-journald
            log_info "restarted systemd-journald for persistent logging"
        fi
    fi

    log_info "done"
}

main "$@"
