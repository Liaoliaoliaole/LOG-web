/* ============================================================================
 * Add Devices
 * ----------------------------------------------------------------------------
 * - CAN Bus Setup is the main control surface for SDAQ / NOX role switching.
 * - Configured Devices shows explicit XML-backed devices.
 * - Detected Devices shows auto-discovered runtime devices.
 * - NOX creation goes through the CAN role transition flow instead of direct
 *   device append, so bitrate + handler changes stay together.
 * ========================================================================== */

(function () {
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

  const devicesApi = window.LOG_WEB?.api?.devices;
  const canRolesApi = window.LOG_WEB?.api?.canRoles;
  const DEVICES_POLL_INTERVAL_MS = 1000;

  const canBody = $('#canBody');
  const canChip = $('#canChip');
  const canTicker = $('#canTicker');
  const legacyBanner = $('#legacyBanner');
  const legacyBannerText = $('#legacyBannerText');

  const configuredBody = $('#configuredBody');
  const detectedBody = $('#detectedBody');
  const mchk = $('#mchk');
  const refreshBtn = $('#refreshBtn');
  const removeBtn = $('#removeBtn');

  const propCard = $('#propCard');
  const addBtn = $('#addBtn');
  const cancelBtn = $('#cancelBtn');
  const saveBtn = $('#saveBtn');

  const devType = $('#devType');
  const devName = $('#devName');
  const devIp = $('#devIp');
  const devNameHint = $('#devNameHint');

  devIp.addEventListener('input', () => stripSpaces(devIp));
  devName.addEventListener('input', () => {
    stripSpaces(devName);
    if (devType.value === 'MTI') {
      // Silently drop any character that is illegal in a D-Bus interface name element.
      devName.value = devName.value.replace(/[^A-Za-z0-9_]/g, '');
    }
  });
  devName.addEventListener('change', () => validateDevName(devName));
  devIp.addEventListener('change', () => validateIp(devIp));

  let devices = [];
  let canRows = [];
  let canWarnings = { chip: null, ticker: [] };
  let legacyState = { blocking: false, message: '' };
  let devicesPollTimer = null;
  let removeInProgress = false;
  let transitionInProgress = false;
  let popupWindowHasFocus = document.hasFocus();
  const popupRegistry = new Map();

  const IP_REGEX = /^(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/;
  const DEV_NAME_REGEX = /^[A-Za-z0-9_-]{1,64}$/;
  // D-Bus interface name element rules (RFC / D-Bus spec):
  // only [A-Za-z0-9_], must not start with a digit.
  // D-Bus spec: interface names max 255 chars total. Prefix "Morfeas.MTI." is 12 chars,
  // leaving 243 chars for the element: first char [A-Za-z_], remainder [A-Za-z0-9_]{0,242}.
  const MTI_DBUS_ELEMENT_REGEX = /^[A-Za-z_][A-Za-z0-9_]{0,242}$/;

  function stripSpaces(el) {
    el.value = el.value.replace(/\s+/g, '');
  }

  function validateIp(el) {
    const val = el.value.trim();
    if (val && !IP_REGEX.test(val)) {
      el.value = '';
      alert('You have entered an invalid IP address!');
      return false;
    }
    return true;
  }

  function validateDevName(el) {
    const val = el.value.trim();
    if (!val) return true;
    if (devType.value === 'MTI') {
      if (!MTI_DBUS_ELEMENT_REGEX.test(val)) {
        el.value = '';
        alert(
          'MTI Device Name must satisfy D-Bus interface name element rules:\n' +
          '\u2022 Allowed characters: A\u2013Z  a\u2013z  0\u20139  _\n' +
          '\u2022 No hyphens or other special characters\n' +
          '\u2022 Must not start with a digit'
        );
        return false;
      }
      return true;
    }
    if (!DEV_NAME_REGEX.test(val)) {
      el.value = '';
      alert('DEV_NAME may contain only letters, numbers, "_" or "-"');
      return false;
    }
    return true;
  }

  function validateRequired() {
    if (!devName.value.trim() || !devIp.value.trim()) {
      alert('Nothing to commit!!!');
      return false;
    }

    if (!validateDevName(devName) || !validateIp(devIp)) {
      return false;
    }

    return true;
  }

  function isDuplicate(name, ip) {
    const conflict = devices.find((d) => {
      if ((d.origin || '') === 'auto') return false;
      if (d.type === 'NOX' || d.type === 'SDAQ') return false;
      if (name && d.name === name) return true;
      if (ip && d.ip === ip) return true;
      return false;
    });

    if (conflict) {
      if (conflict.name === name) {
        alert(`DEV_NAME: ${name} is in use!!!`);
      } else {
        alert(`IPv4_ADDR: ${ip} is in use!!!\n@ Handler with DEV_NAME:${conflict.name}`);
      }
      return true;
    }

    return false;
  }

  function setDisabled(el, disabled) {
    el.disabled = disabled;
    el.style.background = disabled ? 'var(--bg-weak)' : '';
  }

  function confirmServiceRestart(actionLabel) {
    return window.confirm(
      `${actionLabel} will restart LOG SERVICE and may need 10-60 seconds.\n\nContinue?`
    );
  }

  function openNoxPopup(bus) {
    const safeBus = String(bus || '').trim().toLowerCase();
    if (!safeBus) return;

    const url = new URL('../nox-config/nox_config.html', window.location.href);
    url.searchParams.set('bus', safeBus);

    const name = `nox_config_${safeBus}`;
    const existing = popupRegistry.get(name);
    if (existing && !existing.closed) {
      try {
        existing.location.href = url.toString();
        existing.focus();
        return;
      } catch (_) {
        popupRegistry.delete(name);
      }
    }

    const features = [
      'width=1380',
      'height=980',
      'left=80',
      'top=40',
      'resizable=yes',
      'scrollbars=yes',
      'toolbar=no',
      'location=no',
      'status=no',
      'menubar=no',
    ].join(',');

    const win = window.open(url.toString(), name, features);
    if (win) {
      popupRegistry.set(name, win);
      try { win.focus(); } catch (_) { }
    }
  }

  function openMtiPopup(name) {
    const safeName = String(name || '').trim();
    if (!safeName) return;

    const url = new URL('../mti-config/mti_config.html', window.location.href);
    url.searchParams.set('name', safeName);

    const popupName = `mti_config_${safeName.replace(/[^A-Za-z0-9_-]/g, '_')}`;
    const existing = popupRegistry.get(popupName);
    if (existing && !existing.closed) {
      try {
        existing.location.href = url.toString();
        existing.focus();
        return;
      } catch (_) {
        popupRegistry.delete(popupName);
      }
    }

    const features = [
      'width=1260',
      'height=860',
      'left=100',
      'top=60',
      'resizable=yes',
      'scrollbars=yes',
      'toolbar=no',
      'location=no',
      'status=no',
      'menubar=no',
    ].join(',');

    const win = window.open(url.toString(), popupName, features);
    if (win) {
      popupRegistry.set(popupName, win);
      try { win.focus(); } catch (_) { }
    }
  }

  function shouldPollDevices() {
    return !document.hidden && popupWindowHasFocus && !removeInProgress && !transitionInProgress;
  }

  function stopDevicesPoll() {
    if (devicesPollTimer) {
      clearInterval(devicesPollTimer);
      devicesPollTimer = null;
    }
  }

  function startDevicesPoll() {
    if (!shouldPollDevices()) return;
    stopDevicesPoll();
    devicesPollTimer = setInterval(() => {
      if (!shouldPollDevices()) return;
      loadPageData(true, true);
    }, DEVICES_POLL_INTERVAL_MS);
  }

  function syncDevicesPoll() {
    if (shouldPollDevices()) {
      startDevicesPoll();
    } else {
      stopDevicesPoll();
    }
  }

  function selectedConfiguredIds() {
    return new Set(
      $$('#configuredBody tr')
        .filter((r) => r.querySelector('input[type="checkbox"]')?.checked)
        .map((r) => r.dataset.id)
        .filter(Boolean)
    );
  }

  function renderStatusCell(primary, detail) {
    const wrapper = document.createElement('div');
    wrapper.className = 'status-text';

    const main = document.createElement('div');
    main.className = 'status-main';
    main.textContent = primary || 'Unknown';
    wrapper.appendChild(main);

    if (detail) {
      const sub = document.createElement('div');
      sub.className = 'status-detail';
      sub.textContent = detail;
      wrapper.appendChild(sub);
    }

    return wrapper;
  }

  function updateDevicePlaceholders() {
    const type = devType.value;
    if (type === 'MTI') {
      devName.placeholder = 'e.g. MTI_01';
      devIp.placeholder = 'e.g. 192.168.137.10';
      devNameHint.hidden = false;
      // Strip any already-present character that is invalid for a D-Bus interface name element.
      devName.value = devName.value.replace(/[^A-Za-z0-9_]/g, '');
      return;
    }

    devName.placeholder = 'e.g. IOBOX_01';
    devIp.placeholder = 'e.g. 192.168.137.20';
    devNameHint.hidden = true;
  }

  function renderCanRows() {
    canBody.innerHTML = '';

    canRows.forEach((row) => {
      const tr = document.createElement('tr');
      tr.dataset.bus = row.bus || '';

      const cells = [
        row.bus || '—',
        row.mode || '—',
        row.bitrate_display || '—',
        null,
        row.devices || 'None',
        null,
      ];

      cells.forEach((value, idx) => {
        const td = document.createElement('td');

        if (idx === 3) {
          td.appendChild(renderStatusCell(row.state || 'Unknown', row.detail || ''));
        } else if (idx === 5) {
          const actions = Array.isArray(row.actions) ? row.actions : [];
          if (actions.length > 0) {
            const wrap = document.createElement('div');
            wrap.style.display = 'flex';
            wrap.style.gap = '6px';
            actions.forEach((label) => {
              const btn = document.createElement('button');
              btn.className = label.startsWith('Open ') && label.endsWith(' Config') ? 'btn sm primary' : 'btn sm';
              btn.textContent = label;
              btn.dataset.bus = row.bus || '';
              btn.dataset.action = label;
              btn.disabled = !!legacyState.blocking;
              if (legacyState.blocking && legacyState.message) {
                btn.title = legacyState.message;
              }
              wrap.appendChild(btn);
            });
            td.appendChild(wrap);
          } else {
            td.textContent = '—';
          }
        } else {
          td.textContent = value;
        }

        tr.appendChild(td);
      });

      canBody.appendChild(tr);
    });

    const chip = canWarnings?.chip || 'No CAN warnings';
    const hasWarnings = !!canWarnings?.chip;
    canChip.textContent = chip;
    canChip.classList.toggle('warn', hasWarnings);
    canChip.classList.toggle('ok', !hasWarnings);

    const ticker = Array.isArray(canWarnings?.ticker) && canWarnings.ticker.length
      ? canWarnings.ticker.join(' | ')
      : 'All buses look stable.';
    canTicker.textContent = ticker;
  }

  function renderConfiguredRows() {
    const manualDevices = devices.filter((d) => {
      if ((d.origin || '') === 'auto') return false;
      if (d.type === 'NOX' && String(d.status || '').toLowerCase() === 'disabled') return false;
      return true;
    });
    const selectedIds = selectedConfiguredIds();

    configuredBody.innerHTML = '';
    manualDevices.forEach((d, i) => {
      const tr = document.createElement('tr');
      tr.className = 'row';
      tr.dataset.id = d.id || '';
      tr.dataset.origin = d.origin || 'xml';

      const tdCheck = document.createElement('td');
      const cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.setAttribute('aria-label', `Select configured row ${i + 1}`);
      if (tr.dataset.id && selectedIds.has(tr.dataset.id)) {
        cb.checked = true;
      }
      tdCheck.appendChild(cb);

      const tdIdx = document.createElement('td');
      tdIdx.textContent = i + 1;

      const tdBus = document.createElement('td');
      tdBus.textContent = d.bus || '-';

      const tdType = document.createElement('td');
      tdType.textContent = d.type || '-';

      const tdName = document.createElement('td');
      tdName.textContent = d.name || '-';

      const tdIp = document.createElement('td');
      tdIp.textContent = d.ip || '-';

      const tdStatus = document.createElement('td');
      tdStatus.appendChild(renderStatusCell(
        d.runtime_status || d.status || 'Unknown',
        d.runtime_detail || (d.status === 'Disabled' ? 'Disabled in config' : '')
      ));

      const tdAction = document.createElement('td');
      if (d.type === 'MTI') {
        const btn = document.createElement('button');
        btn.className = 'btn sm primary';
        btn.type = 'button';
        btn.textContent = 'Open MTI Config';
        btn.dataset.openMti = d.name || '';
        btn.disabled = !!legacyState.blocking || !(d.name || '').trim();
        tdAction.appendChild(btn);
      } else {
        tdAction.textContent = '—';
      }

      [tdCheck, tdIdx, tdBus, tdType, tdName, tdIp, tdStatus, tdAction].forEach((td) => tr.appendChild(td));
      configuredBody.appendChild(tr);
    });

    syncConfiguredState();
  }

  function renderDetectedRows() {
    const detectedDevices = devices.filter((d) => (d.origin || '') === 'auto');

    detectedBody.innerHTML = '';
    detectedDevices.forEach((d, i) => {
      const tr = document.createElement('tr');
      tr.className = 'row';

      const tdIdx = document.createElement('td');
      tdIdx.textContent = i + 1;

      const tdBus = document.createElement('td');
      tdBus.textContent = d.bus || '-';

      const tdType = document.createElement('td');
      tdType.textContent = d.type || '-';

      const tdName = document.createElement('td');
      tdName.textContent = d.name || '-';

      const tdStatus = document.createElement('td');
      tdStatus.appendChild(renderStatusCell(d.runtime_status || d.status || 'Unknown', 'Auto detected'));

      [tdIdx, tdBus, tdType, tdName, tdStatus].forEach((td) => tr.appendChild(td));
      detectedBody.appendChild(tr);
    });
  }

  function renderAll() {
    renderCanRows();
    renderConfiguredRows();
    renderDetectedRows();
    applyLegacyLockUI();
  }

  function syncConfiguredState() {
    const rows = $$('#configuredBody tr');
    rows.forEach((r) => {
      const checked = !!r.querySelector('input[type="checkbox"]')?.checked;
      r.classList.toggle('selected', checked);
    });

    const checked = rows.filter((r) => r.querySelector('input[type="checkbox"]')?.checked).length;
    removeBtn.disabled = !!legacyState.blocking || checked === 0;

    const total = rows.length;
    mchk.checked = total > 0 && checked === total;
    mchk.indeterminate = checked > 0 && checked < total;
    mchk.disabled = !!legacyState.blocking || total === 0;
  }

  function applyLegacyLockUI() {
    const blocked = !!legacyState.blocking;
    const message = legacyState.message || 'Legacy MDAQ config found in XML. Remove it manually before using this page.';

    if (legacyBanner) {
      legacyBanner.hidden = !blocked;
    }
    if (legacyBannerText) {
      legacyBannerText.textContent = message;
    }

    if (blocked) {
      propCard.style.display = 'none';
    }

    addBtn.disabled = blocked;
    saveBtn.disabled = blocked;

    $$('#configuredBody input[type="checkbox"]').forEach((cb) => {
      cb.disabled = blocked;
    });
    $$('#canBody button').forEach((btn) => {
      btn.disabled = blocked;
      if (blocked) {
        btn.title = message;
      }
    });
  }

  function findCanRow(bus) {
    return canRows.find((row) => row.bus === bus) || null;
  }

  async function loadPageData(silent = false, pollMode = false) {
    try {
      if (!devicesApi) throw new Error('Devices API unavailable');

      const devicesJson = await devicesApi.fetchDevices();
      if (devicesJson.ok === false) throw new Error(devicesJson.error || 'Load devices failed');

      devices = devicesJson.data || [];
      const devicesLegacy = devicesJson.legacy || {};
      legacyState = {
        blocking: !!devicesLegacy.blocking,
        message: String(devicesLegacy.message || ''),
      };

      try {
        if (!canRolesApi) throw new Error('CAN roles API unavailable');
        const canJson = await canRolesApi.fetchRoles();
        if (canJson.ok === false) throw new Error(canJson.error || 'Load CAN roles failed');
        canRows = canJson.data?.rows || [];
        canWarnings = canJson.data?.warnings || { chip: null, ticker: [] };
      } catch (canErr) {
        // Keep the last CAN table during daemon/network settle, but do not let
        // a CAN roles read failure keep a stale legacy-MDAQ lock on the page.
        if (!silent && !pollMode) {
          alert('Failed to load CAN bus data: ' + canErr.message);
        }
      }

      renderAll();
      return true;
    } catch (err) {
      if (!silent) {
        alert('Failed to load Add Devices data: ' + err.message);
      }
      if (!pollMode) {
        renderAll();
      }
      return false;
    }
  }

  async function refreshAfterConfigChange() {
    const maxAttempts = 20;
    const retryDelayMs = 1000;

    for (let i = 0; i < maxAttempts; i += 1) {
      const ok = await loadPageData(true, true);
      if (ok) return true;
      await new Promise((resolve) => setTimeout(resolve, retryDelayMs));
    }

    return loadPageData(false, false);
  }

  async function runCanRoleAction(actionCode, bus, actionLabel) {
    if (transitionInProgress) return false;
    if (legacyState.blocking) {
      alert(legacyState.message || 'Legacy MDAQ config found in XML. Remove it manually before using this page.');
      return false;
    }
    if (!canRolesApi) throw new Error('CAN roles API unavailable');

    transitionInProgress = true;
    stopDevicesPoll();
    saveBtn.disabled = true;
    removeBtn.disabled = true;

    try {
      const json = await canRolesApi.transition(actionCode, bus);
      if (json.ok === false) {
        throw new Error(json.error || 'Transition failed');
      }
      // Transition is pending (server returns immediately after restart).
      // One immediate reload updates the UI to the in-progress state; the
      // regular 1 s poll then picks up the settle progression on its own.
      await loadPageData(true, true);
      return true;
    } catch (err) {
      alert(`Failed to ${actionLabel.toLowerCase()}: ${err.message}`);
      return false;
    } finally {
      transitionInProgress = false;
      saveBtn.disabled = false;
      syncConfiguredState();
      syncDevicesPoll();
    }
  }

  async function saveDevice() {
    if (legacyState.blocking) {
      alert(legacyState.message || 'Legacy MDAQ config found in XML. Remove it manually before using this page.');
      return;
    }

    const type = devType.value;
    const name = devName.value.trim();
    const ip = devIp.value.trim();

    if (!validateRequired()) return;
    if (isDuplicate(name, ip)) return;

    if (!confirmServiceRestart('Adding this device')) return;

    const payload = {
      type,
      name,
      ip,
    };

    saveBtn.disabled = true;

    try {
      if (!devicesApi) throw new Error('Devices API unavailable');
      const json = await devicesApi.updateDevices(payload, 'POST');
      if (json.ok === false) {
        throw new Error(json.error || 'HTTP error');
      }
      propCard.style.display = 'none';
      await refreshAfterConfigChange();
    } catch (err) {
      alert('Failed to save: ' + err.message);
    } finally {
      saveBtn.disabled = false;
    }
  }

  addBtn.addEventListener('click', () => {
    if (legacyState.blocking) {
      alert(legacyState.message || 'Legacy MDAQ config found in XML. Remove it manually before using this page.');
      return;
    }
    propCard.style.display = 'block';
    devType.value = 'IO-BOX';
    updateDevicePlaceholders();
    devName.value = '';
    devIp.value = '';
  });

  devType.addEventListener('change', updateDevicePlaceholders);

  saveBtn.addEventListener('click', saveDevice);

  cancelBtn.addEventListener('click', () => {
    propCard.style.display = 'none';
  });

  mchk.addEventListener('change', () => {
    if (legacyState.blocking) {
      mchk.checked = false;
      return;
    }
    const enableAll = mchk.checked;
    $$('#configuredBody input[type="checkbox"]').forEach((cb) => {
      cb.checked = enableAll;
    });
    syncConfiguredState();
  });

  configuredBody.addEventListener('click', (e) => {
    if (legacyState.blocking) return;
    const mtiBtn = e.target.closest('button[data-open-mti]');
    if (mtiBtn) {
      openMtiPopup(mtiBtn.dataset.openMti || '');
      return;
    }

    const tr = e.target.closest('tr');
    if (!tr) return;
    const cb = tr.querySelector('input[type="checkbox"]');
    if (!cb) return;
    if (!e.target.closest('input[type="checkbox"]')) {
      cb.checked = !cb.checked;
    }
    syncConfiguredState();
  });

  canBody.addEventListener('click', async (e) => {
    if (legacyState.blocking) return;
    const btn = e.target.closest('button[data-action][data-bus]');
    if (!btn) return;

    const bus = btn.dataset.bus || '';
    const label = btn.dataset.action || '';

    if (label === 'Open NOX Config') {
      openNoxPopup(bus);
      return;
    }

    const actionCode = label === 'Switch to SDAQ' ? 'switch_to_sdaq' : 'switch_to_nox';
    if (!confirmServiceRestart(`${label} on ${bus}`)) return;
    await runCanRoleAction(actionCode, bus, label);
  });

  async function deleteDevices() {
    if (removeInProgress) return;
    if (legacyState.blocking) {
      alert(legacyState.message || 'Legacy MDAQ config found in XML. Remove it manually before using this page.');
      return;
    }

    const rows = $$('#configuredBody tr').filter((r) => r.querySelector('input[type="checkbox"]')?.checked);
    if (!rows.length) {
      alert('Please select devices to delete.');
      return;
    }

    const selectedDevices = rows
      .map((r) => devices.find((d) => d.id === r.dataset.id))
      .filter(Boolean);
    const manualIds = selectedDevices
      .map((d) => d.id)
      .filter(Boolean);

    if (!manualIds.length) {
      alert('No removable configured devices selected.');
      return;
    }

    if (selectedDevices.length === 1 && selectedDevices[0].type === 'NOX') {
      const noxBus = selectedDevices[0].bus || '';
      if (window.confirm(`Restore ${noxBus} to SDAQ instead of leaving it free?`)) {
        if (!confirmServiceRestart(`Switching ${noxBus} to SDAQ`)) {
          return;
        }
        await runCanRoleAction('switch_to_sdaq', noxBus, 'Switch to SDAQ');
        return;
      }
    }

    if (!confirmServiceRestart('Removing selected device(s)')) {
      return;
    }

    removeInProgress = true;
    stopDevicesPoll();
    removeBtn.disabled = true;

    try {
      if (!devicesApi) throw new Error('Devices API unavailable');
      const json = await devicesApi.updateDevices({ ids: manualIds }, 'DELETE');
      if (json.ok === false) {
        throw new Error(json.error || 'HTTP error');
      }
      await refreshAfterConfigChange();
    } catch (err) {
      alert('Failed to delete: ' + err.message);
    } finally {
      removeInProgress = false;
      syncConfiguredState();
      syncDevicesPoll();
    }
  }

  removeBtn.addEventListener('click', deleteDevices);

  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && String(e.key).toLowerCase() === 's') {
      if (!saveBtn.disabled && propCard.style.display !== 'none') {
        e.preventDefault();
        saveDevice();
      }
      return;
    }

    if (e.key !== 'Delete') return;
    if (legacyState.blocking) return;

    const el = document.activeElement;
    const isFormField = el && (
      el.tagName === 'INPUT'
      || el.tagName === 'TEXTAREA'
      || el.tagName === 'SELECT'
      || el.isContentEditable
    );
    if (isFormField) return;

    const rows = $$('#configuredBody tr').filter((r) => r.querySelector('input[type="checkbox"]')?.checked);
    if (!rows.length) return;

    deleteDevices();
  });

  refreshBtn.addEventListener('click', () => loadPageData(false, false));

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && popupWindowHasFocus) {
      loadPageData(true, true);
    }
    syncDevicesPoll();
  });

  window.addEventListener('focus', () => {
    popupWindowHasFocus = true;
    loadPageData(true, true);
    syncDevicesPoll();
  });

  window.addEventListener('blur', () => {
    popupWindowHasFocus = false;
    syncDevicesPoll();
  });

  loadPageData(false, false);
  syncDevicesPoll();
  window.addEventListener('beforeunload', stopDevicesPoll);
})();
