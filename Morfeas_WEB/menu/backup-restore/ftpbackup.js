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

  // Restore impact report
  const restoreReport = $('#restoreReport');
  const restoreWarningList = $('#restoreWarningList');
  const restoreChannelsBody = $('#restoreChannelsBody');
  const restoreHandlersBody = $('#restoreHandlersBody');
  const restoreConfirmBtn = $('#restoreConfirmBtn');
  const restoreCancelBtn = $('#restoreCancelBtn');
  const restoreResult = $('#restoreResult');
  const pillChAdd = $('#pillChAdd');
  const pillChReplace = $('#pillChReplace');
  const pillChUnchanged = $('#pillChUnchanged');
  const pillChRemove = $('#pillChRemove');
  const pillHAdd = $('#pillHAdd');
  const pillHReplace = $('#pillHReplace');
  const pillHUnchanged = $('#pillHUnchanged');
  const pillHRemove = $('#pillHRemove');

  // Folder browser elements
  const browseBtn = $('#browseBtn');
  const folderBrowser = $('#folderBrowser');
  const fbBackBtn = $('#fbBackBtn');
  const fbCloseBtn = $('#fbCloseBtn');
  const fbPathEl = $('#fbPath');
  const fbStatus = $('#fbStatus');
  const fbList = $('#fbList');

  if (!hostInput || !engineInput || !connectBtn || !disconnectBtn || !backupBtn || !restoreBtn || !list || !ftpMsg || !bakMsg || !resMsg
    || !restoreReport || !restoreWarningList || !restoreChannelsBody || !restoreHandlersBody || !restoreConfirmBtn || !restoreCancelBtn || !restoreResult
    || !pillChAdd || !pillChReplace || !pillChUnchanged || !pillChRemove || !pillHAdd || !pillHReplace || !pillHUnchanged || !pillHRemove) {
    return;
  }

  let connected = false;
  let configured = false;
  let actionBusy = false;
  let configSignature = '';
  let syncTimer = null;

  // Set once a preflight the operator has not yet acted on is showing;
  // cleared on Confirm, Cancel, a fresh preflight, or leaving the page --
  // so a stale approved-looking report can never be committed against a
  // file selection that has since changed.
  let restoreState = null; // { file, digest, localConfigDigest, warningsCount }

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]));

  const resultRowClass = (result) => {
    switch (result) {
      case 'Add': return 'result-add';
      case 'Replace': return 'result-replace';
      case 'Unchanged': return 'result-unchanged';
      case 'Remove': return 'result-remove';
      default: return '';
    }
  };

  const resetRestoreReport = () => {
    restoreState = null;
    restoreReport.classList.add('hidden');
    restoreWarningList.classList.add('hidden');
    restoreWarningList.innerHTML = '';
    restoreChannelsBody.innerHTML = '';
    restoreHandlersBody.innerHTML = '';
  };

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
    restoreConfirmBtn.disabled = !connected || actionBusy;
    restoreCancelBtn.disabled = actionBusy;
    // Selecting a different file while a Preflight request is in flight
    // must not silently attach that request's report to the new selection.
    list.disabled = actionBusy;

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
    resetRestoreReport();
    setMsg(restoreResult, '');
    restoreResult.classList.add('hidden');
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

  // FTP Restore replaces both config files; its preflight is file-level.
  const describePreflightErrors = (side, label) => {
    if (side.valid) return [];
    return side.errors.map((e) => `  [${label}] ${e.code}: ${e.message}`);
  };

  // Field labels for the "Changes" column -- shared by channel and handler
  // rows, since ftp_backup_channel_fields_changed()/
  // ftp_backup_handler_fields_changed() both name fields by these same keys.
  const FIELD_LABELS = {
    interface_type: 'Interface', anchor: 'Anchor', description: 'Description', min: 'Min', max: 'Max',
    unit: 'Unit', cal_date: 'Cal date', cal_period: 'Cal period',
    alarm_high: 'Alarm high', alarm_low: 'Alarm low', alarm_high_val: 'Alarm high value', alarm_low_val: 'Alarm low value',
    name: 'Name', ip: 'IP', bus: 'CAN bus', i2c_bus_num: 'I2C bus', disabled: 'Status',
  };

  const formatFieldValue = (field, value) => {
    if (field === 'disabled') return value ? 'Disabled' : 'Enabled';
    return (value === null || value === undefined || value === '') ? '(empty)' : String(value);
  };

  // A Replace row only carries the bundle's final values at its top level;
  // `before` (added by the backend for Replace rows only) holds what is
  // there now. Without spelling out "field: before -> after", a Replace
  // verdict on an unchanged-looking ISO_CHANNEL/anchor/name gives the
  // operator no way to tell what is actually about to change.
  const describeChanges = (row) => {
    if (row.result !== 'Replace' || !Array.isArray(row.changed_fields) || !row.changed_fields.length || !row.before) {
      return '';
    }
    return row.changed_fields.map((f) => {
      const label = FIELD_LABELS[f] || f;
      const before = formatFieldValue(f, row.before[f]);
      const after = formatFieldValue(f, row[f]);
      return `${label}: ${before} -> ${after}`;
    }).join('; ');
  };

  const isDisableFlip = (row) => row.result === 'Replace'
    && Array.isArray(row.changed_fields) && row.changed_fields.includes('disabled')
    && row.disabled === true;

  const renderImpactRows = (tbody, rows, columns, opts = {}) => {
    tbody.innerHTML = (rows || []).map((r) => {
      const extraClass = opts.disableFlipClass && isDisableFlip(r) ? ' disable-flip' : '';
      return `
      <tr class="${resultRowClass(r.result)}${extraClass}">
        ${columns.map((c) => `<td class="${c.cls || ''}">${escapeHtml(c.render(r))}</td>`).join('')}
        <td class="result">${escapeHtml(r.result)}</td>
      </tr>
    `;
    }).join('');
  };

  // Renders the preflight preview: what THIS Restore would Add/Replace/
  // Unchanged/Remove, for both ISO channels and IOBOX/MTI/NOX device
  // handlers -- so the operator sees which channel replaces which, and
  // which channels/handlers disappear, before committing to anything.
  const renderRestoreReport = (data) => {
    const impact = data.impact || { channels: [], channel_summary: {}, handlers: [], handler_summary: {} };
    const chSum = impact.channel_summary || {};
    const hSum = impact.handler_summary || {};

    pillChAdd.textContent = `Channels add: ${chSum.add || 0}`;
    pillChReplace.textContent = `Channels replace: ${chSum.replace || 0}`;
    pillChUnchanged.textContent = `Channels unchanged: ${chSum.unchanged || 0}`;
    pillChRemove.textContent = `Channels remove: ${chSum.remove || 0}`;
    pillHAdd.textContent = `Handlers add: ${hSum.add || 0}`;
    pillHReplace.textContent = `Handlers replace: ${hSum.replace || 0}`;
    pillHUnchanged.textContent = `Handlers unchanged: ${hSum.unchanged || 0}`;
    pillHRemove.textContent = `Handlers remove: ${hSum.remove || 0}`;

    renderImpactRows(restoreChannelsBody, impact.channels, [
      { render: (r) => r.iso_channel },
      { render: (r) => r.interface_type },
      { render: (r) => r.anchor },
      { render: describeChanges, cls: 'changes' },
    ]);
    renderImpactRows(restoreHandlersBody, impact.handlers, [
      { render: (r) => r.type },
      { render: (r) => r.name },
      { render: (r) => (r.type === 'NOX' || r.type === 'SDAQ' ? r.bus : r.ip) },
      { render: (r) => (r.disabled ? 'Disabled' : 'Enabled'), cls: 'status' },
      { render: describeChanges, cls: 'changes' },
    ], { disableFlipClass: true });

    // These warnings are about the BUNDLE'S OWN internal consistency (a
    // channel in it referencing a device handler that is not also in it) --
    // a different question from the Add/Replace/Remove tables above, which
    // compare the bundle against what is on THIS machine right now. This
    // check only inspects configuration; it does not verify the device is
    // physically connected -- a handler that legitimately targets a
    // currently-offline device is not itself a problem.
    const warnings = Array.isArray(data.warnings) ? data.warnings : [];
    if (warnings.length) {
      const shown = warnings.slice(0, 20).map((w) => `<li>${escapeHtml(w.message)}</li>`).join('');
      const more = warnings.length > 20 ? `<li>...and ${warnings.length - 20} more</li>` : '';
      restoreWarningList.innerHTML = `<strong>${warnings.length} warning(s) in this backup</strong> `
        + `(these channels will not come online unless a matching handler exists and is enabled in the restored configuration):`
        + `<ul>${shown}${more}</ul>`;
      restoreWarningList.classList.remove('hidden');
    } else {
      restoreWarningList.classList.add('hidden');
      restoreWarningList.innerHTML = '';
    }

    restoreReport.classList.remove('hidden');
  };

  const runRestorePreflight = async () => {
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

    resetRestoreReport();
    setMsg(restoreResult, '');
    restoreResult.classList.add('hidden');
    setBusy(true);

    let preflight;
    try {
      setMsg(resMsg, 'Checking backup...');
      preflight = await api.restorePreflight(file);
    } catch (err) {
      setMsg(resMsg, `Preflight failed: ${err.message || err}`, 'err');
      setBusy(false);
      return;
    }

    const data = preflight?.data;
    if (!data || !data.can_commit) {
      const errorLines = [
        ...describePreflightErrors(data?.opc_ua || { valid: false, errors: [] }, 'OPC_UA_Config.xml'),
        ...describePreflightErrors(data?.morfeas || { valid: false, errors: [] }, 'Morfeas_Config.xml'),
      ];
      setMsg(resMsg, `Backup failed validation, nothing was changed:\n${errorLines.join('\n') || 'Unknown validation error'}`, 'err');
      setBusy(false);
      return;
    }

    restoreState = {
      file,
      digest: data.digest,
      localConfigDigest: data.local_config_digest,
      warningsCount: Array.isArray(data.warnings) ? data.warnings.length : 0,
    };
    renderRestoreReport(data);
    setMsg(resMsg, `Preflight passed for "${file}". Review the impact below, then Confirm Restore.`, 'ok');
    setBusy(false);
  };

  const cancelRestorePreview = () => {
    resetRestoreReport();
    setMsg(resMsg, 'Restore cancelled.', '');
  };

  const confirmRestoreCommit = async () => {
    if (actionBusy || !restoreState) return;
    if (!api) {
      setMsg(resMsg, 'FTP backup API unavailable.', 'err');
      return;
    }

    const { file, digest, localConfigDigest, warningsCount } = restoreState;
    const warningSuffix = warningsCount
      ? `\n\nThis backup has ${warningsCount} warning(s) shown above. Confirming explicitly accepts them.`
      : '';
    const confirmed = window.confirm(
      `This will REPLACE the entire current configuration (all ISO channels and device handlers) `
      + `with the contents of "${file}", as shown in the impact report above. `
      + `This cannot be undone from this dialog.${warningSuffix}\n\nContinue?`
    );
    if (!confirmed) {
      setMsg(resMsg, 'Restore cancelled.', '');
      return;
    }

    setBusy(true);
    try {
      setMsg(resMsg, 'Restoring backup...');
      // The operator has now seen and accepted the warnings above; the
      // backend requires this to be explicit rather than assumed.
      const payload = await api.restoreCommit(file, digest, localConfigDigest, warningsCount > 0);
      const impact = payload?.data?.impact || {};
      const chSum = impact.channel_summary || {};
      const hSum = impact.handler_summary || {};
      // These counts are recomputed by the server under lock, immediately
      // before the successful write -- not the preview numbers the browser
      // was holding -- so this line is the actual result, not a repeat of
      // the preflight preview.
      setMsg(
        restoreResult,
        `Restored from "${file}". Channels: ${chSum.add || 0} added, ${chSum.replace || 0} replaced, `
        + `${chSum.unchanged || 0} unchanged, ${chSum.remove || 0} removed. `
        + `Handlers: ${hSum.add || 0} added, ${hSum.replace || 0} replaced, ${hSum.unchanged || 0} unchanged, ${hSum.remove || 0} removed.`,
        'ok'
      );
      restoreResult.classList.remove('hidden');
      resetRestoreReport();
      setMsg(resMsg, '');
    } catch (err) {
      setMsg(resMsg, `Restore failed: ${err.message || err}`, 'err');
    } finally {
      setBusy(false);
    }
  };

  connectBtn.addEventListener('click', connectFtp);
  disconnectBtn.addEventListener('click', disconnectFtp);
  backupBtn.addEventListener('click', backupNow);
  restoreBtn.addEventListener('click', runRestorePreflight);
  restoreConfirmBtn.addEventListener('click', confirmRestoreCommit);
  restoreCancelBtn.addEventListener('click', cancelRestorePreview);
  // A stale preflight report must never be committed against a file
  // selection that has since changed underneath it.
  list.addEventListener('change', () => {
    if (restoreState) {
      resetRestoreReport();
      setMsg(resMsg, 'Backup selection changed -- run Preflight again.', '');
    }
  });

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
