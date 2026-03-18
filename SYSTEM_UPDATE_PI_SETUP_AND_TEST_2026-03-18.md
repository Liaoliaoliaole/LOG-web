# New Morfeas System Update: Pi Setup + Manual Test

## A. Deploy New Web Code to Pi (you do this first)
Copy these changed files to Pi web path (`/var/www/html/morfeas_web/Morfeas_WEB`):

- `assets/config.js`
- `assets/app.css`
- `assets/index.js`
- `assets/api/systemUpdate.js` (new)
- `backend/api_system_update.php` (new)
- `index.html`
- `menu/system-control/update.html`
- `menu/system-control/system_update.js` (new)

## B. Pi Setup (run on Pi after deploy)

### 1) Sudoers for update script
```bash
sudo tee /etc/sudoers.d/Morfeas_update_allow >/dev/null <<'EOF'
Cmnd_Alias MORFEAS_UPDATE_CMDS = /var/www/html/morfeas_web/update.sh, \
                                 /bin/systemctl restart apache2, \
                                 /bin/systemctl restart Morfeas_system, \
                                 /bin/systemctl restart Morfeas_system.service, \
                                 /usr/bin/make, \
                                 /bin/rm
www-data ALL=(root) NOPASSWD: MORFEAS_UPDATE_CMDS
EOF
sudo chmod 440 /etc/sudoers.d/Morfeas_update_allow
sudo visudo -cf /etc/sudoers.d/Morfeas_update_allow
```

### 2) Git safe.directory for root
```bash
sudo git config --global --add safe.directory /var/www/html/morfeas_web
sudo git config --global --add safe.directory /opt/Morfeas_project/Morfeas_core
```

### 3) Cron wrapper and daily flag check
```bash
sudo chmod +x /var/www/html/morfeas_web/cron/update_cron_wrapper.sh
sudo crontab -l | cat
```
Expected root cron contains daily check entry for `update_cron_wrapper.sh` (legacy parity).

### 4) Apache must see real /tmp
```bash
sudo systemctl cat apache2 | grep -i PrivateTmp
```
Expected: `PrivateTmp=false` effective for apache2.

If changed, reload/restart:
```bash
sudo systemctl daemon-reload
sudo systemctl restart apache2
```

### 5) Endpoint smoke test
```bash
curl -s "http://127.0.0.1/morfeas_web/backend/api_system_update.php?action=status"
```
Expected: JSON with `ok: true` and `data.update_needed`.

## C. Manual Test Checklist

### 1) Status + red dot
```bash
sudo rm -f /tmp/update_needed
curl -s "http://127.0.0.1/morfeas_web/backend/api_system_update.php?action=status"
sudo touch /tmp/update_needed
curl -s "http://127.0.0.1/morfeas_web/backend/api_system_update.php?action=status"
```
Expected:
- `update_needed=false` then `true`
- Main menu System Update red dot follows (initial load + within 60s)

### 2) Check flow
- Open `System Update` popup.
- Verify first message: `Checking for updates...`
- Run `Check Again`.
- Validate each case:
  - up-to-date
  - update available
  - network/git failure (if simulated)

### 3) Update flow
- Click `Update Later`: no system side effects.
- Click `Update Now`: one confirmation, then progress message.
- If connection drops during restart, page should recover with polling (up to 120s).
- After recovery, message instructs refresh (`Ctrl+F5`).

### 4) Regression
- Reboot page works.
- Shutdown page works.
- Version page still works.
- Network config still works.
- Main index table still refreshes.

## D. Pass Criteria
- Backend endpoint reachable and returns normalized JSON.
- Red-dot indicator works from `/tmp/update_needed`.
- Check and update flows behave as designed.
- No regression on reboot/shutdown/network/version.
