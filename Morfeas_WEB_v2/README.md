# Morfeas_WEB_v2

This folder contains the v2 web UI and PHP backend for Morfeas/LOG devices. The frontend assets live at the top level, and the backend PHP endpoints are in `backend/`.

## Structure

```
Morfeas_WEB_v2/
├─ assets/               # Static assets
│  ├─ api/               # Frontend API wrappers (channels/devices/status)
│  ├─ services/          # Frontend data helpers (ISO catalog, search pool)
│  └─ ui/                # Shared UI helpers/formatters
├─ backend/              # PHP API endpoints and core helpers
│  ├─ api_channels.php   # Channel table + ISO XML CRUD
│  ├─ api_devices.php    # Device list + LOG config CRUD
│  ├─ api_system_status.php
│  ├─ core/
│  │  ├─ opcua_config.php
│  │  ├─ logstat_*.php
│  │  └─ paths.php        # Centralized paths/env overrides
│  ├─ repositories/       # File and config IO helpers
│  ├─ services/           # Aggregation and business logic
│  └─ config_sandbox/     # Mock data (XML + logstat JSON)
├─ index.html
├─ linker-table/
├─ menu/
└─ tool-bar/
```

### Frontend modules

- `assets/config.js` defines `LOG_WEB.config` (base path + endpoint resolver).
- `assets/api/` holds page-agnostic API wrappers used by index + popups.
- `assets/services/` provides shared ISO catalog and search pool loaders.
- `assets/ui/` contains cross-page formatters (system status, ticker values).

## Environment configuration

`backend/core/paths.php` centralizes paths so they are not scattered across endpoints. Use environment variables to override defaults:

| Variable | Default | Purpose |
| --- | --- | --- |
| `LOG_WEB_SANDBOX_DIR` | `backend/config_sandbox/` | Mock data directory for the sandbox builds.
| `LOG_WEB_RAMDISK_DIR` | `/mnt/ramdisk/` | Live logstat data (LOG_core output).
| `LOG_WEB_ISO_STANDARD_DIR` | `/home/pi/Morfeas_config/iso_standards/` | ISO standard upload directory (real device path).
| `LOG_WEB_OPCUA_CONFIG_PATH` | `backend/config_sandbox/OPC_UA_Config.mock.xml` | Override the OPC UA config XML path (LOG_core output).
| `LOG_WEB_LOG_CONFIG_PATH` | `backend/config_sandbox/LOG_config.mock.xml` | Override the LOG config XML path (LOG_core output).

## Mock vs. real data (placeholders)

The following files are **mock** inputs today and should be replaced with real LOG_core paths in production:

- `backend/config_sandbox/OPC_UA_Config.mock.xml` (used by `api_channels.php`)
- `backend/config_sandbox/LOG_config.mock.xml` (used by `api_devices.php`)
- `backend/config_sandbox/logstat_*.json` (used by `api_*` endpoints)

If you switch to a real LOG_core deployment, update `LOG_WEB_SANDBOX_DIR` and/or adjust the backend to read from the actual LOG_core config locations.

## API quick view

- `GET backend/api_channels.php` → channel table + status
- `POST/PATCH/DELETE backend/api_channels.php` → ISO XML CRUD
- `GET backend/api_devices.php` → device list + component count
- `POST/DELETE backend/api_devices.php` → update LOG config XML
- `GET backend/api_system_status.php?action=details` → system status detail list

## Notes for LOG_core integration

- `api_channels.php` expects an OPC UA config XML compatible with LOG_core output.
- `api_devices.php` writes to `LOG_config.mock.xml` today; replace with LOG_core config path before production use.
- `api_system_status.php` reads `logstat_*.json` (LOG_core logstat output) and merges sandbox + ramdisk sources.
