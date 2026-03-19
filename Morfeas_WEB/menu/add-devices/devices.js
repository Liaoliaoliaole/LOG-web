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
  const processNotice = $('#processNotice');
  const formStatus = $('#formStatus');

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

  function setFormStatus(msg, tone = 'muted') {
    if (!formStatus) return;
    formStatus.textContent = msg || '';
    formStatus.classList.remove('success', 'error', 'progress');
    if (tone === 'success' || tone === 'error' || tone === 'progress') {
      formStatus.classList.add(tone);
    }
  }

  function updateProcessNotice(type) {
    if (!processNotice) return;

    if (type === 'IO-BOX') {
      processNotice.classList.remove('hidden');
      processNotice.classList.add('warn');
      processNotice.textContent = 'IO-BOX add process: after Save, Morfeas core service restarts automatically to apply the new handler. During restart there may be a short interruption. Expected result: IOBOX logstat files should appear within about 10-60 seconds.';
      return;
    }

    processNotice.classList.add('hidden');
    processNotice.classList.remove('warn');
    processNotice.textContent = '';
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

    updateProcessNotice(t);
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

    if (type === 'IO-BOX') {
      const approved = window.confirm(
        'Adding IO-BOX will automatically restart Morfeas core service to apply the new configuration.\n\nProcess:\n1) Save IO-BOX handler in config\n2) Restart Morfeas core service\n3) Wait for new IOBOX logstat (usually 10-60 seconds)\n\nContinue?'
      );
      if (!approved) return;
    }

    const payload = {
      type,
      bus: canIf.value,
      name,
      ip,
    };

    saveBtn.disabled = true;
    setFormStatus(
      type === 'IO-BOX'
        ? 'Saving IO-BOX and restarting Morfeas core service...'
        : 'Saving device configuration...',
      'progress'
    );

    try {
      if (!devicesApi) throw new Error('Devices API unavailable');
      const json = await devicesApi.updateDevices(payload, 'POST');
      if (json.ok === false) {
        throw new Error(json.error || 'HTTP error');
      }
      propCard.style.display = 'none';
      await loadDevices();
      if (type === 'IO-BOX') {
        setFormStatus('IO-BOX added. Core service restart requested. Waiting for IOBOX logstat...', 'success');
        alert(
          'IO-BOX was added successfully.\n\nAutomatic process started:\n1) Configuration saved\n2) Morfeas core service restarted\n3) IOBOX measurements should become available after restart\n\nExpected result: device search should show IOBOX channels once logstat_IOBOX*.json is generated (typically within 10-60 seconds).'
        );
      } else {
        setFormStatus('Device added successfully.', 'success');
      }
    } catch (err) {
      setFormStatus('Failed to save device: ' + err.message, 'error');
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
    setFormStatus('');
  });

  saveBtn.addEventListener('click', saveDevice);

  cancelBtn.addEventListener('click', () => {
    propCard.style.display = 'none';
    setFormStatus('');
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
    const rows = $$('#devBody tr').filter(r => r.querySelector('input[type="checkbox"]').checked);
    if (!rows.length) {
      alert('Please select devices to delete.');
      return;
    }
    const manualIds = rows
      .filter(r => (r.dataset.origin || 'xml') !== 'auto')
      .map(r => r.dataset.id)
      .filter(Boolean);
    const autoIds = rows
      .filter(r => (r.dataset.origin || 'xml') === 'auto')
      .map(r => r.dataset.id)
      .filter(Boolean);
    try {
      if (manualIds.length) {
        if (!devicesApi) throw new Error('Devices API unavailable');
        const json = await devicesApi.updateDevices({ ids: manualIds }, 'DELETE');
        if (json.ok === false) {
          throw new Error(json.error || 'HTTP error');
        }
      }

      // Remove selected entries locally; SDAQ will reappear on the next logstat refresh.
      const killSet = new Set([...manualIds, ...autoIds]);
      devices = devices.filter(d => !killSet.has(d.id));
      render();

      // Full refresh to sync component counts with backend state.
      await loadDevices();
    } catch (err) {
      alert('Failed to delete: ' + err.message);
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
  async function loadDevices() {
    try {
      if (!devicesApi) throw new Error('Devices API unavailable');
      const json = await devicesApi.fetchDevices();
      if (json.ok === false) throw new Error(json.error || 'Load failed');
      devices = json.data || [];
      const meta = json.components || {};
      componentTotal = typeof meta.total === 'number' ? meta.total : devices.length;
      componentMax = typeof meta.max === 'number' ? meta.max : MAX_COMPONENTS;
      render();
    } catch (err) {
      alert('Failed to load devices: ' + err.message);
    }
  }

  refreshBtn.addEventListener('click', loadDevices);
  loadDevices();
})();
