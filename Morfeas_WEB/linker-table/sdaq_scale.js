(() => {
  const $ = (s, r = document) => r.querySelector(s);
  const root = window.LOG_WEB || (window.LOG_WEB = {});
  const applySessionHeaders = (headers = {}) => root.session?.applyHeaders ? root.session.applyHeaders(headers) : headers;
  const EDIT_RENEW_MS = 10000;

  const params = new URLSearchParams(window.location.search);
  const bus = (params.get('bus') || '').trim().toLowerCase();
  const addrRaw = (params.get('addr') || '').trim();
  const chRaw = (params.get('ch') || '').trim();
  const iso = (params.get('iso') || '').trim();
  const sn = (params.get('sn') || '').trim();
  const devType = (params.get('devType') || '').trim();
  const rawParam = (params.get('raw') || '').trim();

  const addr = /^\d+$/.test(addrRaw) ? Number(addrRaw) : null;
  const ch = /^\d+$/.test(chRaw) ? Number(chRaw) : null;

  const buildApiUrl = (query = null) => {
    if (root.config?.resolveApi) {
      return root.config.resolveApi('api_calibration.php', query || undefined);
    }

    const url = new URL('../backend/api_calibration.php', window.location.href);
    if (query) {
      Object.entries(query).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== '') {
          url.searchParams.set(key, value);
        }
      });
    }
    return url.toString();
  };

  const isoText = $('#isoText');
  const typeText = $('#typeText');
  const snText = $('#snText');
  const ctxText = $('#ctxText');
  const channelText = $('#channelText');

  const rawValue = $('#rawValue');
  const rawLowValue = $('#rawLowValue');
  const rawHighValue = $('#rawHighValue');
  const engLowValue = $('#engLowValue');
  const engHighValue = $('#engHighValue');
  const engUnitInput = $('#engUnitInput');
  const engUnitList = $('#engUnitList');
  const scaledPreview = $('#scaledPreview');

  const btnSave = $('#btnSave');
  const btnReset = $('#btnReset');
  const btnOpenCalibration = $('#btnOpenCalibration');
  const status = $('#status');

  const state = {
    units: [],
    unitSet: new Set(),
    defaultRawLow: 0,
    defaultRawHigh: 100,
    editing: false,
    blockedByOtherSession: false,
    lockRequestPromise: null,
    lockInfo: null,
    renewTimerId: null,
  };

  function setStatus(msg, type = 'info') {
    status.textContent = msg || '';
    status.style.color = type === 'ok'
      ? '#16a34a'
      : (type === 'err' ? '#dc2626' : 'var(--color-muted)');
  }

  function sessionFetch(url, options = {}) {
    return fetch(url, {
      cache: 'no-store',
      ...options,
      headers: applySessionHeaders(options.headers || {}),
    });
  }

  function lockOwnerText(lock) {
    const ip = lock?.owner?.operator_ip || 'another session';
    const hint = lock?.owner?.session_hint || '';
    return hint ? `${ip} / ${hint}` : ip;
  }

  function setEditingEnabled(enabled, { blocked = false } = {}) {
    state.editing = !!enabled;
    state.blockedByOtherSession = !!blocked;
    const shouldDisable = state.blockedByOtherSession;
    [rawLowValue, rawHighValue, engLowValue, engHighValue, engUnitInput].forEach((el) => {
      if (!el) return;
      el.disabled = shouldDisable;
      el.style.background = shouldDisable ? 'var(--color-bg-weak)' : '';
    });
    btnSave.disabled = shouldDisable;
    btnReset.disabled = shouldDisable;
  }

  async function fetchEditStatus() {
    if (!bus || addr === null) throw new Error('Missing bus/addr in popup URL');
    const res = await sessionFetch(buildApiUrl({
      action: 'edit_status',
      bus,
      addr: String(addr),
    }), {
      method: 'GET',
      headers: { Accept: 'application/json' },
    });
    const payload = await res.json().catch(() => ({}));
    if (!res.ok || !payload?.ok) {
      throw new Error(payload?.error || `Edit status request failed: HTTP ${res.status}`);
    }
    return payload?.data || {};
  }

  async function postEditAction(action) {
    if (!bus || addr === null) throw new Error('Missing bus/addr in popup URL');
    const res = await sessionFetch(buildApiUrl(), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        action,
        bus,
        addr,
        tool: 'scale',
      }),
    });
    const payload = await res.json().catch(() => ({}));
    if (!res.ok || !payload?.ok) {
      const error = new Error(payload?.error || `HTTP ${res.status}`);
      error.payload = payload;
      throw error;
    }
    return payload?.data || {};
  }

  function releaseEditLockOnUnload() {
    if (!state.editing || !bus || addr === null) return;

    try {
      fetch(buildApiUrl(), {
        method: 'POST',
        keepalive: true,
        headers: applySessionHeaders({ 'Content-Type': 'application/json', Accept: 'application/json' }),
        body: JSON.stringify({
          action: 'edit_end',
          bus,
          addr,
          tool: 'scale',
        }),
      }).catch(() => {});
    } catch (_) {
      // Best-effort release; TTL expiry remains the fallback.
    }
  }

  function stopLockRenewal() {
    if (state.renewTimerId) {
      clearInterval(state.renewTimerId);
      state.renewTimerId = null;
    }
  }

  function startLockRenewal() {
    stopLockRenewal();
    state.renewTimerId = window.setInterval(async () => {
      if (!state.editing) return;
      try {
        await postEditAction('edit_renew');
      } catch (err) {
        stopLockRenewal();
        if (err?.payload?.lock) {
          applyLockStatus({ locked: true, lock: err.payload.lock }, { silent: true });
        } else {
          applyLockStatus({ locked: false, lock: null }, { silent: true });
        }
        setStatus(err.message || 'Editing lock expired.', 'err');
      }
    }, EDIT_RENEW_MS);
  }

  function applyLockStatus(lockData, options = {}) {
    state.lockInfo = lockData?.lock || null;
    const locked = Boolean(lockData?.locked);
    const mine = Boolean(lockData?.lock?.owned_by_current_session);

    if (locked && mine) {
      setEditingEnabled(true, { blocked: false });
      startLockRenewal();
      if (!options.silent) {
        setStatus('Editing is active for this device.', 'ok');
      }
      return;
    }

    stopLockRenewal();
    setEditingEnabled(false, { blocked: locked });

    if (locked) {
      if (!options.silent) {
        setStatus(`Read-only mode. This device is currently being edited by another session (${lockOwnerText(lockData.lock)}).`, 'info');
      }
      return;
    }

    if (!options.silent) {
      setStatus('Ready. Editing lock will be acquired automatically when you change a value or save.', 'info');
    }
  }

  async function ensureEditLock({ silent = false } = {}) {
    if (state.editing) {
      return true;
    }

    if (state.lockRequestPromise) {
      return state.lockRequestPromise;
    }

    state.lockRequestPromise = (async () => {
      try {
        const data = await postEditAction('edit_start');
        applyLockStatus(data, { silent: true });
        if (!silent) {
          setStatus('Editing is active for this device.', 'ok');
        }
        return true;
      } catch (err) {
        const owner = err?.payload?.lock ? ` (${lockOwnerText(err.payload.lock)})` : '';
        if (err?.payload?.lock) {
          applyLockStatus({ locked: true, lock: err.payload.lock }, { silent: true });
        } else {
          applyLockStatus({ locked: false, lock: null }, { silent: true });
        }
        if (!silent) {
          setStatus((err.message || 'Failed to acquire edit lock') + owner, 'err');
        }
        return false;
      } finally {
        state.lockRequestPromise = null;
      }
    })();

    return state.lockRequestPromise;
  }

  function isAutoLockTarget(target) {
    if (!target) return false;
    if (target === btnSave) return true;
    return target === rawLowValue
      || target === rawHighValue
      || target === engLowValue
      || target === engHighValue
      || target === engUnitInput;
  }

  function requestEditLockForInteraction(target) {
    if (state.editing || state.blockedByOtherSession || !isAutoLockTarget(target)) {
      return;
    }

    ensureEditLock().then((acquired) => {
      if (!acquired) return;
      if (target === btnSave) {
        btnSave.click();
        return;
      }
      if (typeof target?.focus === 'function') {
        target.focus();
      }
    });
  }

  function parseNum(text) {
    const n = Number(text);
    return Number.isFinite(n) ? n : null;
  }

  function toShortNumber(value) {
    if (!Number.isFinite(value)) return '';
    const text = Number(value.toFixed(8)).toString();
    return text === '-0' ? '0' : text;
  }

  function parseRawValueFromUrl() {
    if (rawParam === '' || rawParam === '-' || rawParam === '—') return null;
    return parseNum(rawParam);
  }

  function getSelectedUnit() {
    return (engUnitInput.value || '').trim();
  }

  function isSdaqIorU() {
    const t = devType.toUpperCase();
    return t === 'SDAQ-I' || t === 'SDAQ-U';
  }

  function isSdaqU() {
    return devType.toUpperCase() === 'SDAQ-U';
  }

  function getInputModeFromXml(xmlDoc) {
    if (!xmlDoc) return '';
    const candidates = [
      'SDAQ > SDAQ_info > input_mode',
      'SDAQ > SDAQ_info > Input_mode',
      'SDAQ > SDAQ_info > Input_Mode',
      'SDAQ > SDAQ_info > INPUT_MODE',
    ];
    for (const selector of candidates) {
      const value = (xmlDoc.querySelector(selector)?.textContent || '').trim();
      if (value) return value;
    }
    return '';
  }

  function setDefaultRawRangeByType(inputMode = '') {
    if (isSdaqU()) {
      const mode = String(inputMode || '').toUpperCase();
      if (mode.includes('2V')) {
        state.defaultRawLow = 0;
        state.defaultRawHigh = 2;
        return;
      }
      state.defaultRawLow = 0;
      state.defaultRawHigh = 100;
      return;
    }

    if (devType.toUpperCase() === 'SDAQ-I') {
      state.defaultRawLow = 4;
      state.defaultRawHigh = 20;
      return;
    }

    state.defaultRawLow = 0;
    state.defaultRawHigh = 100;
  }

  function updatePreview() {
    const raw = parseNum(rawValue.value);
    const rawLow = parseNum(rawLowValue.value);
    const rawHigh = parseNum(rawHighValue.value);
    const engLow = parseNum(engLowValue.value);
    const engHigh = parseNum(engHighValue.value);

    if (raw === null) {
      scaledPreview.textContent = 'Scaled Output Value: current measurement value unavailable';
      return;
    }

    if (rawLow === null || rawHigh === null || engLow === null || engHigh === null) {
      scaledPreview.textContent = 'Scaled Output Value: enter measurement input and engineering output ranges to calculate';
      return;
    }

    if (rawHigh === rawLow) {
      scaledPreview.textContent = 'Scaled Output Value: invalid measurement input range (high equals low)';
      return;
    }

    const scaled = (raw - rawLow) * (engHigh - engLow) / (rawHigh - rawLow) + engLow;
    const unit = getSelectedUnit();
    scaledPreview.textContent = `Scaled Output Value: ${toShortNumber(scaled)}${unit ? ` ${unit}` : ''}`;
  }

  function validateBeforeSave() {
    if (!bus || addr === null || ch === null) {
      throw new Error('Missing bus/addr/ch context for scale save');
    }

    if (!isSdaqIorU()) {
      throw new Error(`Scale is available only for SDAQ-I / SDAQ-U. Current type: ${devType || '(unknown)'}`);
    }

    const rawLow = parseNum(rawLowValue.value);
    const rawHigh = parseNum(rawHighValue.value);
    const engLow = parseNum(engLowValue.value);
    const engHigh = parseNum(engHighValue.value);

    if (rawLow === null || rawHigh === null || engLow === null || engHigh === null) {
      throw new Error('Measurement input and engineering output low/high values are required');
    }

    if (rawHigh <= rawLow) {
      throw new Error('Invalid measurement input range: high must be greater than low');
    }

    const engUnit = getSelectedUnit();
    if (!engUnit) {
      throw new Error('Engineering Unit is required');
    }
    if (!state.unitSet.has(engUnit)) {
      throw new Error('Engineering Unit must be selected from supported SDAQ units');
    }

    return { rawLow, rawHigh, engLow, engHigh, engUnit };
  }

  async function fetchUnits() {
    const res = await sessionFetch(buildApiUrl({ action: 'units' }), {
      method: 'GET',
      headers: { Accept: 'application/json' },
    });

    if (!res.ok) {
      throw new Error(`Units request failed: HTTP ${res.status}`);
    }

    const payload = await res.json();
    const units = payload?.data?.SDAQ_UNITs;
    if (!Array.isArray(units)) {
      throw new Error(payload?.error || 'Invalid units payload');
    }

    state.units = units.map((v) => String(v));
    state.unitSet = new Set(state.units);

    engUnitList.innerHTML = '';
    state.units.forEach((unit) => {
      const opt = document.createElement('option');
      opt.value = unit;
      engUnitList.appendChild(opt);
    });
  }

  async function fetchCalibrationXml() {
    if (!bus || addr === null) {
      throw new Error('Missing bus/addr in popup URL');
    }

    const res = await sessionFetch(buildApiUrl({
      action: 'xml',
      bus,
      addr: String(addr),
    }), {
      method: 'GET',
      headers: { Accept: 'application/xml, text/xml' },
    });

    if (!res.ok) {
      const msg = await res.text();
      throw new Error(msg || `Calibration XML request failed: HTTP ${res.status}`);
    }

    const xmlText = await res.text();
    const doc = new DOMParser().parseFromString(xmlText, 'application/xml');
    if (doc.querySelector('parsererror')) {
      throw new Error('Failed to parse calibration XML');
    }

    return doc;
  }

  function getChannelNode(xmlDoc) {
    if (!xmlDoc || ch === null) return null;
    return xmlDoc.querySelector(`SDAQ > Calibration_Data > CH${ch}`);
  }

  function getPointValue(chNode, pointIndex, fieldName) {
    if (!chNode) return null;
    const selector = `:scope > Points > Point_${pointIndex} > ${fieldName}`;
    const raw = (chNode.querySelector(selector)?.textContent || '').trim();
    if (!raw || /^-?nan$/i.test(raw)) return null;
    return parseNum(raw);
  }

  function applyPrefillFromXml(xmlDoc) {
    const inputMode = getInputModeFromXml(xmlDoc);
    setDefaultRawRangeByType(inputMode);

    const chNode = getChannelNode(xmlDoc);
    const usedPointsRaw = (chNode?.querySelector(':scope > Used_Points')?.textContent || '').trim();
    const usedPoints = /^\d+$/.test(usedPointsRaw) ? Number(usedPointsRaw) : 0;

    const p0Measure = getPointValue(chNode, 0, 'Measure');
    const p1Measure = getPointValue(chNode, 1, 'Measure');
    const p0Reference = getPointValue(chNode, 0, 'Reference');
    const p1Reference = getPointValue(chNode, 1, 'Reference');

    const hasTwoPoint = usedPoints >= 2
      && p0Measure !== null
      && p1Measure !== null
      && p0Reference !== null
      && p1Reference !== null;

    if (hasTwoPoint) {
      rawLowValue.value = toShortNumber(p0Measure);
      rawHighValue.value = toShortNumber(p1Measure);
      engLowValue.value = toShortNumber(p0Reference);
      engHighValue.value = toShortNumber(p1Reference);
    } else {
      rawLowValue.value = toShortNumber(state.defaultRawLow);
      rawHighValue.value = toShortNumber(state.defaultRawHigh);
      engLowValue.value = '';
      engHighValue.value = '';
    }

    const unitFromCh = (chNode?.querySelector(':scope > Unit')?.textContent || '').trim();
    if (unitFromCh && state.unitSet.has(unitFromCh)) {
      engUnitInput.value = unitFromCh;
    } else if (!unitFromCh) {
      engUnitInput.value = '';
    }

    if (isSdaqU() && !inputMode) {
      setStatus('Input mode is unavailable; SDAQ-U default raw range fallback to 0..100', 'info');
    }
  }

  async function saveScale() {
    const acquired = await ensureEditLock({ silent: true });
    if (!acquired) {
      throw new Error('This device is currently being edited by another session.');
    }
    const validated = validateBeforeSave();

    const confirmed = window.confirm(
      `This will overwrite CH${ch} calibration to 2-point scale. Continue?`
    );
    if (!confirmed) {
      setStatus('Save canceled by user', 'info');
      return;
    }

    const payload = {
      action: 'scale',
      bus,
      addr,
      ch,
      rawLow: validated.rawLow,
      rawHigh: validated.rawHigh,
      engLow: validated.engLow,
      engHigh: validated.engHigh,
      engUnit: validated.engUnit,
    };

    const res = await sessionFetch(buildApiUrl(), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(payload),
    });

    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data?.ok) {
      throw new Error(data?.error || `Scale save failed: HTTP ${res.status}`);
    }

    setStatus(data.message || `Scale saved for ${bus.toUpperCase()} addr ${addr} ch ${ch}`, 'ok');
  }

  function resetScale() {
    engLowValue.value = '';
    engHighValue.value = '';
    scaledPreview.textContent = 'Scaled Output Value: -';
    setStatus('Engineering output range and preview cleared', 'info');
  }

  function openCalibration() {
    if (!bus || addr === null || ch === null) {
      setStatus('Missing channel context for Calibration page', 'err');
      return;
    }

    const url = new URL('./calibration.html', window.location.href);
    url.searchParams.set('bus', bus);
    url.searchParams.set('addr', String(addr));
    url.searchParams.set('ch', String(ch));
    url.searchParams.set('from', 'scale');
    if (sn) url.searchParams.set('sn', sn);
    if (getSelectedUnit()) url.searchParams.set('unit', getSelectedUnit());
    window.open(url.toString(), '_blank', 'noopener,noreferrer,width=1400,height=900');
  }

  function bindEvents() {
    [rawValue, rawLowValue, rawHighValue, engLowValue, engHighValue, engUnitInput].forEach((el) => {
      el?.addEventListener('input', updatePreview);
    });

    btnReset.addEventListener('click', resetScale);
    btnOpenCalibration?.addEventListener('click', openCalibration);
    btnSave.addEventListener('click', async () => {
      try {
        setStatus('Saving scale...', 'info');
        await saveScale();
      } catch (err) {
        setStatus(err.message || 'Scale save failed', 'err');
      }
    });

    document.addEventListener('pointerdown', (e) => {
      const target = e.target.closest('input, button');
      if (!target || !isAutoLockTarget(target) || state.editing || state.blockedByOtherSession) {
        return;
      }

      e.preventDefault();
      requestEditLockForInteraction(target);
    }, true);

    document.addEventListener('focusin', (e) => {
      const target = e.target;
      if (!isAutoLockTarget(target) || state.editing || state.blockedByOtherSession) {
        return;
      }

      if (typeof target.blur === 'function') {
        target.blur();
      }
      requestEditLockForInteraction(target);
    });

    document.addEventListener('keydown', (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's' && !state.blockedByOtherSession) {
        e.preventDefault();
        btnSave.click();
      }
      if (e.key === 'Escape') {
        window.close();
      }
    });

    window.addEventListener('pagehide', releaseEditLockOnUnload);
    window.addEventListener('beforeunload', releaseEditLockOnUnload);
  }

  function renderContext() {
    isoText.textContent = iso || '-';
    typeText.textContent = devType || '-';
    snText.textContent = sn || '-';
    channelText.textContent = ch !== null ? `CH ${ch}` : '-';
    ctxText.textContent = `${(bus || '-').toUpperCase()} / ${addrRaw || '-'} / ${chRaw || '-'}`;
  }

  async function init() {
    try {
      renderContext();

      if (!isSdaqIorU()) {
        throw new Error(`Scale is available only for SDAQ-I / SDAQ-U. Current type: ${devType || '(unknown)'}`);
      }

      const raw = parseRawValueFromUrl();
      rawValue.value = raw === null ? '' : toShortNumber(raw);

      await fetchUnits();
      const xmlDoc = await fetchCalibrationXml();
      applyPrefillFromXml(xmlDoc);
      bindEvents();
      setEditingEnabled(false);
      updatePreview();
      const lockData = await fetchEditStatus();
      applyLockStatus(lockData);
    } catch (err) {
      setStatus(err.message || 'Failed to initialize scale page', 'err');
      btnSave.disabled = true;
      btnReset.disabled = true;
    }
  }

  init();
})();
