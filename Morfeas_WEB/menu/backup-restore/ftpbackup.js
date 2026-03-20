(() => {
  const DEFAULT_HOST_IP = '10.193.135.70';
  const ENGINE_REGEX = /^[A-Za-z0-9_.-]+$/;
  const IPV4_REGEX = /^(25[0-5]|2[0-4]\d|1?\d?\d)\.(25[0-5]|2[0-4]\d|1?\d?\d)\.(25[0-5]|2[0-4]\d|1?\d?\d)\.(25[0-5]|2[0-4]\d|1?\d?\d)$/;

  const $ = (s, r = document) => r.querySelector(s);

  const api = window.LOG_WEB?.api?.ftpBackup;

  const hostInput = $('#ftpHost');
  const engineInput = $('#engineNo');
  const connectBtn = $('#connectBtn');
  const disconnectBtn = $('#disconnectBtn');
  const backupBtn = $('#backupBtn');
  const restoreBtn = $('#restoreBtn');
  const list = $('#backupList');
  const ftpMsg = $('#ftpStatus');
  const bakMsg = $('#backupStatus');
  const resMsg = $('#restoreStatus');

  if (!hostInput || !engineInput || !connectBtn || !disconnectBtn || !backupBtn || !restoreBtn || !list || !ftpMsg || !bakMsg || !resMsg) {
    return;
  }

  let connected = false;
  let actionBusy = false;
  let configSignature = '';
  let syncTimer = null;

  const clearStatusClass = (el) => {
    el.classList.remove('ok', 'err');
  };

  const setMsg = (el, msg, type = '') => {
    el.textContent = msg || '';
    clearStatusClass(el);
    if (type) el.classList.add(type);
  };

  const setConnectedUI = (on) => {
    connected = !!on;

    hostInput.disabled = connected;
    engineInput.disabled = connected;
    connectBtn.disabled = connected || actionBusy;
    disconnectBtn.disabled = !connected || actionBusy;
    backupBtn.disabled = !connected || actionBusy;
    restoreBtn.disabled = !connected || actionBusy;

    [hostInput, engineInput].forEach((el) => {
      el.style.background = el.disabled ? 'var(--bg-weak)' : '';
    });
  };

  const setBusy = (busy) => {
    actionBusy = !!busy;
    setConnectedUI(connected);
  };

  const ensureDefaultHost = () => {
    const host = hostInput.value.trim();
    if (host === '') {
      hostInput.value = DEFAULT_HOST_IP;
    }
  };

  const toConfigSignature = (config) => {
    if (!config || typeof config !== 'object') return '';
    return `${config.host || ''}|${config.dir || ''}|${config.log || ''}`;
  };

  const applyConfigToUi = (config) => {
    if (!config || typeof config !== 'object') return;
    hostInput.value = config.host || DEFAULT_HOST_IP;
    engineInput.value = config.dir || '';
  };

  const fillList = (files) => {
    const selected = list.value;
    list.innerHTML = '';
    (files || []).forEach((file) => {
      const opt = document.createElement('option');
      opt.value = file;
      opt.textContent = file;
      if (file === selected) {
        opt.selected = true;
      }
      list.appendChild(opt);
    });
  };

  const validateConnectInput = () => {
    const host = hostInput.value.trim();
    const dir = engineInput.value.trim();

    if (!host || !dir) {
      setMsg(ftpMsg, 'Please enter both Host IP and Engine Number.', 'err');
      return null;
    }
    if (!IPV4_REGEX.test(host)) {
      setMsg(ftpMsg, 'Host IP must be a valid IPv4 address.', 'err');
      return null;
    }
    if (!ENGINE_REGEX.test(dir)) {
      setMsg(ftpMsg, 'Engine Number allows only letters, numbers, ".", "_" and "-".', 'err');
      return null;
    }

    return { host, dir };
  };

  const listBackups = async (showStatus = true) => {
    if (!api) throw new Error('FTP backup API unavailable');
    if (showStatus) {
      setMsg(resMsg, 'Loading backups...');
    }

    const payload = await api.list();
    const files = Array.isArray(payload?.data?.files) ? payload.data.files : [];
    fillList(files);

    if (showStatus) {
      const dir = engineInput.value.trim();
      if (files.length === 0) {
        setMsg(resMsg, `No backup files found in engine number directory: "${dir}".`, 'ok');
      } else {
        setMsg(resMsg, `Found ${files.length} backup file(s) in engine number directory: "${dir}".`, 'ok');
      }
    }
  };

  const disconnectUi = () => {
    connected = false;
    setConnectedUI(false);
    fillList([]);
    ensureDefaultHost();
    setMsg(ftpMsg, 'Disconnected. Configuration removed. Automatic backups disabled.', 'ok');
    setMsg(bakMsg, '');
    setMsg(resMsg, '');
    configSignature = '';
  };

  const syncConfig = async (announce = false) => {
    if (!api || actionBusy) return;

    try {
      const payload = await api.configIfUpdated();
      const data = payload?.data || {};
      const config = data.config || null;

      if (!data.connected || !config) {
        if (connected) {
          disconnectUi();
        } else {
          ensureDefaultHost();
        }
        return;
      }

      const newSig = toConfigSignature(config);
      const changed = newSig !== configSignature;
      configSignature = newSig;

      applyConfigToUi(config);
      setConnectedUI(true);

      if (changed) {
        await listBackups(false);
        if (announce) {
          setMsg(ftpMsg, `Configuration updated in another session. Engine: "${config.dir}".`, 'ok');
        }
      }
    } catch (err) {
      if (announce) {
        setMsg(ftpMsg, `Unable to check latest FTP config status: ${err.message || err}`, 'err');
      }
    }
  };

  const connectFtp = async () => {
    if (actionBusy) return;
    const input = validateConnectInput();
    if (!input) return;
    if (!api) {
      setMsg(ftpMsg, 'FTP backup API unavailable.', 'err');
      return;
    }

    setBusy(true);
    try {
      setMsg(ftpMsg, 'Saving FTP configuration...');
      await api.saveConfig(input.host, input.dir);
      setMsg(ftpMsg, 'Connecting to FTP server...');
      await api.testConnect();

      configSignature = `${input.host}|${input.dir}|`;
      setConnectedUI(true);
      setMsg(ftpMsg, `Connected to FTP at ${input.host}. Configuration saved.`, 'ok');
      await listBackups(true);
    } catch (err) {
      setMsg(ftpMsg, `Connection failed: ${err.message || err}`, 'err');
    } finally {
      setBusy(false);
    }
  };

  const disconnectFtp = async () => {
    if (actionBusy) return;
    if (!api) {
      setMsg(ftpMsg, 'FTP backup API unavailable.', 'err');
      return;
    }

    setBusy(true);
    try {
      setMsg(ftpMsg, 'Disconnecting...');
      await api.clearConfig();
      disconnectUi();
    } catch (err) {
      setMsg(ftpMsg, `Disconnect failed: ${err.message || err}`, 'err');
    } finally {
      setBusy(false);
    }
  };

  const backupNow = async () => {
    if (actionBusy || !connected) return;
    if (!api) {
      setMsg(bakMsg, 'FTP backup API unavailable.', 'err');
      return;
    }

    setBusy(true);
    try {
      setMsg(bakMsg, 'Creating and uploading backup...');
      const payload = await api.backup();
      setMsg(bakMsg, payload?.message || 'Backup complete', 'ok');
      await listBackups(true);
    } catch (err) {
      setMsg(bakMsg, `Backup failed: ${err.message || err}`, 'err');
    } finally {
      setBusy(false);
    }
  };

  const restoreSelected = async () => {
    if (actionBusy || !connected) return;
    if (!api) {
      setMsg(resMsg, 'FTP backup API unavailable.', 'err');
      return;
    }

    const file = list.value;
    if (!file) {
      alert('Please select a backup file.');
      return;
    }

    setBusy(true);
    try {
      setMsg(resMsg, 'Restoring backup...');
      const payload = await api.restore(file);
      setMsg(resMsg, payload?.message || `Restored from: ${file}`, 'ok');
    } catch (err) {
      setMsg(resMsg, `Restore failed: ${err.message || err}`, 'err');
    } finally {
      setBusy(false);
    }
  };

  connectBtn.addEventListener('click', connectFtp);
  disconnectBtn.addEventListener('click', disconnectFtp);
  backupBtn.addEventListener('click', backupNow);
  restoreBtn.addEventListener('click', restoreSelected);

  ensureDefaultHost();
  setConnectedUI(false);
  syncConfig(false);
  syncTimer = window.setInterval(() => syncConfig(true), 2000);
  window.addEventListener('beforeunload', () => {
    if (syncTimer) clearInterval(syncTimer);
  });
})();
