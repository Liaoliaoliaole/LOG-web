# LOG Web (Morfeas) - Deployment and Runtime Guide

## 1) Current Baseline (Post-Upgrade)

This repository is maintained for upgraded LOG devices with the following runtime baseline:

- OS: Debian 12 (Bookworm) / Raspberry Pi OS Bookworm
- Web server: Apache2
- PHP: 8.2.x
- OpenSSL: 3.x
- Deployment path on device: `/var/www/html/morfeas_web`

Notes:

- This baseline reflects the current upgraded Pi environment.
- Older OS baselines (for example Bullseye) are not the primary target anymore.

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
sudo a2dissite 000-default.conf
sudo a2ensite Morfeas_web.conf
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

### 6.2 System Update feature (web-only)

Apply/update:

```bash
sudo visudo -f /etc/sudoers.d/Morfeas_update_allow
```

Current expected scope is web-only update:

- `/var/www/html/morfeas_web/update.sh`
- `/bin/systemctl restart apache2`
- `/bin/rm`

## 7) System Update Behavior

`update.sh` is now intentionally **web-only**:

- `--check-only`: checks updates for web repository only
- `--update`: updates web repository only
- no core repository check/update
- no core build/install
- restart target: `apache2` only

Exit codes:

- `0`: up-to-date / success
- `100`: update available (check-only mode)
- `2`: git/network unreachable for web repo
- others: failure

Update flag:

- `/tmp/update_needed` is used by UI indicator and status API.

## 8) Cron / Flag Workflow

Ensure wrapper exists and is executable:

```bash
sudo chmod +x /var/www/html/morfeas_web/cron/update_cron_wrapper.sh
```

Root crontab should include daily update check:

```bash
sudo crontab -l
```

## 9) Apache `PrivateTmp` Requirement

The web process must see real `/tmp` so `/tmp/update_needed` is shared correctly.

Verify:

```bash
systemctl show apache2 -p PrivateTmp
```

Expected:

- `PrivateTmp=no`

If needed, use drop-in override with `PrivateTmp=false`, then restart apache.

## 10) Quick Health Verification

```bash
cat /etc/os-release | egrep 'PRETTY_NAME|VERSION_CODENAME'
php -v | head -n1
openssl version
systemctl is-active apache2 ssh
curl -s "http://127.0.0.1/backend/api_system_update.php?action=status"
```

## 11) Legacy Reference

Older original upstream README content has been replaced by this device-oriented guide to match current upgraded architecture and operational policy.
