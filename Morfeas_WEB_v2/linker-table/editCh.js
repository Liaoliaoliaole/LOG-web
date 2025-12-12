/* ===========================================================================
 * Edit Link popup (LOG WEB v2)
 * - Mirrors Link Creator behaviors for path search and ISO suggestions.
 * - Prefills fields from the selected row; Type stays locked/disabled.
 * - Allows editing path, ISO, description, min/max, alarms, and unit.
 * ========================================================================== */

(() => {
  const $  = (s, r = document) => r.querySelector(s);

  const typeSel      = $('#type');
  const pathInput    = $('#path');
  const isoInput     = $('#iso');
  const isoDropdown  = $('#isoDropdown');
  const descInput    = $('#desc');

  const minInput     = $('#min');
  const maxInput     = $('#max');
  const alarmLowVal  = $('#alarmLowVal');
  const alarmLowChk  = $('#alarmLow');
  const alarmHighVal = $('#alarmHighVal');
  const alarmHighChk = $('#alarmHigh');
  const unitInput    = $('#unit');

  const statusBar    = $('#status');
  const btnSave      = $('#btnSave');
  const btnCancel    = $('#btnCancel');
  const btnSearch    = $('#btnSearch');

  const state = {
    isoCatalog: {},
    isoList: [],
    searchPool: {},
    selectedDevice: null,
    searchWin: null,
    originalIso: '',
  };

  const setDisabled = (el, on) => {
    if (!el) return;
    el.disabled = !!on;
    el.style.background = on ? 'var(--bg-weak)' : '';
  };

  function setStatus(msg, tone = 'info') {
    statusBar.textContent = msg;
    statusBar.style.color = tone === 'error' ? '#e11d48' : (tone === 'ok' ? '#16a34a' : 'inherit');
  }

  // ----- LOADERS -----
  async function loadIsoCatalog() {
    const parseIsoXml = (xmlText) => {
      const parser = new DOMParser();
      const xml = parser.parseFromString(xmlText, 'application/xml');
      const points = xml.querySelector('points');
      if (!points) return false;
      points.childNodes.forEach((node) => {
        if (node.nodeType !== 1) return;
        const code = node.nodeName.trim();
        const read = (tag) => {
          const el = node.querySelector(tag);
          return el ? el.textContent.trim() : '';
        };
        const entry = {
          code,
          description: read('description'),
          unit: read('unit'),
          min: read('min'),
          max: read('max'),
          alarmHigh: read('alarmHigh'),
          alarmHighVal: read('alarmHighVal'),
          alarmLow: read('alarmLow'),
          alarmLowVal: read('alarmLowVal'),
        };
        state.isoCatalog[code] = entry;
        state.isoList.push(entry);
      });
      return true;
    };

    const sources = [
      '/backend/api_channels.php?include=iso_standard',
    ];

    state.isoCatalog = {};
    state.isoList = [];

    for (const src of sources) {
      try {
        const res = await fetch(src, { cache: 'no-store' });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const xmlText = await res.text();
        if (parseIsoXml(xmlText)) return;
      } catch (err) {
        console.error('Failed to load ISOstandard.xml from', src, err);
      }
    }
  }

  async function loadSearchPool() {
    try {
      const res = await fetch('../backend/api_channels.php?include=pool', {
        headers: { Accept: 'application/json' }
      });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const json = await res.json();
      if (json && json.extras && json.extras.search_pool) {
        state.searchPool = json.extras.search_pool;
      }
    } catch (err) {
      console.error('Failed to load device pool', err);
    }
  }

  function lookupIso(codeRaw) {
    if (!codeRaw) return null;
    const code = codeRaw.startsWith('_') ? codeRaw : '_' + codeRaw;
    return state.isoCatalog[code] || null;
  }

  function normalizeUnit(u) {
    return (u || '').trim().toLowerCase();
  }

  function renderIsoSuggestions(filter = '') {
    if (!isoDropdown) return;

    const shouldShow = document.activeElement === isoInput || !!filter.trim();
    if (!shouldShow) {
      isoDropdown.classList.add('hidden');
      return;
    }

    const unitPref = normalizeUnit(unitInput.value);
    const allEntries = (state.isoList || []).slice().sort((a, b) =>
      (a.code || '').localeCompare(b.code || '')
    );
    let entries = allEntries;

    const unitMatches = allEntries.filter((e) => unitPref && normalizeUnit(e.unit) === unitPref);
    if (unitMatches.length) {
      entries = unitMatches;
    }
    if (!unitMatches.length && unitPref) {
      setStatus('No exact unit matches, showing all codes');
    }

    const term = filter.trim().toLowerCase();
    if (term) {
      entries = entries.filter((e) =>
        (e.code || '').toLowerCase().includes(term) ||
        (e.description || '').toLowerCase().includes(term)
      );
    }

    isoDropdown.innerHTML = '';

    const inputRect = isoInput.getBoundingClientRect();
    isoDropdown.style.width = `${inputRect.width}px`;
    isoDropdown.style.minWidth = `${inputRect.width}px`;
    isoDropdown.style.left = `${isoInput.offsetLeft}px`;

    entries.slice(0, 256).forEach((e) => {
      const code = (e.code || '').replace(/^_/, '');
      const item = document.createElement('div');
      item.className = 'iso-suggestion';
      item.innerHTML = `
        <div class="title">${code}</div>
        <div class="meta">${e.description || ''}${e.unit ? ` · ${e.unit}` : ''}</div>
      `;
      item.addEventListener('mousedown', (ev) => {
        ev.preventDefault();
        isoDropdown.classList.add('hidden');
        isoInput.value = code;
        hydrateFromIso(code, { skipSuggestions: true, forceDefaults: true });
        isoInput.focus();
      });
      isoDropdown.appendChild(item);
    });

    isoDropdown.classList.toggle('hidden', !isoDropdown.children.length);
  }

  function syncAlarmInputs() {
    const lockLow  = !alarmLowChk.checked;
    const lockHigh = !alarmHighChk.checked;
    setDisabled(alarmLowVal, lockLow);
    setDisabled(alarmHighVal, lockHigh);
  }

  // ----- PAYLOAD / PREFILL -----
  function readPayload() {
    try {
      const fromSession = sessionStorage.getItem('edit_channel_payload');
      if (fromSession) return JSON.parse(fromSession);
    } catch (_) {}

    try {
      if (window.name && window.name.trim().startsWith('{')) {
        return JSON.parse(window.name);
      }
    } catch (_) {}

    try {
      if (window.opener && window.opener.__EDIT_LINK_DATA) {
        return window.opener.__EDIT_LINK_DATA;
      }
    } catch (_) {}

    return null;
  }

  function hydrateFromIso(codeRaw, options = {}) {
    const entry = lookupIso(codeRaw);
    if (!entry) return;

    const shouldOverride = (el) => !el.dataset.userEdited && (!el.value || options.forceDefaults);

    if (shouldOverride(descInput) && entry.description) descInput.value = entry.description;
    if (shouldOverride(minInput) && entry.min) minInput.value = entry.min;
    if (shouldOverride(maxInput) && entry.max) maxInput.value = entry.max;
    if (shouldOverride(unitInput) && entry.unit) unitInput.value = entry.unit;

    const highVal = entry.alarmHighVal || entry.max;
    const lowVal = entry.alarmLowVal || entry.min;
    if (shouldOverride(alarmHighVal) && highVal != null) alarmHighVal.value = highVal;
    if (shouldOverride(alarmLowVal) && lowVal != null) alarmLowVal.value = lowVal;
    if (options.forceDefaults || !alarmHighChk.dataset.userEdited) {
      alarmHighChk.checked = (entry.alarmHigh || '').toLowerCase() === 'yes';
    }
    if (options.forceDefaults || !alarmLowChk.dataset.userEdited) {
      alarmLowChk.checked  = (entry.alarmLow  || '').toLowerCase() === 'yes';
    }
    syncAlarmInputs();

    if (!options.skipSuggestions) {
      renderIsoSuggestions(isoInput.value);
    }
  }

  function hydrateFromPayload(payload) {
    if (!payload) return;
    const type = payload.interface_type || payload.IF_type || payload.Type || '';
    const iso  = payload.iso_channel || payload.ISOChannel || payload.ISO || '';

    typeSel.value = type || '-';
    state.originalIso = iso || '';
    pathInput.value = payload.display_anchor || payload.anchor || payload.Connection || '';
    isoInput.value = iso.replace(/^_/, '');
    descInput.value = payload.description || payload.Description || '';
    minInput.value = payload.min ?? payload.Min ?? '';
    maxInput.value = payload.max ?? payload.Max ?? '';
    unitInput.value = payload.unit || payload.Unit || '';

    alarmLowChk.checked  = ((payload.alarm_low || payload.AlarmLow || 'no').toString().toLowerCase()) === 'yes';
    alarmHighChk.checked = ((payload.alarm_high || payload.AlarmHigh || 'no').toString().toLowerCase()) === 'yes';
    alarmLowVal.value    = payload.alarm_low_val ?? payload.AlarmLowVal ?? (minInput.value || '');
    alarmHighVal.value   = payload.alarm_high_val ?? payload.AlarmHighVal ?? (maxInput.value || '');
    syncAlarmInputs();
  }

  // ----- SEARCH POPUP -----
  function openSearchPopup() {
    const type = typeSel.value;
    if (!type || type === '-') {
      setStatus('Type is locked but required to search');
      return;
    }
    const url = `../tool-bar/device_search.html?type=${encodeURIComponent(type)}`;
    const features = 'width=780,height=720,resizable=yes,scrollbars=yes';
    if (state.searchWin && !state.searchWin.closed) {
      try { state.searchWin.focus(); return; } catch (_) {}
    }
    state.searchWin = window.open(url, 'device_search', features);
  }

  // ----- SAVE -----
  function normalizeIsoValue() {
    return isoInput.value.trim();
  }

  async function save() {
    const type = typeSel.value;
    const isoVal = normalizeIsoValue();
    const anchor = (pathInput.value || '').trim();

    if (!isoVal) {
      setStatus('ISO Code is required', 'error');
      return;
    }
    if (!anchor) {
      setStatus('Sensor path is required', 'error');
      return;
    }

    const isoEntry = lookupIso(isoVal);
    const minVal = minInput.value || (isoEntry ? isoEntry.min : '0');
    const maxVal = maxInput.value || (isoEntry ? isoEntry.max : '0');

    const body = {
      iso_channel: isoVal,
      interface_type: type,
      anchor,
      description: descInput.value,
      min: minVal,
      max: maxVal,
      unit: unitInput.value,
      alarm_high: alarmHighChk.checked ? 'yes' : 'no',
      alarm_high_val: alarmHighVal.value || maxVal,
      alarm_low: alarmLowChk.checked ? 'yes' : 'no',
      alarm_low_val: alarmLowVal.value || minVal,
    };

    btnSave.disabled = true;
    try {
      const res = await fetch(`/backend/api_channels.php?iso=${encodeURIComponent(state.originalIso || isoVal)}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const json = await res.json();
      if (json && json.ok === false) {
        throw new Error(json.error || 'Operation failed');
      }
      setStatus('Saved changes.');
      try { window.opener?.postMessage({ type: 'channel-updated' }, '*'); } catch (_) {}
      setTimeout(() => window.close(), 400);
    } catch (err) {
      console.error(err);
      setStatus(err.message || 'Failed to save changes', 'error');
      btnSave.disabled = false;
    }
  }

  // ----- EVENTS -----
  [descInput, minInput, maxInput, unitInput, alarmLowVal, alarmHighVal, isoInput].forEach((el) => {
    el?.addEventListener('input', () => { el.dataset.userEdited = '1'; });
  });

  [alarmLowChk, alarmHighChk].forEach((el) => {
    el?.addEventListener('change', () => {
      el.dataset.userEdited = '1';
      syncAlarmInputs();
    });
  });

  isoInput.addEventListener('change', (e) => {
    hydrateFromIso(e.target.value.trim(), { forceDefaults: true });
  });

  isoInput.addEventListener('input', (e) => {
    if (!e.target.value.trim()) {
      isoDropdown.classList.add('hidden');
    }
    renderIsoSuggestions(e.target.value);
  });

  isoInput.addEventListener('focus', (e) => {
    renderIsoSuggestions(e.target.value);
  });

  isoInput.addEventListener('blur', () => {
    setTimeout(() => isoDropdown.classList.add('hidden'), 120);
  });

  btnSearch.addEventListener('click', (e) => {
    e.preventDefault();
    openSearchPopup();
  });

  btnSave.addEventListener('click', (e) => {
    e.preventDefault();
    save();
  });

  btnCancel.addEventListener('click', (e) => {
    e.preventDefault();
    window.close();
  });

  window.addEventListener('message', (e) => {
    const data = e.data;
    if (!data || data.type !== 'device-selected') return;
    state.selectedDevice = data.payload || null;
    if (state.selectedDevice) {
      pathInput.value = state.selectedDevice.display_anchor || state.selectedDevice.anchor || '';
      if (!unitInput.dataset.userEdited && state.selectedDevice.unit) {
        unitInput.value = state.selectedDevice.unit;
      }
      renderIsoSuggestions(isoInput.value);
      setStatus(`Selected ${pathInput.value}`);
    }
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') window.close();
  });

  // ----- INIT -----
  (async function init() {
    const payload = readPayload();
    hydrateFromPayload(payload);
    await Promise.all([loadIsoCatalog(), loadSearchPool()]);
    if (isoInput.value) {
      hydrateFromIso(isoInput.value, { skipSuggestions: false });
    }
    syncAlarmInputs();
    if (!payload) setStatus('No row data provided', 'error');
  })();
})();
