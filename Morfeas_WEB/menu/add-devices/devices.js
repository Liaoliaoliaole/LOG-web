/* ============================================================================
 * Add Devices (popup)
 * ---------------------------------------------------------------------------
 * - “Add…” reveals the inline form; Save appends a device to the table.
 * - Type rules:
 *     SDAQ  → auto from logstat (not addable in the form).
 *     NOX   → CAN interface only (Name/IP disabled, same as old behavior).
 *     IO-BOX / MDAQ / MTI → CAN interface disabled, Name/IP editable.
 * - Table features: master checkbox, row click-to-toggle, Remove selected,
 *   Delete key to remove selected, Refresh re-renders.
 * ========================================================================== */

(function () {
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

  const devicesApi = window.LOG_WEB?.api?.devices;
  const MAX_COMPONENTS = 16; // legacy Morfeas_comp_amount_max

  // --------------------------------------------------------------------------
  // 1) TABLE ELEMENTS
  // --------------------------------------------------------------------------
  const devBody = $('#devBody');
  const mchk = $('#mchk');
  const refreshBtn = $('#refreshBtn');
  const removeBtn = $('#removeBtn');

  // --------------------------------------------------------------------------
  // 2) FORM ELEMENTS
  // --------------------------------------------------------------------------
  const propCard = $('#propCard');
  const addBtn = $('#addBtn');
  const cancelBtn = $('#cancelBtn');
  const saveBtn = $('#saveBtn');

  const devType = $('#devType');
  const canIf = $('#canIf');
  const devName = $('#devName');
  const devIp = $('#devIp');

  [devName, devIp].forEach((input) => {
    input.addEventListener('input', () => stripSpaces(input));
  });
  devName.addEventListener('change', () => validateDevName(devName));
  devIp.addEventListener('change', () => validateIp(devIp));

  // --------------------------------------------------------------------------
  // 3) IN-MEMORY MODEL (backed by API)
  // --------------------------------------------------------------------------
  let devices = [];
  let componentTotal = 0;
  let componentMax = MAX_COMPONENTS;
  const DEVICES_POLL_INTERVAL_MS = 1000; // legacy-like continuous detection cadence
  let devicesPollTimer = null;
  let removeInProgress = false;
  let popupWindowHasFocus = document.hasFocus();

  // --------------------------------------------------------------------------
  // 3.1) VALIDATION HELPERS (legacy-compatible)
  // --------------------------------------------------------------------------
  const IP_REGEX = /^(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/;
  const DEV_NAME_REGEX = /^[0-9]+|[^[a-zA-Z0-9_-]]*/;

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
    if (val && !DEV_NAME_REGEX.test(val)) {
      return true;
    }
    if (val && DEV_NAME_REGEX.test(val)) {
      el.value = '';
      alert('DEV_NAME contains illegal characters');
      return false;
    }
    return true;
  }

  function validateRequired(type) {
    if (type === 'NOX') return true;

    if (!devName.value.trim() || !devIp.value.trim()) {
      alert('Nothing to commit!!!');
      return false;
    }

    if (!validateDevName(devName) || !validateIp(devIp)) {
      return false;
    }

    return true;
  }

  function isDuplicate(type, name, ip) {
    // Only Modbus-based devices (IOBOX/MTI/MDAQ) check duplicate name/IP
    if (type === 'NOX') return false;

    const conflict = devices.find((d) => {
      if (d.origin === 'auto') return false;
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

  // --------------------------------------------------------------------------
  // 4) RENDERING
  // --------------------------------------------------------------------------
  function render() {
    const selectedIds = new Set(
      $$('#devBody tr')
        .filter((r) => r.querySelector('input[type="checkbox"]')?.checked)
        .map((r) => r.dataset.id)
        .filter(Boolean)
    );

    devBody.innerHTML = '';
    devices.forEach((d, i) => {
      const tr = document.createElement('tr');
      tr.className = 'row';
      tr.dataset.index = i;
      tr.dataset.id = d.id || '';
      tr.dataset.origin = d.origin || 'xml';

      const tdCheck = document.createElement('td');
      const cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.setAttribute('aria-label', `Select row ${i + 1}`);
      if (tr.dataset.id && selectedIds.has(tr.dataset.id)) {
        cb.checked = true;
      }
      tdCheck.appendChild(cb);

      const tdIdx = document.createElement('td');
      tdIdx.textContent = i + 1;

      const tdBus = document.createElement('td');
      tdBus.textContent = d.bus || '-';

      const tdType = document.createElement('td');
      tdType.textContent = d.type;

      const tdName = document.createElement('td');
      tdName.textContent = d.name || '-';

      const tdIp = document.createElement('td');
      tdIp.textContent = d.ip || '-';

      [tdCheck, tdIdx, tdBus, tdType, tdName, tdIp].forEach((td) =>
        tr.appendChild(td)
      );
      devBody.appendChild(tr);
    });
    syncState();
  }

  function syncState() {
    const rows = $$('#devBody tr');
    rows.forEach(r =>
      r.classList.toggle('selected', r.querySelector('input[type="checkbox"]').checked)
    );

    const checked = rows.filter(r => r.querySelector('input[type="checkbox"]').checked).length;
    removeBtn.disabled = checked === 0;

    const total = rows.length;
    mchk.checked = total > 0 && checked === total;
    mchk.indeterminate = checked > 0 && checked < total;
  }

  // --------------------------------------------------------------------------
  // 5) FORM RULES (enable/disable by type)
  // --------------------------------------------------------------------------
  function setDisabled(el, disabled) {
    el.disabled = disabled;
    el.style.background = disabled ? 'var(--bg-weak)' : '';
  }

  function confirmServiceRestart(actionLabel) {
    return window.confirm(
      `${actionLabel} will restart LOG SERVICE and may need 10-60 seconds.\n\nContinue?`
    );
  }

  function shouldPollDevices() {
    return !document.hidden && popupWindowHasFocus && !removeInProgress;
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
      loadDevices(true, true);
    }, DEVICES_POLL_INTERVAL_MS);
  }

  function syncDevicesPoll() {
    if (shouldPollDevices()) {
      startDevicesPoll();
    } else {
      stopDevicesPoll();
    }
  }

  async function refreshDevicesAfterConfigChange() {
    const maxAttempts = 15;
    const retryDelayMs = 1000;

    for (let i = 0; i < maxAttempts; i++) {
      const ok = await loadDevices(true, false);
      if (ok) return true;
      await new Promise((resolve) => setTimeout(resolve, retryDelayMs));
    }

    // Final visible attempt if backend is still unavailable.
    return loadDevices(false, false);
  }

  function applyTypeRules() {
    const t = devType.value;
    const canOnly = (t === 'NOX');

    // NOX → disable Name & IP; others → disable CAN
    setDisabled(devName, canOnly);
    setDisabled(devIp, canOnly);
    setDisabled(canIf, !canOnly);

    if (canOnly) {
      devName.value = '';
      devIp.value = '';
    }
  }
  devType.addEventListener('change', applyTypeRules);

  // --------------------------------------------------------------------------
  // 6) FORM INTERACTIONS
  // --------------------------------------------------------------------------
  async function saveDevice() {
    const type = devType.value;
    const name = devName.value.trim();
    const ip = devIp.value.trim();

    if (componentTotal >= componentMax) {
      alert('Maximum Amount of components reached!!!');
      return;
    }

    if (!validateRequired(type)) return;
    if (isDuplicate(type, name, ip)) return;
    if (!confirmServiceRestart('Adding this device')) return;

    const payload = {
      type,
      bus: canIf.value,
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
      await refreshDevicesAfterConfigChange();
    } catch (err) {
      alert('Failed to save: ' + err.message);
    } finally {
      saveBtn.disabled = false;
    }
  }

  addBtn.addEventListener('click', () => {
    propCard.style.display = 'block';
    devType.value = 'IO-BOX';
    canIf.value = 'can0';
    devName.value = '';
    devIp.value = '';
    applyTypeRules();
  });

  saveBtn.addEventListener('click', saveDevice);

  cancelBtn.addEventListener('click', () => {
    propCard.style.display = 'none';
  });

  // --------------------------------------------------------------------------
  // 7) TABLE INTERACTIONS
  // --------------------------------------------------------------------------
  mchk.addEventListener('change', () => {
    const enableAll = mchk.checked;
    $$('#devBody input[type="checkbox"]').forEach(cb => {
      if (!cb.disabled) cb.checked = enableAll;
    });
    syncState();
  });

  devBody.addEventListener('click', (e) => {
    const tr = e.target.closest('tr');
    if (!tr) return;
    const cb = tr.querySelector('input[type="checkbox"]');
    if (!cb) return;
    if (!e.target.closest('input[type="checkbox"]')) {
      if (cb.disabled) return;
      cb.checked = !cb.checked;
    }
    syncState();
  });

  async function deleteDevices() {
    if (removeInProgress) return;

    const rows = $$('#devBody tr').filter(r => r.querySelector('input[type="checkbox"]').checked);
    if (!rows.length) {
      alert('Please select devices to delete.');
      return;
    }
    const manualIds = rows
      .filter(r => (r.dataset.origin || 'xml') !== 'auto')
      .map(r => r.dataset.id)
      .filter(Boolean);

    if (manualIds.length === 0) {
      alert('SDAQ device cannot be removed.');
      return;
    }

    if (manualIds.length && !confirmServiceRestart('Removing selected device(s)')) {
      return;
    }

    removeInProgress = true;
    stopDevicesPoll();
    removeBtn.disabled = true;

    try {
      if (manualIds.length) {
        if (!devicesApi) throw new Error('Devices API unavailable');
        const json = await devicesApi.updateDevices({ ids: manualIds }, 'DELETE');
        if (json.ok === false) {
          throw new Error(json.error || 'HTTP error');
        }
      }

      await refreshDevicesAfterConfigChange();
    } catch (err) {
      alert('Failed to delete: ' + err.message);
    } finally {
      removeInProgress = false;
      startDevicesPoll();
      syncState();
    }
  }

  removeBtn.addEventListener('click', deleteDevices);

  // Delete key: remove selected rows (ignores focused inputs)
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Delete') return;

    const el = document.activeElement;
    const isFormField =
      el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT' || el.isContentEditable);
    if (isFormField) return;

    const rows = $$('#devBody tr').filter(r => r.querySelector('input[type="checkbox"]').checked && !r.querySelector('input[type="checkbox"]').disabled);
    if (!rows.length) return;

    deleteDevices();
  });

  // --------------------------------------------------------------------------
  // 8) REFRESH & INITIAL LOAD
  // --------------------------------------------------------------------------
  async function loadDevices(silent = false, sdaqOnlyPoll = silent) {
    try {
      if (!devicesApi) throw new Error('Devices API unavailable');
      const json = await devicesApi.fetchDevices();
      if (json.ok === false) throw new Error(json.error || 'Load failed');
      const incoming = json.data || [];

      if (sdaqOnlyPoll) {
        // Polling mode: update only auto-detected SDAQ rows.
        // Keep manual IOBOX/MTI/NOX rows stable so user interactions are not interrupted.
        const polledAuto = incoming.filter((d) => (d.origin || '') === 'auto');
        const currentManual = devices.filter((d) => (d.origin || '') !== 'auto');
        devices = [...currentManual, ...polledAuto];
      } else {
        devices = incoming;
      }

      const meta = json.components || {};
      componentTotal = typeof meta.total === 'number' ? meta.total : devices.length;
      componentMax = typeof meta.max === 'number' ? meta.max : MAX_COMPONENTS;
      render();
      return true;
    } catch (err) {
      if (!silent) {
        alert('Failed to load devices: ' + err.message);
      }
      return false;
    }
  }

  refreshBtn.addEventListener('click', () => loadDevices(false, false));

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && popupWindowHasFocus) {
      loadDevices(true, true);
    }
    syncDevicesPoll();
  });

  window.addEventListener('focus', () => {
    popupWindowHasFocus = true;
    loadDevices(true, true);
    syncDevicesPoll();
  });

  window.addEventListener('blur', () => {
    popupWindowHasFocus = false;
    syncDevicesPoll();
  });

  loadDevices(false, false);
  syncDevicesPoll();
  window.addEventListener('beforeunload', stopDevicesPoll);
})();
