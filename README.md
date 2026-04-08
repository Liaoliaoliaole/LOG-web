# LOG Web (Morfeas) - Deployment and Runtime Guide

## 1) Current Baseline (Post-Upgrade)

This repository is maintained for upgraded LOG devices with the following runtime baseline:

- OS: Debian 12 (Bookworm) / Raspberry Pi OS Bookworm
- Web server: Apache2
- PHP: 8.2.x
- OpenSSL: 3.x
- Deployment path on device: `/var/www/html/morfeas_web`

## 2) Required Packages

Install required runtime/build tools on the target Pi:

```bash
sudo apt update
sudo apt install -y \
  apache2 \
  php \
  libapache2-mod-php \
  php-dev \
  php-xml \
  php-mbstring \
  git \
  jq \
  curl
```

## 3) Optional Dependency: `pecl-dbus`

`pecl-dbus` is only needed for legacy DBus-dependent flows (for example legacy MTI/NOX pages).

To build it when needed:

```bash
./build_submodule.sh
```

## 4) Deploy Application

Clone and pull submodules:

```bash
git clone https://git.devops.wartsila.com/scm/log/log_web.git
cd log_web
git submodule update --init --recursive --remote --merge
```

Copy to device web root:

```bash
sudo cp -r . /var/www/html/morfeas_web
sudo chown -R morfeas:morfeas /var/www/html/morfeas_web
sudo chmod -R 755 /var/www/html/morfeas_web
```

Create environment file:

```bash
sudo cp /var/www/html/morfeas_web/Morfeas_WEB/Morfeas_env.php.template \
        /var/www/html/morfeas_web/Morfeas_WEB/Morfeas_env.php
sudo nano /var/www/html/morfeas_web/Morfeas_WEB/Morfeas_env.php
```

## 5) Apache Setup

Install and enable the site config:

```bash
sudo cp /var/www/html/morfeas_web/apache_site_conf/Morfeas_web.conf /etc/apache2/sites-available/Morfeas_web.conf
sudo cp /var/www/html/morfeas_web/apache_site_conf/morfeas-servername.conf /etc/apache2/conf-available/morfeas-servername.conf
sudo a2dissite 000-default.conf
sudo a2ensite Morfeas_web.conf
sudo a2enconf morfeas-servername
sudo systemctl reload apache2
sudo systemctl restart apache2
```

Open:

- `http://localhost`
- `http://<LOG_DEVICE_IP>`

## 6) Sudoers Setup for Web Actions

### 6.1 General web operations

Apply the project sudoers template for `Morfeas_web_allow`:

```bash
sudo visudo -f /etc/sudoers.d/Morfeas_web_allow
```

For **System Status -> System Journal**:

- include `/usr/bin/journalctl` in `Morfeas_web_allow`
- API will first try direct read, then fallback to `sudo -n /usr/bin/journalctl`
- or install the dedicated snippet `sudoers/Morfeas_web_journal_allow`

### 6.2 System Update feature (web + core)

Apply/update:

```bash
sudo visudo -f /etc/sudoers.d/Morfeas_update_allow
```

Current expected scope includes web + core update:

- `/var/www/html/morfeas_web/deploy/system_update.sh`
- `/bin/systemctl restart apache2`

## 7) System Update Behavior

`deploy/system_update.sh` now orchestrates **web + core** update:

- `--check-only`: checks updates for both web and core repositories
- `--update`: updates web repo, runs web post-deploy, then runs core update script
- core update script (canonical): `deploy/core_update.sh`
- core flow uses lock file (`/var/lock/morfeas_core_update.lock`) to avoid concurrent runs
- core flow enforces unified restart through `Morfeas_system.service` (via `build_core_code_only.sh`)
- post-update auto-deploy hook (canonical): `deploy/post_deploy.sh` (runs on every `--update`)

Post-update deploy hook (`deploy/post_deploy.sh`) does:

- install `/etc/logrotate.d/morfeas-loggers`
- install `/etc/sudoers.d/Morfeas_update_allow`
- install `/etc/sudoers.d/Morfeas_web_journal_allow` (for System Journal fallback)
- ensure `/mnt/ramdisk/Morfeas_Loggers` shared write access (`morfeas` group, setgid)
- normalize existing `Morfeas_Loggers/*.log` files to `group=morfeas`, mode `664`
- ensure `morfeas_web_api.log` writable by `www-data`
- ensure `www-data` is member of `morfeas` group
- ensure execute bits for `deploy/system_update.sh`, `deploy/core_update.sh`, `deploy/post_deploy.sh`, `cron/system_update_check.sh`, `deploy/backup.sh`
- ensure root cron entries for update check + backup
- ensure `systemd-journald` persistent storage (for System Journal tab)

