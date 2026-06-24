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

  // Folder browser elements
  const browseBtn = $('#browseBtn');
  const folderBrowser = $('#folderBrowser');
  const fbBackBtn = $('#fbBackBtn');
  const fbCloseBtn = $('#fbCloseBtn');
  const fbPathEl = $('#fbPath');
  const fbStatus = $('#fbStatus');
  const fbList = $('#fbList');

  if (!hostInput || !engineInput || !connectBtn || !disconnectBtn || !backupBtn || !restoreBtn || !list || !ftpMsg || !bakMsg || !resMsg) {
    return;
  }

  let connected = false;
  let configured = false;
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

  const setConnectedUI = (on, hasConfig = on) => {
    connected = !!on;
    configured = !!hasConfig;

    hostInput.disabled = connected;
    engineInput.disabled = connected;
    connectBtn.disabled = connected || actionBusy;
    disconnectBtn.disabled = !configured || actionBusy;
    backupBtn.disabled = !connected || actionBusy;
    restoreBtn.disabled = !connected || actionBusy;

    [hostInput, engineInput].forEach((el) => {
      el.style.background = el.disabled ? 'var(--bg-weak)' : '';
    });
  };

  const setBusy = (busy) => {
    actionBusy = !!busy;
    setConnectedUI(connected, configured);
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
    configured = false;
    setConnectedUI(false, false);
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

      if (!config) {
        if (connected || configured) {
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
      setConnectedUI(Boolean(data.connected), true);

      if (!data.connected) {
        fillList([]);
        const message = data?.health?.message || 'FTP is configured but not currently reachable.';
        if (announce || changed) {
          setMsg(ftpMsg, `FTP configured but offline: ${message}`, 'err');
        }
        return;
      }

      if (changed) {
        await listBackups(false);
        if (announce) {
          setMsg(ftpMsg, `Configuration updated in another session. Engine: "${config.dir}".`, 'ok');
        }
      }
    } catch (err) {
      setConnectedUI(false, configured);
      fillList([]);
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
      setConnectedUI(true, true);
      setMsg(ftpMsg, `Connected to FTP at ${input.host}. Configuration saved.`, 'ok');
      await listBackups(true);
    } catch (err) {
      configSignature = `${input.host}|${input.dir}|`;
      setConnectedUI(false, true);
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

  // ---- Folder Browser ----
  let fbHistory = [];
  let fbCurrentPath = '/';

  const fbLoadDir = async (path) => {
    const host = hostInput.value.trim();
    if (!host || !IPV4_REGEX.test(host)) {
      setMsg(ftpMsg, 'Enter a valid Host IP before browsing folders.', 'err');
      return;
    }
    if (!api) return;

    fbStatus.textContent = 'Loading\u2026';
    fbList.innerHTML = '';
    fbPathEl.textContent = path || '/';
    fbBackBtn.disabled = fbHistory.length === 0;

    try {
      const payload = await api.listDirs(host, path);
      const dirs = Array.isArray(payload?.data?.dirs) ? payload.data.dirs : [];
      fbStatus.textContent = '';

      if (dirs.length === 0) {
        const li = document.createElement('li');
        li.className = 'fb-empty';
        li.textContent = 'No sub-folders found.';
        fbList.appendChild(li);
        return;
      }

      dirs.forEach((name) => {
        const li = document.createElement('li');
        li.className = 'fb-item';

        const nameSpan = document.createElement('span');
        nameSpan.className = 'fb-name';
        nameSpan.textContent = '\uD83D\uDCC1 ' + name;
        nameSpan.title = 'Click to connect with this engine number';
        nameSpan.addEventListener('click', async () => {
          folderBrowser.hidden = true;
          // Force inputs back to editable state so connectFtp() can read them
          connected = false;
          configured = false;
          setConnectedUI(false, false);
          engineInput.value = name;
          await connectFtp();
        });

        const enterBtn = document.createElement('button');
        enterBtn.className = 'btn';
        enterBtn.textContent = '\u25B6';
        enterBtn.title = 'Browse into this folder';
        enterBtn.style.padding = '2px 8px';
        enterBtn.addEventListener('click', async () => {
          const newPath = (path === '/' ? '' : path) + '/' + name;
          fbHistory.push(path);
          fbCurrentPath = newPath;
          await fbLoadDir(newPath);
        });

        li.appendChild(nameSpan);
        li.appendChild(enterBtn);
        fbList.appendChild(li);
      });
    } catch (err) {
      fbStatus.textContent = 'Error: ' + (err.message || String(err));
      fbStatus.className = 'status err';
    }
  };

  if (browseBtn) {
    browseBtn.addEventListener('click', async () => {
      folderBrowser.hidden = false;
      fbHistory = [];
      fbCurrentPath = '/';
      fbStatus.className = 'status';
      await fbLoadDir('/');
    });
  }

  if (fbBackBtn) {
    fbBackBtn.addEventListener('click', async () => {
      if (fbHistory.length === 0) return;
      const prev = fbHistory.pop();
      fbCurrentPath = prev;
      await fbLoadDir(prev);
    });
  }

  if (fbCloseBtn) {
    fbCloseBtn.addEventListener('click', () => {
      folderBrowser.hidden = true;
    });
  }

  ensureDefaultHost();
  setConnectedUI(false, false);
  syncConfig(false);
  syncTimer = window.setInterval(() => syncConfig(true), 2000);
  window.addEventListener('beforeunload', () => {
    if (syncTimer) clearInterval(syncTimer);
  });
})();
