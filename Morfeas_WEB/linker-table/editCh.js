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
  const postfixSel   = $('#postfix');
  const isoDropdown  = $('#isoDropdown');
  const descInput    = $('#desc');

  const minInput     = $('#min');
  const maxInput     = $('#max');
  const alarmLowVal  = $('#alarmLowVal');
  const alarmLowChk  = $('#alarmLow');
  const alarmHighVal = $('#alarmHighVal');
  const alarmHighChk = $('#alarmHigh');
  const unitInput    = $('#unit');
  const calDateInput = $('#calDate');
  const calPeriodInput = $('#calPeriod');
  const calRows = Array.from(document.querySelectorAll('.cal-data'));

  const statusBar    = $('#status');
  const btnSave      = $('#btnSave');
  const btnCancel    = $('#btnCancel');
  const btnSearch    = $('#btnSearch');
  const titleEl      = document.querySelector('h1');

  const channelsApi = window.LOG_WEB?.api?.channels;
  const isoCatalogService = window.LOG_WEB?.services?.isoCatalog;
  const searchPoolService = window.LOG_WEB?.services?.searchPool;

  const state = {
    isoCatalog: {},
    isoList: [],
    searchPool: {},
    selectedDevice: null,
    searchWin: null,
    originalIso: '',
    sourceFamily: '',
    sourceDevType: '',
    sourceSubtypeKnown: false,
  };

  function fillPostfix() {
    if (!postfixSel) return;
    postfixSel.innerHTML = '';
    const add = (v, txt = v) => {
      const o = document.createElement('option');
      o.value = v; o.textContent = txt;
      postfixSel.appendChild(o);
    };
    add('N/A', 'N/A');
    for (let i = 1; i <= 20; i++) add(String(i));
    postfixSel.value = 'N/A';
  }

  const setDisabled = (el, on) => {
    if (!el) return;
    el.disabled = !!on;
    el.style.background = on ? 'var(--color-bg-weak)' : '';
  };

  function isReplaceMode() {
    try {
      const params = new URLSearchParams(window.location.search);
      return params.get('mode') === 'replace';
    } catch (_) {
      return false;
    }
  }

  function normFamily(raw) {
    return (raw || '').toString().trim().toUpperCase();
  }

  function normSubtype(raw) {
    return (raw || '').toString().trim().toUpperCase();
  }

  function evaluateReplaceCandidate(candidate) {
    if (!isReplaceMode()) {
      return { allow: true };
    }

    const sourceFamily = normFamily(state.sourceFamily || typeSel.value);
    const sourceSubtype = normSubtype(state.sourceDevType);
    const sourceKnown = !!state.sourceSubtypeKnown;

    const targetFamily = normFamily(candidate?.interface_type || candidate?.type);
    const targetSubtype = normSubtype(candidate?.device_type || targetFamily);

    if (sourceFamily && targetFamily && sourceFamily !== targetFamily) {
      return {
        allow: false,
        message: "Type mismatch: expected " + sourceFamily + ", got " + targetFamily + ".",
      };
    }

    if (sourceFamily === "SDAQ" && sourceKnown) {
      if (!targetSubtype || targetSubtype !== sourceSubtype) {
        return {
          allow: false,
          message: "Subtype mismatch: expected " + (state.sourceDevType || sourceSubtype) + ", got " + (candidate?.device_type || targetSubtype || "unknown") + ".",
        };
      }
    }

    if (sourceFamily === "SDAQ" && !sourceKnown) {
      return {
        allow: true,
        warning: "Subtype unknown, make sure replace within same type.",
      };
    }

    return { allow: true };
  }

  function setStatus(msg, tone = 'info') {
    statusBar.textContent = msg;
    statusBar.style.color = tone === 'error' ? '#e11d48' : (tone === 'ok' ? '#16a34a' : (tone === 'warn' ? '#d97706' : 'inherit'));
  }

  // ----- LOADERS -----
  async function loadIsoCatalog() {
    if (!isoCatalogService) return;
    try {
      const payload = await isoCatalogService.loadCatalog();
      state.isoCatalog = payload.catalog || {};
      state.isoList = payload.list || [];
    } catch (err) {
      console.error('Failed to load ISO catalog', err);
    }
  }

  async function loadSearchPool() {
    try {
      if (!searchPoolService) throw new Error('Search pool service unavailable');
      state.searchPool = await searchPoolService.loadSearchPool();
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
    if (isReplaceMode()) {
      isoDropdown.classList.add('hidden');
      return;
    }

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
      entries = entries.filter((e) => {
        const fields = [
          e.code,
          e.description,
          e.unit,
          e.min,
          e.max,
          e.alarmHigh,
          e.alarmHighVal,
          e.alarmLow,
          e.alarmLowVal,
        ];
        return fields.some((v) => String(v || '').toLowerCase().includes(term));
      });
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

  function applyCalibrationRules(type, payload) {
    const showCal = type && type !== '-' && type !== 'SDAQ';
    calRows.forEach((row) => {
      row.style.display = showCal ? '' : 'none';
    });

    if (!showCal) {
      return;
    }

    const calDateRaw = payload?.cal_date || payload?.Cal_date || '';
    const calPeriodRaw = payload?.cal_period || payload?.Cal_period || '';

    if (calDateInput && !calDateInput.value) {
      if (calDateRaw) {
        calDateInput.value = calDateRaw.replaceAll('/', '-');
      } else {
        const now = new Date();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        calDateInput.value = `${now.getFullYear()}-${month}-${day}`;
      }
    }

    if (calPeriodInput && !calPeriodInput.value) {
      calPeriodInput.value = calPeriodRaw || '12';
    }
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
    state.sourceFamily = normFamily(type || payload.interface_type || payload.IF_type || payload.Type || '');
    state.sourceDevType = (payload.dev_type || type || '').toString().trim();
    state.sourceSubtypeKnown = !!payload.dev_type_known;
    pathInput.value = payload.display_anchor || payload.anchor || payload.Connection || '';
    const isoClean = iso.replace(/^_/, '');
    let baseIso = isoClean;
    let postfix = 'N/A';
    const m = isoClean.match(/^(.*)_(\d+)$/);
    if (m) {
      baseIso = m[1];
      postfix = m[2];
    }
    isoInput.value = baseIso;
    if (postfixSel) postfixSel.value = postfix;
    descInput.value = payload.description || payload.Description || '';
    minInput.value = payload.min ?? payload.Min ?? '';
    maxInput.value = payload.max ?? payload.Max ?? '';
    unitInput.value = payload.unit || payload.Unit || '';

    alarmLowChk.checked  = ((payload.alarm_low || payload.AlarmLow || 'no').toString().toLowerCase()) === 'yes';
    alarmHighChk.checked = ((payload.alarm_high || payload.AlarmHigh || 'no').toString().toLowerCase()) === 'yes';
    alarmLowVal.value    = payload.alarm_low_val ?? payload.AlarmLowVal ?? (minInput.value || '');
    alarmHighVal.value   = payload.alarm_high_val ?? payload.AlarmHighVal ?? (maxInput.value || '');
    syncAlarmInputs();
    applyCalibrationRules(type, payload);
  }

  // ----- SEARCH POPUP -----
  function openSearchPopup() {
    const type = typeSel.value;
    if (!type || type === "-") {
      setStatus("Type is locked but required to search");
      return;
    }

    const params = new URLSearchParams();
    if (isReplaceMode()) {
      params.set("flow", "replace");
      params.set("source_type", state.sourceFamily || normFamily(type));
      params.set("source_dev_type", state.sourceDevType || type);
      params.set("source_known", state.sourceSubtypeKnown ? "1" : "0");
    } else {
      params.set("type", type);
    }

    const url = "../tool-bar/device_search.html?" + params.toString();
    const features = "width=780,height=720,resizable=yes,scrollbars=yes";
    if (state.searchWin && !state.searchWin.closed) {
      try { state.searchWin.focus(); return; } catch (_) {}
    }
    state.searchWin = window.open(url, "device_search", features);
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

    const postfix = postfixSel && postfixSel.value !== 'N/A' ? postfixSel.value : '';
    const isoFull = postfix ? `${isoVal}_${postfix}` : isoVal;
    const body = {
      iso_channel: isoFull,
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

    if (type !== 'SDAQ') {
      const calDate = calDateInput?.value || '';
      const calPeriod = calPeriodInput?.value || '';
      if (calDate && calPeriod) {
        body.cal_date = calDate.replaceAll('-', '/');
        body.cal_period = calPeriod;
      }
    }

    if (isReplaceMode()) {
      body.replace_mode = true;
      if (state.selectedDevice) {
        const decision = evaluateReplaceCandidate(state.selectedDevice);
        if (!decision.allow) {
          setStatus(decision.message || 'Selected device is not compatible for replace.', 'error');
          return;
        }
        if (decision.warning) {
          setStatus(decision.warning, 'warn');
        }
      } else if (state.sourceFamily === 'SDAQ' && !state.sourceSubtypeKnown) {
        setStatus('Subtype unknown, make sure replace within same type.', 'warn');
      }
    }

    btnSave.disabled = true;
    try {
      if (!channelsApi) throw new Error('Channels API unavailable');
      const json = await channelsApi.updateChannel(state.originalIso || isoVal, body);
      if (json && json.ok === false) {
        throw new Error(json.error || 'Operation failed');
      }
      setStatus('Saved changes.');
      try { window.opener?.postMessage({ type: 'channel-updated' }, '*'); } catch (_) {}
      setTimeout(() => window.close(), 400);
    } catch (err) {
      console.error(err);
      const backendMsg = err?.payload?.error;
      const backendCode = err?.payload?.code;
      const finalMsg = backendMsg || err.message || 'Failed to save changes';
      setStatus(backendCode ? (finalMsg + ' [' + backendCode + ']') : finalMsg, 'error');
      btnSave.disabled = false;
    }
  }

  // ----- EVENTS -----
  [minInput, maxInput, unitInput, alarmLowVal, alarmHighVal].forEach((el) => {
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

    const candidate = data.payload || null;
    if (!candidate) return;

    const decision = evaluateReplaceCandidate(candidate);
    if (!decision.allow) {
      setStatus(decision.message || 'Selected device is not compatible for replace.', 'error');
      return;
    }

    state.selectedDevice = candidate;
    pathInput.value = candidate.display_anchor || candidate.anchor || '';
    if (!unitInput.dataset.userEdited && candidate.unit) {
      unitInput.value = candidate.unit;
    }
    renderIsoSuggestions(isoInput.value);

    if (decision.warning) {
      setStatus(decision.warning, 'warn');
    } else {
      setStatus('Selected ' + pathInput.value);
    }
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') window.close();
  });

  // ----- INIT -----
  (async function init() {
    const payload = readPayload();
    fillPostfix();
    hydrateFromPayload(payload);
    const replaceMode = isReplaceMode();
    if (replaceMode) {
      document.title = 'Replace Link';
      if (titleEl) titleEl.textContent = 'Replace Link';

      let sourceInfo = '';
      if (state.sourceFamily === 'SDAQ') {
        if (state.sourceSubtypeKnown) {
          const staleText = payload?.dev_type_stale ? ' (last known)' : '';
          sourceInfo = 'Source: ' + (state.sourceDevType || 'SDAQ') + staleText + '. ';
        } else {
          sourceInfo = 'Source: SDAQ (unknown). ';
        }
      }
      statusBar.textContent = sourceInfo + 'Select a new sensor path, then Save.';
    }
    setDisabled(typeSel, true);
    setDisabled(isoInput, true);
    setDisabled(postfixSel, true);
    setDisabled(descInput, true);
    if (replaceMode) {
      setDisabled(pathInput, false);
      setDisabled(btnSearch, false);
      pathInput.classList.remove('ro');
      btnSearch.classList.remove('ro');
      setDisabled(minInput, true);
      setDisabled(maxInput, true);
      setDisabled(unitInput, true);
      setDisabled(alarmLowVal, true);
      setDisabled(alarmHighVal, true);
      setDisabled(alarmLowChk, true);
      setDisabled(alarmHighChk, true);
    } else {
      setDisabled(pathInput, true);
      setDisabled(btnSearch, true);
    }
    await Promise.all([loadIsoCatalog(), loadSearchPool()]);
    if (isoInput.value) {
      hydrateFromIso(isoInput.value, { skipSuggestions: true });
    }
    syncAlarmInputs();
    isoDropdown?.classList.add('hidden');
    if (!payload) setStatus('No row data provided', 'error');
  })();
})();