Exit codes:

- `0`: up-to-date / success
- `100`: update available (check-only mode, web or core)
- `2`: git/network unreachable for web or core repo
- others: failure

Update flag:

- `/var/lib/morfeas/update_needed` is used by the UI indicator and status API.

## 8) Cron / Flag Workflow

Ensure daily check script exists and is executable:

```bash
sudo chmod +x /var/www/html/morfeas_web/cron/system_update_check.sh
```

Root crontab should include daily update check:

```bash
sudo crontab -l
```

Daily wrapper log visibility:

- `cron/system_update_check.sh` writes `/tmp/daily_update_check.log`
- and mirrors it to `/mnt/ramdisk/Morfeas_Loggers/LOG_daily_update_check.log`
- mirrored logger file is normalized to `group=morfeas`, mode `664`
- therefore it is visible in **System Status -> System Log**

## 9) Morfeas Logger Rotation (`Morfeas_Loggers/*.log`)

Install project logrotate rule:

```bash
sudo cp /var/www/html/morfeas_web/logrotate/morfeas-loggers /etc/logrotate.d/morfeas-loggers
sudo chmod 644 /etc/logrotate.d/morfeas-loggers
sudo logrotate -d /etc/logrotate.conf
```

Rule target:

- `/mnt/ramdisk/Morfeas_Loggers/*.log`
- `daily`, `rotate 7`, `compress`, `copytruncate`

System Status visibility:

- files under `/mnt/ramdisk/Morfeas_Loggers/` are listed in **System Log** page.
- includes `morfeas_web_api.log`, `LOG_daily_update_check.log`, `LOG_update_*.log`, and runtime logs.
- systemd journal is shown in dedicated **System Journal** tab (separate from file-based logs).

Operational ownership model:

- logger directory: `root:morfeas`, mode `2775`
- runtime component logs: typically `morfeas:morfeas`
- web API log: `www-data:morfeas`
- root-created maintenance logs should still be normalized to `group=morfeas`, mode `664`

## 10) Update Flag Persistence

The update reminder flag now lives at `/var/lib/morfeas/update_needed`.
This is persistent application state, so it survives reboot and does not depend on shared `/tmp`.

Backend refresh model:

- refreshed at boot by `@reboot /var/www/html/morfeas_web/cron/system_update_check.sh`
- refreshed daily by root cron
- read lightly by the UI indicator and status API
- real remote update checks happen only in the **System Update** flow

## 11) Quick Health Verification

```bash
cat /etc/os-release | egrep 'PRETTY_NAME|VERSION_CODENAME'
php -v | head -n1
openssl version
systemctl is-active apache2 ssh
curl -s "http://127.0.0.1/backend/api_system_update.php?action=status"
```

## 12) Legacy Reference

Older original upstream README content has been replaced by this device-oriented guide to match current upgraded architecture and operational policy.

## 13) TC16 API Regression Smoke

Run contract checks for new TC16 endpoints:

```bash
cd /var/www/html/morfeas_web
./deploy/tc16_api_regression.sh http://127.0.0.1
```

Optional atomicity check (verifies failed replace does not modify XML):

```bash
TC16_SOURCE_ISO=_T7001 ./deploy/tc16_api_regression.sh http://127.0.0.1
```

Notes:

- `TC16_SOURCE_ISO` should be an existing channel ISO on the device.
- Expected failures are validated by HTTP status + API `code`.
- Script exits non-zero if any assertion fails.

## 14) Help Manual PDF

The active user manual for the new Morfeas web UI is maintained in:

- `Morfeas_WEB/docs/manual/`

The generated PDF delivered by the Help menu is:

- `Morfeas_WEB/docs/help_manual.pdf`

Build or rebuild the PDF locally with:

```bash
make -C Morfeas_WEB/docs/manual
```

Clean intermediate files:

```bash
make -C Morfeas_WEB/docs/manual clean
```

The legacy LaTeX tree under `Docs/Morfeas_WEB_Docs/` is archived reference only and should not be used as the active operator manual.
