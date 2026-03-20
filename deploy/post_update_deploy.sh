#!/bin/bash
set -euo pipefail

export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

LOG_TAG="[morfeas-post-deploy]"
APACHE_RESTART_REQUIRED=0

log_info() {
    echo "$LOG_TAG $*"
}

log_warn() {
    echo "$LOG_TAG WARN: $*" >&2
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

main() {
    require_root

    log_info "start"

    mkdir -p /mnt/ramdisk/Morfeas_Loggers

    install_regular_file \
        "$REPO_ROOT/logrotate/morfeas-loggers" \
        "/etc/logrotate.d/morfeas-loggers" \
        644 root root
    log_info "installed /etc/logrotate.d/morfeas-loggers"

    install_sudoers_file \
        "$REPO_ROOT/sudoers/Morfeas_update_allow" \
        "/etc/sudoers.d/Morfeas_update_allow"
    log_info "installed /etc/sudoers.d/Morfeas_update_allow"

    if [ -f "$REPO_ROOT/sudoers/Morfeas_web_journal_allow" ]; then
        install_sudoers_file \
            "$REPO_ROOT/sudoers/Morfeas_web_journal_allow" \
            "/etc/sudoers.d/Morfeas_web_journal_allow"
        log_info "installed /etc/sudoers.d/Morfeas_web_journal_allow"
    else
        log_warn "optional sudoers file missing: Morfeas_web_journal_allow"
    fi

    ensure_executable "$REPO_ROOT/update.sh"
    ensure_executable "$REPO_ROOT/cron/update_cron_wrapper.sh"
    ensure_executable "$REPO_ROOT/backup.sh"

    ensure_root_cron_line "0 0 * * * /var/www/html/morfeas_web/cron/update_cron_wrapper.sh"
    ensure_root_cron_line "0 0 * * * /var/www/html/morfeas_web/backup.sh"

    ensure_apache_private_tmp

    if [ "$APACHE_RESTART_REQUIRED" -eq 1 ]; then
        systemctl restart apache2
        log_info "restarted apache2 for updated systemd override"
    fi

    log_info "done"
}

main "$@"
