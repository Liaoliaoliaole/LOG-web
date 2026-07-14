# LOG WEB v2

This folder contains the v2 web UI and PHP backend for LOG(Morfeas) devices. The frontend assets live at the top level, and the backend PHP endpoints are in `backend/`.
The active product is `Morfeas_WEB/`; archived legacy web folders are read-only references for behavior comparison.

## Structure

```
Morfeas_WEB/
├─ assets/               # Shared static assets loaded on every page
│  ├─ api/               # Frontend API wrappers (one module per backend endpoint)
│  │  ├─ channels.js
│  │  ├─ devices.js
│  │  ├─ ftpBackup.js
│  │  ├─ networkConfig.js
│  │  ├─ systemPower.js
│  │  ├─ systemStatus.js
│  │  └─ systemUpdate.js
│  ├─ services/          # Shared data helpers
│  │  ├─ isoCatalog.js   # ISO standard XML loader + localStorage persistence
│  │  └─ searchPool.js   # Channel search index builder
│  └─ ui/
│     └─ systemStatusFormatter.js  # Ticker + status value formatters
├─ backend/              # PHP API endpoints and supporting layers
│  ├─ api_calibration.php     # SDAQ calibration read/write
│  ├─ api_channels.php        # Channel table + ISO XML CRUD + ISO standard upload
│  ├─ api_devices.php         # Device list + LOG config CRUD
│  ├─ api_ftp_backup.php      # FTP backup config, test, backup, restore, log upload
│  ├─ api_network_config.php  # Network apply/confirm/rollback
│  ├─ api_system_power.php    # Reboot / shutdown
│  ├─ api_system_status.php   # System details, logger files, journal
│  ├─ api_system_update.php   # Update check + apply (via update.sh)
│  ├─ api_system_version.php  # Git branch/commit info for About page
│  ├─ cli/
│  │  ├─ ftp_backup_cli.php        # CLI wrapper for FTP backup (used by backup.sh cron)
│  │  └─ network_pending_watcher.php  # Auto-rollback watcher for staged network changes
│  ├─ core/
│  │  ├─ logstat_iobox.php   # IOBOX logstat parser
│  │  ├─ logstat_mti.php     # MTI logstat parser
│  │  ├─ logstat_nox.php     # NOX logstat parser
│  │  ├─ logstat_sdaq.php    # SDAQ logstat parser
│  │  ├─ opcua_config.php    # OPC UA XML read/write (ISO channel CRUD)
│  │  ├─ paths.php           # Hardcoded production paths (ramdisk, config, ISO dir)
│  │  ├─ request.php         # JSON body parsing, request ID, error logging helpers
│  │  ├─ sdaq_type_cache.php # SDAQ type cache (ramdisk-backed)
│  │  └─ system_info.php     # MAC address, CAN bitrates, NTP server helpers
│  ├─ repositories/
│  │  ├─ iso_repository.php        # ISO standard file discovery and upload helpers
│  │  ├─ log_config_repository.php # Morfeas_config.xml device read/write
│  │  └─ logstat_repository.php    # Logstat JSON file path collection + loading
│  └─ services/
│     ├─ channel_service.php       # Channel row aggregation (OPC UA + logstat merge)
│     ├─ device_service.php        # Device list aggregation + runtime status merge
│     ├─ ftp_backup_service.php    # FTP config, backup, restore, log upload logic
│     ├─ network_service.php       # Network state read + staged apply + rollback
│     └─ system_status_service.php # System details, logger listing, journal, export
├─ docs/
│  └─ help_manual.pdf    # End-user help manual
├─ index.html            # ISO Channels Linker (main page)
├─ linker-table/         # Popups opened from the channel table
│  ├─ calibration.html / calibration.js    # Manual SDAQ calibration editor (legacy-style save semantics)
│  ├─ edit_channel.html / editCh.js        # ISO channel edit popup
│  ├─ sdaq_scale.html / sdaq_scale.js      # Separate SDAQ-I/U scale popup (2-point auto-coefficient flow)
│  └─ replace_tc16.html / replace_tc16.js # TC16 bulk SDAQ replacement popup
├─ menu/                 # Menu popup pages
│  ├─ about/             # System version info
│  ├─ add-devices/       # Add / remove IOBOX, MTI, NOX devices
│  ├─ advanced-settings/ # Advanced configuration options
│  ├─ backup-restore/    # FTP backup and restore UI
│  ├─ network-config/    # Network configuration UI
│  ├─ system-control/    # System update, reboot, shutdown
│  └─ system-status/     # System status, logger viewer, journal
└─ tool-bar/             # Toolbar popups opened from the main page
   ├─ add_channel.html / addCh.js   # Add ISO channel popup
   ├─ device_search.html / device_search.js  # Device anchor search popup
   └─ import_channel.html           # Channel import (JSON) popup
```
