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
  const rangeHelp = $('#rangeHelp');
  const rangeHelpPopup = $('#rangeHelpPopup');

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
  const isoCatalogRules = isoCatalogService?.rules;
  const searchPoolService = window.LOG_WEB?.services?.searchPool;

  const state = {
    isoCatalog: {},
    isoList: [],
    searchPool: {},
    selectedDevice: null,
    searchWin: null,
    suppressIsoSuggestions: false,
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
    return isoCatalogRules?.lookupEntry(state.isoCatalog, codeRaw) || null;
  }

  function clearIsoDefaults() {
    postfixSel.value = 'N/A';
    if (!descInput.dataset.userEdited) descInput.value = '';
    if (!descInput.dataset.userEdited) descInput.dataset.base = '';
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

    const standardFields = [descInput, minInput, maxInput, unitInput];
    if (options.forceDefaults) {
      standardFields.forEach((el) => delete el.dataset.userEdited);
    }
    const shouldOverride = (el) => options.forceDefaults || (!el.dataset.userEdited && !el.value);

    if (shouldOverride(descInput)) {
      descInput.value = entry.description || '';
      descInput.dataset.base = entry.description || '';
      updateDescriptionWithPostfix();
    }
    if (shouldOverride(minInput)) minInput.value = entry.min || '';
    if (shouldOverride(maxInput)) maxInput.value = entry.max || '';
    if (shouldOverride(unitInput)) unitInput.value = entry.unit || '';

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

    if (state.suppressIsoSuggestions && !filter.trim()) {
      isoDropdown.classList.add('hidden');
      return;
    }

    const shouldShow = document.activeElement === isoInput;
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
        state.suppressIsoSuggestions = true;
        isoDropdown.classList.add('hidden');
        isoInput.value = code;
        postfixSel.value = 'N/A';
        hydrateFromIso(code, { skipSuggestions: true, forceDefaults: true });
      });
      isoDropdown.appendChild(item);
    });

    isoDropdown.classList.toggle('hidden', !isoDropdown.children.length);
  }

  function syncAlarmInputs() {
    const isMulti = typeSel.value === 'SDAQ'
      && Math.max(1, parseInt(rangeInput.value || '1', 10)) > 1;
    const lockLow = isMulti || !alarmLowChk.checked;
    const lockHigh = isMulti || !alarmHighChk.checked;
    setDisabled(alarmLowVal, lockLow);
    setDisabled(alarmHighVal, lockHigh);
  }

  function toggleMultiLock(isMulti) {
    [descInput, minInput, maxInput, alarmLowChk, alarmHighChk]
      .forEach((el) => setDisabled(el, isMulti));
    setDisabled(unitInput, isMulti || typeSel.value === 'SDAQ');
    syncAlarmInputs();
  }

  function applyTypeRules() {
    const t = typeSel.value;
    const isSdaq = t === 'SDAQ';
    const showCal = t !== '-' && !isSdaq;

    const placeholders = {
      SDAQ: 'CAN1.ADDR:01.CH:01',
      IOBOX: 'DEV_NAME.RX:X.CH:XX / RX:X.Status',
      MTI: 'DEV_NAME.Type.CH:XX',
      NOX: 'CAN0.ADDR:0.NOx',
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
    updateRangeValidity();
  }

  function onRangeChange() {
    const n = Math.max(1, parseInt(rangeInput.value || '1', 10));
    rangeInput.value = n;
    toggleMultiLock(typeSel.value === 'SDAQ' && n >= 2);
    updateRangeValidity();
  }

  function openSearchPopup() {
    if (!typeSel.value || typeSel.value === '-') {
      setStatus('Select a Type first', 'error');
      return;
    }
    const url = `device_search.html?type=${encodeURIComponent(typeSel.value)}&flow=add_channel`;
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

  function normalizeBaseDescription(value) {
    return (value || '').replace(/ Cyl:\s*\d+$/i, '');
  }

  function updateDescriptionWithPostfix() {
    const postfix = postfixSel.value;
    const base = descInput.dataset.base || normalizeBaseDescription(descInput.value);
    if (!base) {
      descInput.value = '';
      return;
    }
    descInput.value = formatDescription(base, postfix);
  }

  function buildAnchor(base, offset) {
    if (offset === 0) return base;
    const m = base.match(/^(.*CH:?)(\d+)$/i);
    if (!m) return null;
    const width = m[2].length;
    const next = parseInt(m[2], 10) + offset;
    return `${m[1]}${String(next).padStart(width, '0')}`;
  }

  function findPoolEntry(type, anchor) {
    const pool = state.searchPool[type] || [];
    const target = (anchor || '').toUpperCase();
    return pool.find((p) =>
      (p.anchor || '').toUpperCase() === target ||
      (p.display_anchor || '').toUpperCase() === target
    ) || null;
  }

  function normalizeFamily(value) {
    return (value || '').toString().trim().toUpperCase();
  }

  function selectedDeviceFamily(device) {
    const direct = normalizeFamily(device?.interface_type || device?.type || device?.pool_type);
    if (direct) return direct;

    const deviceType = normalizeFamily(device?.device_type);
    if (deviceType.startsWith('SDAQ')) return 'SDAQ';
    if (deviceType.startsWith('IOBOX')) return 'IOBOX';
    if (deviceType.startsWith('MTI')) return 'MTI';
    if (deviceType.startsWith('NOX')) return 'NOX';
    return '';
  }

  function isIoboxAnchor(value) {
    return /\.RX\d+\.(?:CH\d+|Status|Success)$/i.test((value || '').toString().trim());
  }

  function selectedAnchorMatchesType(device, expectedType) {
    if (!device || !expectedType) return true;
    const anchors = [device.anchor, device.display_anchor].filter(Boolean);
    if (expectedType === 'IOBOX') {
      return anchors.some(isIoboxAnchor);
    }
    if (expectedType === 'MTI') {
      return !anchors.some(isIoboxAnchor);
    }
    return true;
  }

  function isAvailableEntry(entry, type) {
    if (!entry) return false;
    if (entry.link_state && entry.link_state.toLowerCase() !== 'unlinked') return false;
    if (entry.linked_in_xml) return false;
    return true;
  }

  function getMaxValidRange() {
    const type = typeSel.value;
    if (type !== 'SDAQ' || !state.selectedDevice) return 1;
    const pool = state.searchPool[type] || [];
    const baseAnchor = state.selectedDevice.anchor;
    if (!baseAnchor) return 1;
    let count = 0;
    for (let i = 0; i < 64; i++) {
      const anchor = buildAnchor(baseAnchor, i);
      if (!anchor) break;
      const entry = pool.find((p) => (p.anchor || '').toUpperCase() === anchor.toUpperCase());
      if (!isAvailableEntry(entry, type)) break;
      count += 1;
    }
    return Math.max(1, count || 1);
  }

  function updateRangeValidity() {
    if (!rangeInput) return;
    const type = typeSel.value;
    if (type !== 'SDAQ' || !state.selectedDevice) {
      rangeInput.classList.remove('invalid');
      rangeInput.removeAttribute('title');
      return;
    }
    const maxRange = getMaxValidRange();
    const current = Math.max(1, parseInt(rangeInput.value || '1', 10));
    const invalid = current > maxRange;
    rangeInput.classList.toggle('invalid', invalid);
    if (invalid) {
      rangeInput.title = `Only ${maxRange} valid channel(s) available.`;
    } else {
      rangeInput.removeAttribute('title');
    }
  }

  function validateSelection(range) {
    if (!state.selectedDevice) {
      setStatus('Select a sensor from search first', 'error');
      return null;
    }

    const type = typeSel.value;
    const pool = state.searchPool[type] || [];
    const anchors = [];
    if (!selectedAnchorMatchesType(state.selectedDevice, type)) {
      setStatus(`Sensor path does not match ${type}`, 'error');
      return null;
    }

    for (let i = 0; i < range; i++) {
      const anchor = buildAnchor(state.selectedDevice.anchor, i);
      if (!anchor) {
        setStatus('Invalid anchor format for range expansion', 'error');
        return null;
      }
      const entry = pool.find((p) => (p.anchor || '').toUpperCase() === anchor.toUpperCase());
      if (!entry) {
        // No manual/offline fallback: a target that isn't in the current
        // Unlinked pool can never be selected, syntax notwithstanding. The
        // backend re-verifies this independently and is the real boundary;
        // this is the first line of defense.
        setStatus(`Channel ${anchor} is not available`, 'error');
        return null;
      }
      if (entry.link_state && entry.link_state.toLowerCase() !== 'unlinked') {
        setStatus(`Channel ${anchor} is already linked`, 'error');
        return null;
      }
      if (entry.linked_in_xml) {
        setStatus(`Channel ${anchor} already exists in configuration`, 'error');
        return null;
      }
      anchors.push(anchor);
    }
    return anchors;
  }

  function collectRecord(anchor, isoName, entryFromIso, useCatalogValues) {
    const postfix = postfixSel.value !== 'N/A' ? postfixSel.value : '';
    const baseDesc = useCatalogValues
      ? (entryFromIso?.description || '')
      : (descInput.value || entryFromIso?.description || '');
    const base = normalizeBaseDescription(baseDesc || isoName);
    const desc = formatDescription(base, postfix);
    const min = useCatalogValues
      ? (entryFromIso?.min || '0')
      : (minInput.value || entryFromIso?.min || '0');
    const max = useCatalogValues
      ? (entryFromIso?.max || '0')
      : (maxInput.value || entryFromIso?.max || '0');
    const unit = useCatalogValues
      ? (entryFromIso?.unit || '')
      : (unitInput.value || entryFromIso?.unit || state.selectedDevice?.unit || '');
    const alarmHigh = useCatalogValues
      ? (entryFromIso?.alarmHigh || '').toLowerCase() === 'yes'
      : alarmHighChk.checked;
    const alarmLow = useCatalogValues
      ? (entryFromIso?.alarmLow || '').toLowerCase() === 'yes'
      : alarmLowChk.checked;
    const resolvedAlarmHighVal = useCatalogValues
      ? (entryFromIso?.alarmHighVal || max)
      : (alarmHighVal.value || max);
    const resolvedAlarmLowVal = useCatalogValues
      ? (entryFromIso?.alarmLowVal || min)
      : (alarmLowVal.value || min);
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
      alarm_high: alarmHigh ? 'yes' : 'no',
      alarm_high_val: resolvedAlarmHighVal,
      alarm_low: alarmLow ? 'yes' : 'no',
      alarm_low_val: resolvedAlarmLowVal,
    };

    if (type !== 'SDAQ') {
      payload.unit = unit;
    }

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
    const anchors = validateSelection(range);
    if (!anchors) return;

    let isoItems;
    if (range === 1) {
      // Range === 1 saves the entered code directly; ISOstandard lookup is optional.
      isoItems = [{ code: isoBase, entry: lookupIso(isoBase) }];
    } else {
      if (!isoCatalogRules) {
        setStatus('ISO catalog rules are unavailable', 'error');
        return;
      }
      const resolution = isoCatalogRules.resolveSequentialEntries(state.isoCatalog, isoBase, range);
      if (!resolution.ok) {
        setStatus(resolution.error.message, 'error');
        return;
      }
      isoItems = resolution.items;
    }

    const useCatalogValues = range > 1;
    const records = isoItems.map((item, index) =>
      collectRecord(anchors[index], item.code, item.entry, useCatalogValues)
    );

    btnSave.disabled = true;
    try {
      if (!channelsApi) throw new Error('Channels API unavailable');
      if (records.length === 1) {
        const json = await channelsApi.createChannel(records[0]);
        if (json && json.ok === false) {
          throw new Error(json.error || 'Operation failed');
        }
      } else {
        // Range Add: one atomic batch request, not a POST-per-record loop.
        // The backend re-validates and writes all-or-nothing inside a
        // single XML lock; a mid-batch failure here never leaves a partial
        // write (e.g. CH1 saved, CH2 rejected).
        const json = await channelsApi.createChannelsBatch(records);
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
      if (el === descInput) {
        descInput.dataset.base = normalizeBaseDescription(descInput.value);
        updateDescriptionWithPostfix();
      }
      if (el === unitInput) renderIsoSuggestions(isoInput.value);
    });
  });

  isoInput.addEventListener('change', (e) => {
    hydrateFromIso(e.target.value.trim());
  });

  const handleIsoInputSearch = (value) => {
    state.suppressIsoSuggestions = false;
    if (!value.trim()) {
      clearIsoDefaults();
    }
    renderIsoSuggestions(value);
  };

  isoInput.addEventListener('input', (e) => {
    handleIsoInputSearch(e.target.value || '');
  });

  // Extra fallback for some environments/IMEs where only keyup is observed reliably.
  isoInput.addEventListener('keyup', (e) => {
    if (e.key === 'Enter') return;
    handleIsoInputSearch(e.target?.value || '');
  });

  isoInput.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    state.suppressIsoSuggestions = true;
    isoDropdown.classList.add('hidden');
    const custom = isoInput.value.trim();
    isoInput.value = custom;
    postfixSel.value = 'N/A';
    descInput.value = '';
    descInput.dataset.base = '';
    delete descInput.dataset.userEdited;
  });

  postfixSel.addEventListener('change', () => {
    updateDescriptionWithPostfix();
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

  // Path is a read-only display of the current Search-popup selection (see
  // add_channel.html): no input/keydown/paste handling is attached to it, so
  // keyboard input, paste, and Enter can never establish a selection. The
  // only way to set state.selectedDevice is the 'device-selected' message
  // from the Search popup below.

  if (rangeHelp && rangeHelpPopup) {
    rangeHelp.addEventListener('click', (e) => {
      e.preventDefault();
      rangeHelpPopup.classList.toggle('hidden');
    });

    document.addEventListener('click', (e) => {
      if (!rangeHelpPopup.classList.contains('hidden')) {
        if (!rangeHelpPopup.contains(e.target) && e.target !== rangeHelp) {
          rangeHelpPopup.classList.add('hidden');
        }
      }
    });
  }

  window.addEventListener('message', (e) => {
    const data = e.data;
    if (!data || data.type !== 'device-selected') return;

    const selected = data.payload || null;
    const expectedType = normalizeFamily(typeSel.value);
    const selectedType = selectedDeviceFamily(selected);
    if (selected && expectedType && selectedType && selectedType !== expectedType) {
      setStatus(`Selected ${selectedType} channel cannot be used for ${expectedType}`, 'error');
      return;
    }
    if (selected && !selectedAnchorMatchesType(selected, expectedType)) {
      setStatus(`Selected channel shape does not match ${expectedType}`, 'error');
      return;
    }

    // Prefer the local pool entry so save-time validation uses the same anchor
    // metadata shown in this Add Channel window. If the popup data is newer than
    // the parent pool, keep the selected payload but never allow a known family
    // mismatch above.
    const selectedAnchor = selected?.anchor || selected?.display_anchor || '';
    const localEntry = selected ? findPoolEntry(expectedType, selectedAnchor) : null;
    if (selected && !localEntry && !selectedType) {
      setStatus(`Selected channel is not available for ${expectedType}`, 'error');
      return;
    }

    state.selectedDevice = localEntry || selected;
    if (state.selectedDevice) {
      pathInput.value = state.selectedDevice.display_anchor || state.selectedDevice.anchor || '';
      if (!unitInput.dataset.userEdited && state.selectedDevice.unit) {
        unitInput.value = state.selectedDevice.unit;
      }
      renderIsoSuggestions(isoInput.value);
      setStatus(`Selected ${pathInput.value}`);
      updateRangeValidity();
    }
  });

  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && String(e.key).toLowerCase() === 's') {
      e.preventDefault();
      btnSave.click();
      return;
    }
    if (e.key === 'Escape') {
      window.close();
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
    updateDescriptionWithPostfix();
    renderIsoSuggestions(isoInput.value || '');
  })();
})();
