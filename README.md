# LOG-web

`LOG-web` is the web interface for the Morfeas / LOG system.

It provides:
- the main channel linker page
- device and network management pages
- backup/restore and system status pages
- SDAQ calibration and scale popups

The deployable application lives in `Morfeas_WEB/`.

## Current Baseline

This repository is maintained for upgraded LOG devices with the following runtime baseline:

- OS: Debian 12 (Bookworm) / Raspberry Pi OS Bookworm
- Web server: Apache2
- PHP: 8.2.x
- OpenSSL: 3.x
- Deployment path on device: `/var/www/html/morfeas_web`

## Dependencies

Minimum runtime dependencies:

- `apache2`
- `php`
- `libapache2-mod-php`
- `php-xml`
- `php-mbstring`
- `php-ftp`
- `curl`

Useful development/build tools:

- `git`
- `make`
- `jq`

Example install on Debian / Raspberry Pi OS:

```bash
sudo apt update
sudo apt install -y \
  apache2 \
  php \
  libapache2-mod-php \
  php-xml \
  php-mbstring \
  php-ftp \
  curl \
  git \
  make \
  jq
```

## Build

Common checks:

```bash
php -l Morfeas_WEB/backend/api_channels.php
php -l Morfeas_WEB/backend/api_calibration.php
node -e "const fs=require('fs'); new Function(fs.readFileSync('Morfeas_WEB/assets/index.js','utf8')); console.log('ok');"
make -C Morfeas_WEB/docs/manual
```

## Usage

Typical local workflow:

```bash
cd Morfeas_WEB
```

Deploy the web root to:

```text
/var/www/html/morfeas_web
```

Typical entry page:

```text
http://<device-ip>/
```

Main application entry file:

```text
Morfeas_WEB/index.html
```

## Notes

- Production paths for configuration, ramdisk, and ISO standards are defined in `Morfeas_WEB/backend/core/paths.php`.
- The end-user manual PDF is generated from `Morfeas_WEB/docs/manual/`.
- Detailed application structure and backend notes are in `Morfeas_WEB/README.md`.
