/* =============================================================================
 * Link Creator – LOG WEB v2
 * - Combines legacy "Add channel" + "Add channels" behaviors.
 * - Range selector appears for SDAQ; range>1 mirrors legacy bulk-add logic.
 * - Sensor path is chosen from the search popup (no manual typing).
 * ========================================================================== */

(() => {
  const $ = (s, r = document) => r.querySelector(s);

  const typeSel = $('#type');
  const pathInput = $('#path');
  const isoInput = $('#iso');
  const postfixSel = $('#postfix');
  const isoDropdown = $('#isoDropdown');
  const descInput = $('#desc');

  const rangeLabel = $('#rangeLabel');
  const rangeRow = $('#rangeRow');
  const rangeInput = $('#range');

  const minInput = $('#min');
  const maxInput = $('#max');
  const alarmLowVal = $('#alarmLowVal');
  const alarmLowChk = $('#alarmLow');
  const alarmHighVal = $('#alarmHighVal');
  const alarmHighChk = $('#alarmHigh');
  const unitInput = $('#unit');
  const calDateInput = $('#calDate');
  const calPeriodInput = $('#calPeriod');
  const calRows = Array.from(document.querySelectorAll('.cal-data'));

  const statusBar = $('#status');
  const btnSave = $('#btnSave');
  const btnCancel = $('#btnCancel');
  const btnSearch = $('#btnSearch');

  const channelsApi = window.LOG_WEB?.api?.channels;
  const isoCatalogService = window.LOG_WEB?.services?.isoCatalog;
  const searchPoolService = window.LOG_WEB?.services?.searchPool;

  const state = {
    isoCatalog: {},
    isoList: [],
    searchPool: {},
    selectedDevice: null,
    searchWin: null,
  };

  const SEARCH_POOL_REFRESH_MS = 1000; // legacy logstat polling cadence
  let searchPoolLoading = false;
  let pendingPoolRefresh = false;

  const setDisabled = (el, on) => {
    if (!el) return;
    el.disabled = !!on;
    el.style.background = on ? 'var(--bg-weak)' : '';
  };

  function setStatus(msg, tone = 'info') {
    statusBar.textContent = msg;
    statusBar.style.color = tone === 'error' ? '#e11d48' : 'inherit';
  }

  function pad2(n) {
    return String(n).padStart(2, '0');
  }

  function fillPostfix() {
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

  async function loadIsoCatalog() {
    if (!isoCatalogService) {
      setStatus('ISO catalog service unavailable', 'error');
      return;
    }

    try {
      const payload = await isoCatalogService.loadCatalog();
      state.isoCatalog = payload.catalog || {};
      state.isoList = payload.list || [];
      if (!state.isoList.length) {
        setStatus('No ISOstandard entries were loaded; check Advanced Settings selection', 'error');
      }
    } catch (err) {
      console.error('Failed to load ISO catalog', err);
      setStatus('No ISOstandard entries were loaded; check Advanced Settings selection', 'error');
    }
  }

  async function loadSearchPool(force = false) {
    if (searchPoolLoading) {
      if (force) pendingPoolRefresh = true;
      return;
    }

    searchPoolLoading = true;

    try {
      if (!searchPoolService) throw new Error('Search pool service unavailable');
      state.searchPool = await searchPoolService.loadSearchPool();
    } catch (err) {
      console.error('Failed to load device pool', err);
    } finally {
      searchPoolLoading = false;
      if (pendingPoolRefresh) {
        pendingPoolRefresh = false;
        loadSearchPool();
      }
    }
  }


  function lookupIso(codeRaw) {
    if (!codeRaw) return null;
    const code = codeRaw.startsWith('_') ? codeRaw : '_' + codeRaw;
    return state.isoCatalog[code] || null;
  }

  function clearIsoDefaults() {
    postfixSel.value = 'N/A';
    if (!descInput.dataset.userEdited) descInput.value = '';
    if (!minInput.dataset.userEdited) minInput.value = '';
    if (!maxInput.dataset.userEdited) maxInput.value = '';
    if (!unitInput.dataset.userEdited) unitInput.value = '';
    alarmHighVal.value = '';
    alarmLowVal.value = '';
    alarmHighChk.checked = false;
    alarmLowChk.checked = false;
    syncAlarmInputs();
  }

  function hydrateFromIso(codeRaw, options = {}) {
    const entry = lookupIso(codeRaw);
    if (!entry) return;
    if (!descInput.dataset.userEdited && !descInput.value) descInput.value = entry.description;
    if (!minInput.dataset.userEdited && !minInput.value) minInput.value = entry.min;
    if (!maxInput.dataset.userEdited && !maxInput.value) maxInput.value = entry.max;
    if (!unitInput.dataset.userEdited && !unitInput.value) unitInput.value = entry.unit;

    const highVal = entry.alarmHighVal || entry.max;
    const lowVal = entry.alarmLowVal || entry.min;
    alarmHighVal.value = highVal;
    alarmLowVal.value = lowVal;
    alarmHighChk.checked = (entry.alarmHigh || '').toLowerCase() === 'yes';
    alarmLowChk.checked = (entry.alarmLow || '').toLowerCase() === 'yes';
    syncAlarmInputs();
    if (!options.skipSuggestions) {
      renderIsoSuggestions(isoInput.value);
    }
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
        postfixSel.value = 'N/A';
        hydrateFromIso(code, { skipSuggestions: true });
        isoInput.focus();
      });
      isoDropdown.appendChild(item);
    });

    isoDropdown.classList.toggle('hidden', !isoDropdown.children.length);
  }

  function syncAlarmInputs() {
    const lockLow = !alarmLowChk.checked;
    const lockHigh = !alarmHighChk.checked;
    setDisabled(alarmLowVal, lockLow);
    setDisabled(alarmHighVal, lockHigh);
  }

  function toggleMultiLock(isMulti) {
    [minInput, maxInput, alarmLowVal, alarmLowChk, alarmHighVal, alarmHighChk, unitInput]
      .forEach((el) => setDisabled(el, isMulti));
  }

  function applyTypeRules() {
    const t = typeSel.value;
    const isSdaq = t === 'SDAQ';
    const showCal = t !== '-' && !isSdaq;

    const placeholders = {
      SDAQ: 'CAN1.ADDR:01.CH:01',
      IOBOX: 'DEV_NAME.RX:X.CH:XX',
      MTI: 'DEV_NAME.Type.CH:XX',
      NOX: 'CAN-if.Addr:X.Sensor_name',
    };

    rangeLabel.classList.toggle('hidden', !isSdaq);
    rangeRow.classList.toggle('hidden', !isSdaq);
    calRows.forEach((row) => {
      row.style.display = showCal ? '' : 'none';
    });

    if (showCal) {
      const now = new Date();
      if (calDateInput && !calDateInput.value) {
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        calDateInput.value = `${now.getFullYear()}-${month}-${day}`;
      }
      if (calPeriodInput && !calPeriodInput.value) {
        calPeriodInput.value = '12';
      }
    }

    if (!isSdaq) {
      rangeInput.value = 1;
      toggleMultiLock(false);
    } else {
      const v = Math.max(1, parseInt(rangeInput.value || '1', 10));
      rangeInput.value = v;
      toggleMultiLock(v >= 2);
    }

    state.selectedDevice = null;
    pathInput.value = '';
    pathInput.placeholder = placeholders[t] || 'Select from search';
    setStatus(t === '-' ? 'Select Type' : `Type selected: ${t}`);
  }

  function onRangeChange() {
    const n = Math.max(1, parseInt(rangeInput.value || '1', 10));
    rangeInput.value = n;
    toggleMultiLock(typeSel.value === 'SDAQ' && n >= 2);
  }

  function openSearchPopup() {
    if (!typeSel.value || typeSel.value === '-') {
      setStatus('Select a Type first', 'error');
      return;
    }
    const url = `device_search.html?type=${encodeURIComponent(typeSel.value)}`;
    const features = 'width=780,height=720,resizable=yes,scrollbars=yes';
    if (state.searchWin && !state.searchWin.closed) {
      try { state.searchWin.focus(); return; } catch (_) { }
    }
    state.searchWin = window.open(url, 'device_search', features);
  }

  function formatDescription(baseDesc, postfix) {
    if (!postfix || postfix === 'N/A') return baseDesc;
    return `${baseDesc} Cyl:${postfix}`;
  }

  function buildIsoName(base, offset) {
    if (offset === 0) return base;
    const m = base.match(/^(.*?)(\d+)$/);
    if (!m) return null;
    const next = parseInt(m[2], 10) + offset;
    return `${m[1]}${next}`;
  }

  function buildAnchor(base, offset) {
    if (offset === 0) return base;
    const m = base.match(/^(.*CH:?)(\d+)$/i);
    if (!m) return null;
    const width = m[2].length;
    const next = parseInt(m[2], 10) + offset;
    return `${m[1]}${String(next).padStart(width, '0')}`;
  }

  function validateSelection(range) {
    if (!state.selectedDevice) {
      setStatus('Select a sensor from search first', 'error');
      return false;
    }

    const type = typeSel.value;
    const pool = state.searchPool[type] || [];

    for (let i = 0; i < range; i++) {
      const anchor = buildAnchor(state.selectedDevice.anchor, i);
      if (!anchor) {
        setStatus('Invalid anchor format for range expansion', 'error');
        return false;
      }
      const entry = pool.find((p) => (p.anchor || '').toUpperCase() === anchor.toUpperCase());
      if (!entry) {
        setStatus(`Channel ${anchor} is not available`, 'error');
        return false;
      }
      if (entry.link_state && entry.link_state.toLowerCase() !== 'unlinked') {
        setStatus(`Channel ${anchor} is already linked`, 'error');
        return false;
      }
      if (entry.linked_in_xml) {
        setStatus(`Channel ${anchor} already exists in configuration`, 'error');
        return false;
      }
      if (type === 'SDAQ') {
        const regDone = (entry.registration || '').toLowerCase() === 'done';
        if (!regDone || !entry.has_sensor) {
          setStatus(`Channel ${anchor} is not ready for linking`, 'error');
          return false;
        }
      }
    }
    return true;
  }

  function collectSingleRecord(anchor, isoName, baseDesc, entryFromIso) {
    const postfix = postfixSel.value !== 'N/A' ? postfixSel.value : '';
    const desc = formatDescription(baseDesc || isoName, postfix);
    const min = minInput.value || (entryFromIso ? entryFromIso.min : '0');
    const max = maxInput.value || (entryFromIso ? entryFromIso.max : '0');
    const unit = unitInput.value || (entryFromIso ? entryFromIso.unit : '') || (state.selectedDevice?.unit || '');
    const type = typeSel.value;
    const calDate = calDateInput?.value || '';
    const calPeriod = calPeriodInput?.value || '';

    const payload = {
      iso_channel: postfix ? `${isoName}_${postfix}` : isoName,
      interface_type: typeSel.value,
      anchor,
      description: desc,
      min,
      max,
      unit,
      alarm_high: alarmHighChk.checked ? 'yes' : 'no',
      alarm_high_val: alarmHighVal.value || max,
      alarm_low: alarmLowChk.checked ? 'yes' : 'no',
      alarm_low_val: alarmLowVal.value || min,
    };

    if (type !== 'SDAQ' && calDate && calPeriod) {
      payload.cal_date = calDate.replaceAll('-', '/');
      payload.cal_period = calPeriod;
    }

    return payload;
  }

  async function save() {
    const type = typeSel.value;
    if (!type || type === '-') {
      setStatus('Select a Type first', 'error');
      return;
    }

    const isoBase = isoInput.value.trim();
    if (!isoBase) {
      setStatus('ISO Code is required', 'error');
      return;
    }

    const range = type === 'SDAQ' ? Math.max(1, parseInt(rangeInput.value || '1', 10)) : 1;
    if (!validateSelection(range)) return;

    const records = [];
    for (let i = 0; i < range; i++) {
      const isoName = buildIsoName(isoBase, i);
      if (!isoName) {
        setStatus('ISO Code must end with a number for ranged add', 'error');
        return;
      }
      const anchor = buildAnchor(state.selectedDevice.anchor, i);
      if (!anchor) {
        setStatus('Invalid sensor path format', 'error');
        return;
      }
      const isoEntry = lookupIso(isoName);
      const baseDesc = i === 0 ? (descInput.value || (isoEntry ? isoEntry.description : '')) : (isoEntry ? isoEntry.description : descInput.value);
      records.push(collectSingleRecord(anchor, isoName, baseDesc, isoEntry));
    }

    btnSave.disabled = true;
    try {
      for (const rec of records) {
        if (!channelsApi) throw new Error('Channels API unavailable');
        const json = await channelsApi.createChannel(rec);
        if (json && json.ok === false) {
          throw new Error(json.error || 'Operation failed');
        }
      }
      setStatus(`Saved ${records.length} channel(s).`);
      if (window.opener && !window.opener.closed) {
        try {
          window.opener.postMessage({ type: 'channel-added' }, '*');
        } catch (_) { }
      }
      setTimeout(() => window.close(), 400);
    } catch (err) {
      console.error(err);
      setStatus(err.message || 'Failed to save channel(s)', 'error');
      btnSave.disabled = false;
    }
  }

  // Events
  typeSel.addEventListener('change', applyTypeRules);
  rangeInput.addEventListener('change', onRangeChange);
  rangeInput.addEventListener('input', onRangeChange);

  [descInput, minInput, maxInput, unitInput].forEach((el) => {
    el.addEventListener('input', () => {
      el.dataset.userEdited = '1';
      if (el === unitInput) renderIsoSuggestions(isoInput.value);
    });
  });

  isoInput.addEventListener('change', (e) => {
    hydrateFromIso(e.target.value.trim());
  });

  isoInput.addEventListener('input', (e) => {
    if (!e.target.value.trim()) {
      clearIsoDefaults();
    }
    renderIsoSuggestions(e.target.value);
  });

  isoInput.addEventListener('focus', (e) => {
    renderIsoSuggestions(e.target.value);
  });

  isoInput.addEventListener('blur', () => {
    setTimeout(() => isoDropdown.classList.add('hidden'), 120);
  });

  [alarmLowChk, alarmHighChk].forEach((el) => el.addEventListener('change', syncAlarmInputs));

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

  // Init
  (async function init() {
    fillPostfix();
    await Promise.all([loadIsoCatalog(), loadSearchPool()]);
    setInterval(() => loadSearchPool(), SEARCH_POOL_REFRESH_MS);
    applyTypeRules();
    onRangeChange();
    syncAlarmInputs();
  })();
})();