(() => {
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

  const DEFAULT_VISIBLE_ROWS = 10;
  const LIVE_POLL_MS = 1000;
  const EDIT_RENEW_MS = 10000;
  const DEFAULT_POINT_VALUES = {
    measure: '0',
    reference: '0',
    offset: '0',
    gain: '1',
    c2: '0',
    c3: '0',
  };

  const params = new URLSearchParams(location.search);
  const root = window.LOG_WEB || (window.LOG_WEB = {});
  const applySessionHeaders = (headers = {}) => root.session?.applyHeaders ? root.session.applyHeaders(headers) : headers;
  const requestedPoints = Math.max(1, parseInt(params.get('points') || '8', 10));
  const requestedCh = Math.max(1, parseInt(params.get('ch') || '1', 10));
  const requestedUnit = params.get('unit') || '';
  const requestedSn = (params.get('sn') || '').trim();
  const fromScale = (params.get('from') || '').trim().toLowerCase() === 'scale';
  const bus = (params.get('bus') || '').trim().toLowerCase();
  const addrRaw = params.get('addr');
  const addr = addrRaw !== null && /^\d+$/.test(addrRaw) ? parseInt(addrRaw, 10) : null;
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

  const unitSelect = $('#unitBox');
  const calDateInput = $('#calDate');
  const calPeriodInput = $('#calPeriod');
  const usedInput = $('#usedPoints');
  const tableBody = $('#calTable tbody');
  const statusEl = $('#status');
  const btnRead = $('#btnRead');
  const btnRevert = $('#btnRevert');
  const btnSave = $('#btnSave');
  const btnConvert = $('#btnConvert');
  const modeBanner = $('#modeBanner');

  const infoEls = {
    serial: $('#devSerial'),
    type: $('#devType'),
    avail: $('#devAvailChannels'),
    sample: $('#devSampleRate'),
    maxPoints: $('#devMaxPoints'),
    channel: $('#channelFixed'),
  };

  const summaryEls = {
    type: $('#summaryType'),
    slope: $('#summarySlope'),
    offset: $('#summaryOffset'),
    min: $('#summaryMin'),
    max: $('#summaryMax'),
    unit: $('#summaryUnit'),
  };

  const liveEls = {
    measured: $('#liveMeasuredRawValue'),
    current: $('#liveCurrentOutputValue'),
    preview: $('#livePreviewOutputValue'),
  };
  const copyButtons = $$('[data-copy-target]');

  const pointCols = [
    { xml: 'Measure', key: 'measure', label: 'Uncalibrated value' },
    { xml: 'Reference', key: 'reference', label: 'Reference value' },
    { xml: 'Offset', key: 'offset', label: 'Offset' },
    { xml: 'Gain', key: 'gain', label: 'Gain' },
    { xml: 'C2', key: 'c2', label: 'C2' },
    { xml: 'C3', key: 'c3', label: 'C3' },
  ];

  const state = {
    sourceXmlDoc: null,
    selectedCh: requestedCh,
    maxPoints: requestedPoints,
    availableChannels: 64,
    renderRows: Math.max(1, requestedPoints),
    selectedPreviewRow: 0,
    units: [],
    unitSet: new Set(),
    invalidCells: new Map(),
    originalChannelObj: null,
    hasExistingCalibration: false,
    editorMode: 'view',
    protectedByExisting: false,
    liveMeasurement: {
      rawLastMeas: null,
      lastMeas: null,
      unit: null,
    },
    liveTimerId: null,
    editRenewTimerId: null,
    editing: false,
    blockedByOtherSession: false,
    lockRequestPromise: null,
    lockInfo: null,
  };

  const rows = [];

  function setStatus(msg, type = 'info') {
    statusEl.textContent = msg || '';
    statusEl.dataset.state = type;
    statusEl.style.color = type === 'ok' ? '#16a34a' : (type === 'err' ? '#dc2626' : 'var(--color-muted)');
  }

  function lockOwnerText(lock) {
    const ip = lock?.owner?.operator_ip || 'another session';
    const hint = lock?.owner?.session_hint || '';
    return hint ? `${ip} / ${hint}` : ip;
  }

  function setEditingEnabled(enabled, { blocked = false } = {}) {
    state.editing = !!enabled;
    state.blockedByOtherSession = !!blocked;
    applyInteractionState();
    applyUsed();
  }

  function applyInteractionState() {
    const blocked = state.blockedByOtherSession;
    const canEditPoints = isAutoLinearMode() && !blocked;
    const canEditMetadata = !blocked;

    [calDateInput, calPeriodInput].forEach((el) => {
      if (!el) return;
      el.disabled = !canEditMetadata;
      el.style.background = canEditMetadata ? '' : 'var(--color-bg-weak)';
    });

    [usedInput, unitSelect].forEach((el) => {
      if (!el) return;
      el.disabled = !canEditPoints;
      el.style.background = canEditPoints ? '' : 'var(--color-bg-weak)';
    });

    btnSave.disabled = blocked;
    btnRevert.disabled = blocked;
    if (btnConvert) {
      btnConvert.style.display = state.protectedByExisting && !isAutoLinearMode() && !blocked ? '' : 'none';
    }

    if (btnSave) {
      btnSave.textContent = isAutoLinearMode() ? 'Save to SDAQ' : 'Save Metadata';
    }

    if (modeBanner) {
      let text = '';
      if (blocked) {
        text = 'Read-only mode. This device is currently being edited by another session.';
      } else if (isAutoLinearMode()) {
        text = 'Auto linear point editing is active. Offset, Gain, C2, and C3 are calculated automatically.';
      } else if (state.protectedByExisting) {
        text = 'Existing calibration is protected. Metadata can be saved without changing coefficients, or convert to edit calibration points.';
      }
      modeBanner.textContent = text;
      modeBanner.style.display = text ? 'block' : 'none';
    }
  }

  async function sessionFetch(url, options = {}) {
    return fetch(url, {
      cache: 'no-store',
      ...options,
      headers: applySessionHeaders(options.headers || {}),
    });
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

  function stopEditRenewal() {
    if (state.editRenewTimerId) {
      clearInterval(state.editRenewTimerId);
      state.editRenewTimerId = null;
    }
  }

  function stopLivePolling() {
    if (state.liveTimerId) {
      clearInterval(state.liveTimerId);
      state.liveTimerId = null;
    }
  }

  function startEditRenewal() {
    stopEditRenewal();
    state.editRenewTimerId = window.setInterval(async () => {
      if (!state.editing) return;
      try {
        await postEditAction('edit_renew');
      } catch (err) {
        stopEditRenewal();
        if (err?.payload?.lock) {
          applyLockStatus({ locked: true, lock: err.payload.lock }, { silent: true });
        } else {
          applyLockStatus({ locked: false, lock: null }, { silent: true });
        }
        setStatus(err.message || 'Editing lock expired.', 'err');
      }
    }, EDIT_RENEW_MS);
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
        tool: 'calibration',
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
          tool: 'calibration',
        }),
      }).catch(() => {});
    } catch (_) {
      // Best-effort release; TTL expiry remains the fallback.
    }
  }

  function applyLockStatus(lockData, options = {}) {
    state.lockInfo = lockData?.lock || null;
    const locked = Boolean(lockData?.locked);
    const mine = Boolean(lockData?.lock?.owned_by_current_session);

    if (locked && mine) {
      setEditingEnabled(true, { blocked: false });
      startEditRenewal();
      if (!options.silent) {
        setStatus('Editing is active for this device.', 'ok');
      }
      return;
    }

    stopEditRenewal();
    setEditingEnabled(false, { blocked: locked });

    if (locked) {
      const owner = lockOwnerText(lockData.lock);
      if (!options.silent) {
        setStatus(`Read-only mode. This device is currently being edited by another session (${owner}).`, 'info');
      }
      return;
    }

    if (!options.silent) {
      const msg = isProtectedViewMode()
        ? 'Read-only point table. Metadata changes will acquire the edit lock automatically; use Edit Calibration Points to convert.'
        : 'Ready. Editing lock will be acquired automatically when you change a value or save.';
      setStatus(msg, 'info');
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
    if (target === btnRead) return false;
    if (target === btnConvert) return false;
    if (target === btnSave) return true;
    if (target === calDateInput || target === calPeriodInput) return true;
    if ((target === usedInput || target === unitSelect) && isAutoLinearMode()) return true;
    const pointInput = target.closest('#calTable input[data-k]');
    if (!pointInput || !isAutoLinearMode()) return false;
    return isPointInputKey(pointInput.dataset.k || '');
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

  function getText(parent, selector, fallback = '') {
    const node = parent?.querySelector(selector);
    return node ? (node.textContent || '').trim() : fallback;
  }

  function toInt(value, fallback) {
    const n = parseInt(String(value), 10);
    return Number.isFinite(n) ? n : fallback;
  }

  function toFiniteNumber(value) {
    const raw = String(value ?? '').trim();
    if (raw === '' || /^-?nan$/i.test(raw)) return null;
    const n = Number(raw);
    return Number.isFinite(n) ? n : null;
  }

  function isCoeffKey(key) {
    return key === 'offset' || key === 'gain' || key === 'c2' || key === 'c3';
  }

  function isPointInputKey(key) {
    return key === 'measure' || key === 'reference';
  }

  function isAutoLinearMode() {
    return state.editorMode === 'auto-linear';
  }

  function isProtectedViewMode() {
    return state.protectedByExisting && state.editorMode === 'view';
  }

  function valuesDiffer(value, target) {
    const n = toFiniteNumber(value);
    return n !== null && Math.abs(n - target) > 1e-12;
  }

  function channelHasPolynomial(chObj) {
    const used = Math.max(0, Math.min(state.maxPoints, toInt(chObj?.Used_Points ?? 0, 0)));
    for (let i = 0; i < used; i++) {
      const p = chObj?.Points?.[`Point_${i}`] || {};
      if (valuesDiffer(p.c2, 0) || valuesDiffer(p.c3, 0)) {
        return true;
      }
    }
    return false;
  }

  function channelHasExistingCalibration(chObj) {
    const used = Math.max(0, Math.min(state.maxPoints, toInt(chObj?.Used_Points ?? 0, 0)));
    if (used > 0) return true;

    for (let i = 0; i < state.maxPoints; i++) {
      const p = chObj?.Points?.[`Point_${i}`] || {};
      if (valuesDiffer(p.offset, 0) || valuesDiffer(p.gain, 1) || valuesDiffer(p.c2, 0) || valuesDiffer(p.c3, 0)) {
        return true;
      }
    }
    return false;
  }

  function channelLooksLikeScaleResult(chObj) {
    const used = Math.max(0, Math.min(state.maxPoints, toInt(chObj?.Used_Points ?? 0, 0)));
    return used === 2 && !channelHasPolynomial(chObj);
  }

  function toDisplayNumber(value) {
    if (!Number.isFinite(value)) return '-';
    const text = Number(value.toFixed(8)).toString();
    return text === '-0' ? '0' : text;
  }

  function displayWithUnit(value, unit = '') {
    const text = toDisplayNumber(value);
    if (text === '-') return '-';
    return unit ? `${text} ${unit}` : text;
  }

  function updateCopyButtons() {
    copyButtons.forEach((btn) => {
      const target = document.getElementById(btn.dataset.copyTarget || '');
      const text = String(target?.textContent || '').trim();
      const disabled = text === '' || text === '-' || target?.dataset.copyable === '0';
      btn.disabled = disabled;
      btn.title = disabled ? 'No measurement available' : 'Copy';
    });
  }

  async function copyMetric(targetId) {
    const target = document.getElementById(targetId);
    const text = String(target?.textContent || '').trim();
    if (!text || text === '-' || target?.dataset.copyable === '0') return;
    try {
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(text);
      } else {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', 'readonly');
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
      }
      setStatus('Copied value to clipboard.', 'ok');
    } catch (_) {
      setStatus('Copy failed in this browser.', 'err');
    }
  }

  function todayYmd() {
    const d = new Date();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${d.getFullYear()}-${mm}-${dd}`;
  }

  function ymdToSlash(ymd) {
    if (!ymd || !/^\d{4}-\d{2}-\d{2}$/.test(ymd)) return '';
    return ymd.replaceAll('-', '/');
  }

  function slashToYmd(slashDate) {
    if (!slashDate) return '';
    const m = String(slashDate).trim().match(/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/);
    if (!m) return '';
    return `${m[1]}-${String(parseInt(m[2], 10)).padStart(2, '0')}-${String(parseInt(m[3], 10)).padStart(2, '0')}`;
  }

  function selectedChannelTag() {
    return `CH${Math.max(1, state.selectedCh)}`;
  }

  function invalidKey(rowIdx, colKey) {
    return `${rowIdx}:${colKey}`;
  }

  function colLabel(colKey) {
    const found = pointCols.find((c) => c.key === colKey);
    return found ? found.label : colKey;
  }

  function setCellValue(rowIdx, colKey, value) {
    const tr = rows[rowIdx];
    const inp = tr?.querySelector(`input[data-k="${colKey}"]`);
    if (inp) inp.value = value;
  }

  function getCellInput(rowIdx, colKey) {
    return rows[rowIdx]?.querySelector(`input[data-k="${colKey}"]`) || null;
  }

  function setPreviewCell(rowIdx, calcKey, value) {
    const cell = rows[rowIdx]?.querySelector(`td[data-calc="${calcKey}"]`);
    if (cell) cell.textContent = value;
  }

  function clearAllInvalidMarks() {
    state.invalidCells.clear();
    rows.forEach((tr) => {
      $$('input', tr).forEach((inp) => inp.classList.remove('cell-invalid'));
    });
  }

  function markInvalid(rowIdx, colKey, rawValue, reason) {
    const tr = rows[rowIdx];
    const inp = tr?.querySelector(`input[data-k="${colKey}"]`);
    if (inp) inp.classList.add('cell-invalid');
    state.invalidCells.set(invalidKey(rowIdx, colKey), {
      rowIdx,
      colKey,
      rawValue: String(rawValue ?? ''),
      reason: reason || 'invalid value',
    });
  }

  function clearInvalid(rowIdx, colKey) {
    const tr = rows[rowIdx];
    const inp = tr?.querySelector(`input[data-k="${colKey}"]`);
    if (inp) inp.classList.remove('cell-invalid');
    state.invalidCells.delete(invalidKey(rowIdx, colKey));
  }

  function invalidMessage(onlyUsed = true) {
    const used = Math.max(0, Math.min(state.maxPoints, toInt(usedInput.value, 0)));
    const items = Array.from(state.invalidCells.values())
      .filter((it) => !onlyUsed || it.rowIdx < used)
      .sort((a, b) => a.rowIdx - b.rowIdx);
    if (!items.length) return '';
    return items
      .map((it) => `Point_${it.rowIdx} / ${colLabel(it.colKey)}: ${it.reason}`)
      .join('; ');
  }

  function resolveRenderRows(used) {
    const max = Math.max(1, state.maxPoints);
    const preferred = Math.max(DEFAULT_VISIBLE_ROWS, used);
    return Math.min(max, preferred);
  }

  function normalizeScalar(v) {
    if (v === null || v === undefined) return '0';
    const s = String(v).trim();
    if (s === '') return '0';
    if (/^-?nan$/i.test(s)) return 'nan';
    const n = Number(s);
    return Number.isFinite(n) ? String(n) : s;
  }

  function buildRows() {
    tableBody.innerHTML = '';
    rows.length = 0;
    for (let i = 0; i < state.renderRows; i++) {
      const tr = document.createElement('tr');
      tr.dataset.rowIdx = String(i);
      tr.innerHTML = `
        <td><b>${i}</b></td>
        <td><input type="number" step="any" class="input" data-k="measure" value="${DEFAULT_POINT_VALUES.measure}"></td>
        <td><input type="number" step="any" class="input" data-k="reference" value="${DEFAULT_POINT_VALUES.reference}"></td>
        <td><input type="number" step="any" class="input coeff-readonly" data-k="offset" value="${DEFAULT_POINT_VALUES.offset}" readonly></td>
        <td><input type="number" step="any" class="input coeff-readonly" data-k="gain" value="${DEFAULT_POINT_VALUES.gain}" readonly></td>
        <td><input type="number" step="any" class="input coeff-readonly" data-k="c2" value="${DEFAULT_POINT_VALUES.c2}" readonly></td>
        <td><input type="number" step="any" class="input coeff-readonly" data-k="c3" value="${DEFAULT_POINT_VALUES.c3}" readonly></td>
        <td class="calc-cell" data-calc="corrected">-</td>
        <td class="calc-cell" data-calc="difference">-</td>`;
      tableBody.appendChild(tr);
      rows.push(tr);
    }
    syncSelectedRowHighlight();
  }

  function getRowValues(rowIdx) {
    const out = {};
    pointCols.forEach((c) => {
      out[c.key] = String(getCellInput(rowIdx, c.key)?.value || '').trim();
    });
    return out;
  }

  function computeCorrected(values) {
    const measure = toFiniteNumber(values.measure);
    const offset = toFiniteNumber(values.offset);
    const gain = toFiniteNumber(values.gain);
    const c2 = toFiniteNumber(values.c2);
    const c3 = toFiniteNumber(values.c3);
    if ([measure, offset, gain, c2, c3].some((v) => v === null)) return null;
    return offset + (gain * measure) + (c2 * measure * measure) + (c3 * measure * measure * measure);
  }

  function collectUsedPointModels(channelObj) {
    const used = Math.max(0, Math.min(state.maxPoints, toInt(channelObj?.Used_Points ?? 0, 0)));
    const points = [];

    for (let i = 0; i < used; i++) {
      const src = channelObj?.Points?.[`Point_${i}`] || {};
      const measure = toFiniteNumber(src.measure);
      const reference = toFiniteNumber(src.reference);
      const offset = toFiniteNumber(src.offset);
      const gain = toFiniteNumber(src.gain);
      const c2 = toFiniteNumber(src.c2);
      const c3 = toFiniteNumber(src.c3);
      if ([measure, reference, offset, gain, c2, c3].some((v) => v === null)) {
        return null;
      }
      points.push({ measure, reference, offset, gain, c2, c3 });
    }

    return points;
  }

  function computeChannelCorrectValue(channelObj, rawMeasure) {
    if (!Number.isFinite(rawMeasure)) return null;

    const points = collectUsedPointModels(channelObj);
    if (!points) return null;
    if (points.length === 0) return rawMeasure;
    if (points.length === 1) {
      const p = points[0];
      return p.offset + (p.gain * rawMeasure) + (p.c2 * rawMeasure * rawMeasure) + (p.c3 * rawMeasure * rawMeasure * rawMeasure);
    }

    // Sort by Measure ascending so piecewise lookup works regardless of the
    // order the user entered the points (industry convention is to enter rows
    // by Reference; reverse-response sensors then have descending Measure).
    const sorted = [...points].sort((a, b) => a.measure - b.measure);

    let selected = sorted[sorted.length - 1];
    // Piecewise boundary per the auto-linear writer: raw <= Point[i + 1].Measure uses Point_i's segment.
    for (let i = 0; i < sorted.length - 1; i++) {
      if (rawMeasure <= sorted[i + 1].measure) {
        selected = sorted[i];
        break;
      }
    }

    return selected.offset
      + (selected.gain * rawMeasure)
      + (selected.c2 * rawMeasure * rawMeasure)
      + (selected.c3 * rawMeasure * rawMeasure * rawMeasure);
  }

  function previousSinglePointGain() {
    if (!state.originalChannelObj || channelHasPolynomial(state.originalChannelObj)) {
      return 1;
    }
    const gain = toFiniteNumber(state.originalChannelObj?.Points?.Point_0?.gain);
    return gain === null ? 1 : gain;
  }

  function writeCoeff(rowIdx, offset, gain, c2 = 0, c3 = 0) {
    setCellValue(rowIdx, 'offset', normalizeScalar(offset));
    setCellValue(rowIdx, 'gain', normalizeScalar(gain));
    setCellValue(rowIdx, 'c2', normalizeScalar(c2));
    setCellValue(rowIdx, 'c3', normalizeScalar(c3));
  }

  function deriveAutoLinearCoefficients() {
    if (!isAutoLinearMode()) return;

    const used = Math.max(0, Math.min(state.maxPoints, toInt(usedInput.value, 0)));
    if (used === 0) return;

    const points = [];
    for (let i = 0; i < used; i++) {
      const measure = toFiniteNumber(getCellInput(i, 'measure')?.value);
      const reference = toFiniteNumber(getCellInput(i, 'reference')?.value);
      if (measure === null || reference === null) return;
      points.push({ rowIdx: i, measure, reference });
    }

    // Follow industry convention: the user enters rows by Reference (the
    // controlled physical input) in any order they prefer. Internally we sort
    // by Measure ascending because the SDAQ piecewise lookup is keyed on
    // Measure. Duplicate Measure values would make a segment slope infinite,
    // so we still reject them.
    const sorted = [...points].sort((a, b) => a.measure - b.measure);
    for (let i = 1; i < sorted.length; i++) {
      if (sorted[i].measure === sorted[i - 1].measure) return;
    }

    if (used === 1) {
      const gain = previousSinglePointGain();
      const offset = points[0].reference - (gain * points[0].measure);
      writeCoeff(points[0].rowIdx, offset, gain);
      return;
    }

    // Map each UI row to its position in the Measure-sorted order, so each row
    // receives the segment whose first endpoint is that row's own (Measure,
    // Reference). The row with the largest Measure reuses the previous segment.
    const sortedPos = new Map();
    sorted.forEach((p, idx) => sortedPos.set(p.rowIdx, idx));

    for (let i = 0; i < used; i++) {
      const pos = sortedPos.get(i);
      const start = pos < used - 1 ? pos : pos - 1;
      const end = pos < used - 1 ? pos + 1 : pos;
      const x0 = sorted[start].measure;
      const y0 = sorted[start].reference;
      const x1 = sorted[end].measure;
      const y1 = sorted[end].reference;
      const gain = (y1 - y0) / (x1 - x0);
      const offset = y0 - (gain * x0);
      if (!Number.isFinite(gain) || !Number.isFinite(offset)) return;
      writeCoeff(i, offset, gain);
    }
  }

  function computeChannelType(used) {
    if (used === 0) return 'none';

    let hasPoly = false;
    let hasOffsetOnly = false;
    let hasScale = false;

    for (let i = 0; i < used; i++) {
      const row = getRowValues(i);
      const gain = toFiniteNumber(row.gain);
      const offset = toFiniteNumber(row.offset);
      const c2 = toFiniteNumber(row.c2);
      const c3 = toFiniteNumber(row.c3);

      if ((c2 !== null && c2 !== 0) || (c3 !== null && c3 !== 0)) {
        hasPoly = true;
        break;
      }

      if (gain === 1 && offset !== null && offset !== 0) {
        hasOffsetOnly = true;
      } else if (gain !== null || offset !== null) {
        hasScale = true;
      }
    }

    if (hasPoly) return 'polynomial';
    if (used === 1) return 'single-point correction';
    if (used > 2) return 'multi-point linearization';
    if (hasScale) return 'scale + offset';
    if (hasOffsetOnly) return 'offset only';
    return 'none';
  }

  function updateRowPreview(rowIdx) {
    const used = Math.max(0, Math.min(state.maxPoints, toInt(usedInput.value, 0)));
    if (rowIdx >= used) {
      setPreviewCell(rowIdx, 'corrected', '-');
      setPreviewCell(rowIdx, 'difference', '-');
      return;
    }

    const values = getRowValues(rowIdx);
    const corrected = computeCorrected(values);
    const reference = toFiniteNumber(values.reference);
    const difference = corrected !== null && reference !== null ? corrected - reference : null;

    setPreviewCell(rowIdx, 'corrected', corrected === null ? '-' : toDisplayNumber(corrected));
    setPreviewCell(rowIdx, 'difference', difference === null ? '-' : toDisplayNumber(difference));
  }

  function updateAllRowPreviews() {
    rows.forEach((_, idx) => updateRowPreview(idx));
  }

  function updateSummary() {
    const used = Math.max(0, Math.min(state.maxPoints, toInt(usedInput.value, 0)));
    const refs = [];
    const gains = [];
    const offsets = [];
    let hasPoly = false;

    for (let i = 0; i < used; i++) {
      const row = getRowValues(i);
      const ref = toFiniteNumber(row.reference);
      const gain = toFiniteNumber(row.gain);
      const offset = toFiniteNumber(row.offset);
      const c2 = toFiniteNumber(row.c2);
      const c3 = toFiniteNumber(row.c3);
      if (ref !== null) refs.push(ref);
      if (gain !== null) gains.push(gain);
      if (offset !== null) offsets.push(offset);
      if ((c2 !== null && c2 !== 0) || (c3 !== null && c3 !== 0)) hasPoly = true;
    }

    let slopeText = '-';
    if (hasPoly) {
      slopeText = 'polynomial';
    } else if (gains.length) {
      const first = gains[0];
      const same = gains.every((v) => Math.abs(v - first) < 1e-12);
      slopeText = same ? toDisplayNumber(first) : 'mixed';
    }

    let offsetText = '-';
    if (offsets.length) {
      const first = offsets[0];
      const same = offsets.every((v) => Math.abs(v - first) < 1e-12);
      offsetText = same ? toDisplayNumber(first) : 'mixed';
    }

    summaryEls.type.textContent = computeChannelType(used);
    summaryEls.slope.textContent = slopeText;
    summaryEls.offset.textContent = offsetText;
    summaryEls.min.textContent = refs.length ? toDisplayNumber(Math.min(...refs)) : '-';
    summaryEls.max.textContent = refs.length ? toDisplayNumber(Math.max(...refs)) : '-';
    summaryEls.unit.textContent = unitSelect.value || '-';
  }

  function syncSelectedRowHighlight() {
    rows.forEach((tr, idx) => {
      tr.classList.toggle('preview-row-selected', idx === state.selectedPreviewRow);
    });
  }

  function updateLivePreview() {
    syncSelectedRowHighlight();

    const rawMeasure = state.liveMeasurement.rawLastMeas;
    deriveAutoLinearCoefficients();
    const currentObj = collectChannelObjFromForm();
    const preview = isAutoLinearMode() ? computeChannelCorrectValue(currentObj, rawMeasure) : null;
    const unit = unitSelect.value || state.liveMeasurement.unit || '';
    const currentUnit = state.liveMeasurement.unit || unit;

    liveEls.measured.textContent = displayWithUnit(rawMeasure, '');
    liveEls.current.textContent = displayWithUnit(state.liveMeasurement.lastMeas, currentUnit);
    liveEls.preview.textContent = isAutoLinearMode() ? displayWithUnit(preview, unit) : '= Current Device Output';
    liveEls.measured.dataset.copyable = Number.isFinite(rawMeasure) ? '1' : '0';
    liveEls.current.dataset.copyable = Number.isFinite(state.liveMeasurement.lastMeas) ? '1' : '0';
    liveEls.preview.dataset.copyable = isAutoLinearMode() && Number.isFinite(preview) ? '1' : '0';
    updateCopyButtons();
  }

  function updateDerivedViews() {
    deriveAutoLinearCoefficients();
    updateAllRowPreviews();
    updateSummary();
    updateLivePreview();
  }

  function channelNodeToObject(chNode) {
    if (!chNode) {
      return {
        Calibration_date: ymdToSlash(todayYmd()),
        Calibration_Period: '0',
        Used_Points: '0',
        Unit: requestedUnit || '',
        Points: {},
      };
    }

    const out = {
      Calibration_date: getText(chNode, ':scope > Calibration_date', ymdToSlash(todayYmd())),
      Calibration_Period: getText(chNode, ':scope > Calibration_Period', '0'),
      Used_Points: getText(chNode, ':scope > Used_Points', '0'),
      Unit: getText(chNode, ':scope > Unit', requestedUnit || ''),
      Points: {},
    };

    for (let i = 0; i < state.maxPoints; i++) {
      const point = chNode.querySelector(`:scope > Points > Point_${i}`);
      const p = {};
      pointCols.forEach((c) => {
        p[c.key] = getText(point, `:scope > ${c.xml}`, '0');
      });
      out.Points[`Point_${i}`] = p;
    }
    return out;
  }

  function collectChannelObjFromForm() {
    const used = Math.max(0, Math.min(state.maxPoints, toInt(usedInput.value, 0)));
    const obj = {
      Calibration_date: ymdToSlash(calDateInput.value) || ymdToSlash(todayYmd()),
      Calibration_Period: String(Math.max(0, toInt(calPeriodInput.value, 0))),
      Used_Points: String(used),
      Unit: String(unitSelect.value || ''),
      Points: {},
    };

    for (let i = 0; i < state.maxPoints; i++) {
      const p = {};
      pointCols.forEach((c) => {
        if (i >= used) {
          p[c.key] = DEFAULT_POINT_VALUES[c.key] ?? '0';
          return;
        }
        const raw = String(getCellInput(i, c.key)?.value || '').trim();
        p[c.key] = normalizeScalar(raw);
      });
      obj.Points[`Point_${i}`] = p;
    }
    return obj;
  }

  function collectMetadataOnlyChannelObj() {
    const base = JSON.parse(JSON.stringify(state.originalChannelObj || {}));
    base.Calibration_date = ymdToSlash(calDateInput.value) || ymdToSlash(todayYmd());
    base.Calibration_Period = String(Math.max(0, toInt(calPeriodInput.value, 0)));
    base.Used_Points = String(Math.max(0, Math.min(state.maxPoints, toInt(base.Used_Points ?? 0, 0))));
    base.Unit = String(base.Unit ?? '');
    base.Points = base.Points || {};
    for (let i = 0; i < state.maxPoints; i++) {
      base.Points[`Point_${i}`] = base.Points[`Point_${i}`] || { ...DEFAULT_POINT_VALUES };
    }
    return base;
  }

  function hasMetadataDiff(currentObj, originalObj) {
    return normalizeScalar(currentObj?.Calibration_date) !== normalizeScalar(originalObj?.Calibration_date)
      || normalizeScalar(currentObj?.Calibration_Period) !== normalizeScalar(originalObj?.Calibration_Period);
  }

  function normalizeChannelForDiff(chObj) {
    const used = Math.max(0, Math.min(state.maxPoints, toInt(chObj?.Used_Points ?? 0, 0)));
    const out = {
      Calibration_date: normalizeScalar(chObj?.Calibration_date),
      Calibration_Period: normalizeScalar(chObj?.Calibration_Period),
      Used_Points: String(used),
      Unit: String(chObj?.Unit ?? ''),
      Points: {},
    };

    for (let i = 0; i < state.maxPoints; i++) {
      const src = chObj?.Points?.[`Point_${i}`] || {};
      const p = {};
      pointCols.forEach((c) => {
        p[c.key] = i < used
          ? normalizeScalar(src[c.key])
          : (DEFAULT_POINT_VALUES[c.key] ?? '0');
      });
      out.Points[`Point_${i}`] = p;
    }
    return out;
  }

  function hasSelectedChannelDiff(currentObj, originalObj) {
    return JSON.stringify(normalizeChannelForDiff(currentObj)) !== JSON.stringify(normalizeChannelForDiff(originalObj));
  }

  function createEmptyDoc() {
    return new DOMParser().parseFromString('<?xml version="1.0" encoding="utf-8"?><SDAQ/>', 'application/xml');
  }

  function appendTextNode(doc, parent, tag, value) {
    const n = doc.createElement(tag);
    n.appendChild(doc.createTextNode(String(value ?? '')));
    parent.appendChild(n);
  }

  function pointValueForXml(value, preserveRaw) {
    return preserveRaw ? String(value ?? '') : normalizeScalar(value);
  }

  function buildSaveXmlOnlySelectedChannel(channelObj, options = {}) {
    const preserveRawPointValues = !!options.preserveRawPointValues;
    const outDoc = createEmptyDoc();
    const sdaqRoot = outDoc.documentElement;
    const srcInfo = state.sourceXmlDoc?.querySelector('SDAQ > SDAQ_info');
    const dstInfo = outDoc.createElement('SDAQ_info');
    if (srcInfo) {
      Array.from(srcInfo.children || []).forEach((child) => {
        appendTextNode(outDoc, dstInfo, child.nodeName, child.textContent || '');
      });
    }
    sdaqRoot.appendChild(dstInfo);

    const calData = outDoc.createElement('Calibration_Data');
    const chNode = outDoc.createElement(selectedChannelTag());
    appendTextNode(outDoc, chNode, 'Calibration_date', channelObj.Calibration_date);
    appendTextNode(outDoc, chNode, 'Calibration_Period', channelObj.Calibration_Period);
    appendTextNode(outDoc, chNode, 'Used_Points', channelObj.Used_Points);
    appendTextNode(outDoc, chNode, 'Unit', channelObj.Unit);

    const pointsNode = outDoc.createElement('Points');
    const used = Math.max(0, Math.min(state.maxPoints, toInt(channelObj.Used_Points, 0)));
    for (let i = 0; i < used; i++) {
      const pNode = outDoc.createElement(`Point_${i}`);
      const p = channelObj.Points[`Point_${i}`] || {};
      pointCols.forEach((c) => appendTextNode(outDoc, pNode, c.xml, pointValueForXml(p[c.key], preserveRawPointValues)));
      pointsNode.appendChild(pNode);
    }
    chNode.appendChild(pointsNode);
    calData.appendChild(chNode);
    sdaqRoot.appendChild(calData);
    return new XMLSerializer().serializeToString(outDoc);
  }

  function ensureRowCapacity(targetUsed, preserveCurrent = true) {
    const desired = resolveRenderRows(targetUsed);
    if (desired === rows.length) return;

    const snapshot = preserveCurrent && rows.length ? collectChannelObjFromForm() : null;
    state.renderRows = desired;
    buildRows();
    if (snapshot) {
      fillFormFromChannelObj(snapshot, { skipResize: true, suppressStatus: true });
    }
  }

  function fillFormFromChannelObj(chObj, options = {}) {
    clearAllInvalidMarks();

    const used = Math.max(0, Math.min(state.maxPoints, toInt(chObj?.Used_Points ?? 0, 0)));
    if (!options.skipResize) {
      state.renderRows = resolveRenderRows(used);
      buildRows();
    }

    usedInput.value = String(used);
    calDateInput.value = slashToYmd(chObj?.Calibration_date || '') || todayYmd();
    calPeriodInput.value = String(Math.max(0, toInt(chObj?.Calibration_Period ?? 0, 0)));
    unitSelect.value = state.unitSet.has(String(chObj?.Unit ?? '')) ? String(chObj.Unit) : '';

    for (let i = 0; i < rows.length; i++) {
      const point = chObj?.Points?.[`Point_${i}`] || {};
      const isUsed = i < used;

      pointCols.forEach((c) => {
        if (!isUsed) {
          setCellValue(i, c.key, '0');
          clearInvalid(i, c.key);
          return;
        }

        const raw = String(point[c.key] ?? '0').trim();
        if (raw === '' || /^-?nan$/i.test(raw)) {
          setCellValue(i, c.key, '');
          markInvalid(i, c.key, raw, raw === '' ? 'empty value' : 'non-finite value');
          return;
        }

        const num = Number(raw);
        if (!Number.isFinite(num)) {
          setCellValue(i, c.key, '');
          markInvalid(i, c.key, raw, 'non-finite value');
          return;
        }

        setCellValue(i, c.key, String(num));
        clearInvalid(i, c.key);
      });
    }

    state.selectedPreviewRow = used > 0 ? Math.min(state.selectedPreviewRow, used - 1) : 0;
    applyUsed();
    validateMeasureDistinct(false);
    updateDerivedViews();
    const msg = invalidMessage(true);
    if (!options.suppressStatus) {
      setStatus(msg || `Loaded ${selectedChannelTag()}`, msg ? 'err' : 'ok');
    }
  }

  async function fetchUnits() {
    const res = await sessionFetch(buildApiUrl({ action: 'units' }), {
      method: 'GET',
      headers: { Accept: 'application/json' },
    });
    if (!res.ok) throw new Error(`Units request failed: HTTP ${res.status}`);
    const payload = await res.json();
    if (!payload?.ok || !Array.isArray(payload?.data?.SDAQ_UNITs)) {
      throw new Error(payload?.error || 'Invalid units payload');
    }

    state.units = payload.data.SDAQ_UNITs.map((v) => String(v));
    state.unitSet = new Set(state.units);
    unitSelect.innerHTML = '<option value="">Select unit</option>';
    state.units.forEach((u) => {
      const opt = document.createElement('option');
      opt.value = u;
      opt.textContent = u;
      unitSelect.appendChild(opt);
    });
  }

  async function fetchCalibrationXml() {
    if (!bus || addr === null) throw new Error('Missing bus/addr in popup URL');
    const res = await sessionFetch(buildApiUrl({
      action: 'xml',
      bus,
      addr: String(addr),
    }), {
      method: 'GET',
      headers: { Accept: 'application/xml, text/xml' },
    });
    if (!res.ok) {
      const t = await res.text();
      throw new Error(t || `Calibration XML request failed: HTTP ${res.status}`);
    }

    const xmlText = await res.text();
    const xmlDoc = new DOMParser().parseFromString(xmlText, 'application/xml');
    if (xmlDoc.querySelector('parsererror')) throw new Error('Failed to parse calibration XML');

    state.sourceXmlDoc = xmlDoc;
    const maxFromInfo = toInt(getText(xmlDoc, 'SDAQ > SDAQ_info > Max_num_of_cal_points', String(requestedPoints)), requestedPoints);
    const chFromInfo = toInt(getText(xmlDoc, 'SDAQ > SDAQ_info > Available_Channels', '64'), 64);
    state.maxPoints = Math.max(1, maxFromInfo);
    state.availableChannels = Math.max(1, chFromInfo);
    state.selectedCh = Math.min(Math.max(1, requestedCh), state.availableChannels);
    state.renderRows = resolveRenderRows(0);
  }

  async function fetchLiveMeasurement() {
    if (!bus || addr === null) throw new Error('Missing bus/addr in popup URL');
    const res = await sessionFetch(buildApiUrl({
      action: 'live_measurement',
      bus,
      addr: String(addr),
      ch: String(state.selectedCh),
    }), {
      method: 'GET',
      headers: { Accept: 'application/json' },
    });

    const payload = await res.json().catch(() => ({}));
    if (!res.ok || !payload?.ok) {
      throw new Error(payload?.error || `Live measurement request failed: HTTP ${res.status}`);
    }

    const data = payload.data || {};
    state.liveMeasurement.rawLastMeas = Number.isFinite(Number(data.raw_last_meas)) ? Number(data.raw_last_meas) : null;
    state.liveMeasurement.lastMeas = Number.isFinite(Number(data.last_meas)) ? Number(data.last_meas) : null;
    state.liveMeasurement.unit = typeof data.unit === 'string' ? data.unit : null;
  }

  async function refreshLiveMeasurement({ silent = true } = {}) {
    try {
      await fetchLiveMeasurement();
      updateLivePreview();
    } catch (err) {
      state.liveMeasurement.rawLastMeas = null;
      state.liveMeasurement.lastMeas = null;
      state.liveMeasurement.unit = null;
      updateLivePreview();
      if (!silent) {
        throw err;
      }
    }
  }

  function startLivePolling() {
    stopLivePolling();
    state.liveTimerId = window.setInterval(() => {
      refreshLiveMeasurement({ silent: true });
    }, LIVE_POLL_MS);
  }

  function populateInfo() {
    const setIf = (el, val) => { if (el) el.textContent = val; };
    const info = state.sourceXmlDoc?.querySelector('SDAQ > SDAQ_info');
    setIf(infoEls.serial, getText(info, ':scope > SerialNumber', requestedSn || '-'));
    setIf(infoEls.type, getText(info, ':scope > Type', '-'));
    setIf(infoEls.avail, getText(info, ':scope > Available_Channels', '-'));
    setIf(infoEls.sample, getText(info, ':scope > Samplerate', '-'));
    setIf(infoEls.maxPoints, getText(info, ':scope > Max_num_of_cal_points', '-'));
    setIf(infoEls.channel, `CH ${state.selectedCh}`);
  }

  function loadSelectedChannelFromXml() {
    const chNode = state.sourceXmlDoc?.querySelector(`SDAQ > Calibration_Data > ${selectedChannelTag()}`);
    const chObj = channelNodeToObject(chNode);
    state.originalChannelObj = JSON.parse(JSON.stringify(chObj));
    state.hasExistingCalibration = channelHasExistingCalibration(chObj);
    const allowScaleContinuation = fromScale && channelLooksLikeScaleResult(chObj);
    state.protectedByExisting = state.hasExistingCalibration && !allowScaleContinuation;
    state.editorMode = state.protectedByExisting ? 'view' : 'auto-linear';
    fillFormFromChannelObj(chObj);
    applyInteractionState();
  }

  function applyUsed() {
    const used = Math.max(0, Math.min(state.maxPoints, toInt(usedInput.value, 0)));
    if (String(used) !== usedInput.value) usedInput.value = String(used);

    rows.forEach((tr, idx) => {
      const active = idx < used;
      const canEditPoints = isAutoLinearMode() && !state.blockedByOtherSession;
      tr.classList.toggle('row-disabled', !active);
      $$('input', tr).forEach((inp) => {
        const key = inp.dataset.k || '';
        inp.disabled = !active || state.blockedByOtherSession || !canEditPoints || !isPointInputKey(key);
        inp.readOnly = isCoeffKey(key);
        inp.classList.toggle('coeff-readonly', isCoeffKey(key));
      });
      if (!active) {
        pointCols.forEach((c) => {
          const el = tr.querySelector(`input[data-k="${c.key}"]`);
          if (el) el.value = DEFAULT_POINT_VALUES[c.key] ?? '0';
          clearInvalid(idx, c.key);
        });
      }
    });

    if (used === 0) state.selectedPreviewRow = 0;
    else if (state.selectedPreviewRow >= used) state.selectedPreviewRow = Math.max(used - 1, 0);

    applyInteractionState();
    updateDerivedViews();
  }

  // Industry-convention calibration tools sort points by Reference and accept
  // any Measure ordering (forward, reverse-response, or non-monotonic sensors).
  // The only hard requirement is that no two used Measure values are equal,
  // otherwise the segment slope between them would be infinite.
  function validateMeasureDistinct(throwOnError = true) {
    const used = Math.max(0, Math.min(state.maxPoints, toInt(usedInput.value, 0)));
    const seen = new Map();
    for (let i = 0; i < used; i++) {
      const value = toFiniteNumber(getCellInput(i, 'measure')?.value);
      if (value === null) continue;
      if (seen.has(value)) {
        const firstIdx = seen.get(value);
        markInvalid(i, 'measure', getCellInput(i, 'measure')?.value, `value must be unique among ${colLabel('measure')} (duplicate of Point_${firstIdx})`);
        const msg = invalidMessage(true);
        if (throwOnError) throw new Error(msg);
        setStatus(msg, 'err');
        return false;
      }
      seen.set(value, i);
    }
    return true;
  }

  function validateBeforeSave() {
    if (!state.unitSet.has(unitSelect.value)) {
      throw new Error('CH\'s Unit must be selected from supported SDAQ units');
    }

    const used = toInt(usedInput.value, -1);
    if (!Number.isInteger(used) || used < 0 || used > state.maxPoints) {
      throw new Error(`Used Points must be an integer between 0 and ${state.maxPoints}`);
    }

    clearAllInvalidMarks();
    deriveAutoLinearCoefficients();
    for (let i = 0; i < used; i++) {
      pointCols.forEach((c) => {
        if (isAutoLinearMode() && isCoeffKey(c.key)) {
          return;
        }
        const raw = String(getCellInput(i, c.key)?.value || '').trim();
        if (raw === '') {
          markInvalid(i, c.key, raw, 'empty value');
          return;
        }
        if (/^-?nan$/i.test(raw)) {
          markInvalid(i, c.key, raw, 'non-finite value');
          return;
        }
        const value = Number(raw);
        if (!Number.isFinite(value)) {
          markInvalid(i, c.key, raw, 'non-finite value');
        }
      });
    }

    const fieldErrors = invalidMessage(true);
    if (fieldErrors) throw new Error(fieldErrors);
    validateMeasureDistinct(true);
  }

  function validateMetadataBeforeSave() {
    if (!calDateInput.value || !/^\d{4}-\d{2}-\d{2}$/.test(calDateInput.value)) {
      throw new Error('Calibration date must be a valid date');
    }

    const period = toInt(calPeriodInput.value, -1);
    if (!Number.isInteger(period) || period < 0) {
      throw new Error('Calibration period must be a non-negative integer');
    }
  }

  async function saveSelectedChannel() {
    const acquired = await ensureEditLock({ silent: true });
    if (!acquired) {
      throw new Error('This device is currently being edited by another session.');
    }

    const saveMode = isAutoLinearMode() ? 'auto-linear' : 'legacy';
    if (saveMode === 'auto-linear') {
      // Stamp today as the calibration date whenever the point table is saved
      calDateInput.value = todayYmd();
      validateBeforeSave();
      deriveAutoLinearCoefficients();
    } else {
      validateMetadataBeforeSave();
    }

    const currentObj = saveMode === 'auto-linear'
      ? collectChannelObjFromForm()
      : collectMetadataOnlyChannelObj();
    const changed = saveMode === 'auto-linear'
      ? hasSelectedChannelDiff(currentObj, state.originalChannelObj)
      : hasMetadataDiff(currentObj, state.originalChannelObj);
    if (!changed) {
      setStatus(`No changes for ${selectedChannelTag()}.`, 'info');
      return null;
    }

    const xmlContent = buildSaveXmlOnlySelectedChannel(currentObj, { preserveRawPointValues: saveMode === 'legacy' });
    const payload = {
      action: 'calibration_save',
      mode: saveMode,
      bus,
      addr,
      xmlContent,
    };
    const res = await sessionFetch(buildApiUrl(), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(payload),
    });

    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data?.ok) throw new Error(data?.error || `Save failed: HTTP ${res.status}`);
    if (saveMode === 'auto-linear') {
      await fetchCalibrationXml();
      populateInfo();
      // loadSelectedChannelFromXml sets editorMode/protectedByExisting correctly
      loadSelectedChannelFromXml();
      updateDerivedViews();
    } else {
      state.originalChannelObj = JSON.parse(JSON.stringify(currentObj));
      state.hasExistingCalibration = channelHasExistingCalibration(currentObj);
      state.protectedByExisting = state.hasExistingCalibration;
      state.editorMode = state.protectedByExisting ? 'view' : 'auto-linear';
      applyInteractionState();
    }
    return data;
  }

  async function readFromSdaq() {
    setStatus('Reading calibration from SDAQ...', 'info');
    stopLivePolling();
    await fetchCalibrationXml();
    populateInfo();
    loadSelectedChannelFromXml();
    await refreshLiveMeasurement({ silent: false });
    startLivePolling();
    setStatus(`Loaded calibration for ${bus.toUpperCase()} addr ${addr} CH ${state.selectedCh}`, 'ok');
  }

  function revertToLoadedState() {
    if (!state.originalChannelObj) {
      setStatus('Nothing to revert.', 'info');
      return;
    }
    fillFormFromChannelObj(JSON.parse(JSON.stringify(state.originalChannelObj)));
    const allowScaleContinuation = fromScale && channelLooksLikeScaleResult(state.originalChannelObj);
    if (state.hasExistingCalibration && !allowScaleContinuation) {
      state.editorMode = 'view';
      state.protectedByExisting = true;
    }
    applyInteractionState();
    setStatus(`Discarded changes for ${selectedChannelTag()} and restored the last loaded state.`, 'ok');
  }

  async function releaseEditLockIfHeld() {
    if (!state.editing) return;
    try {
      await postEditAction('edit_end');
    } catch (_) {
      // TTL expiry remains the fallback.
    } finally {
      stopEditRenewal();
      state.editing = false;
      applyInteractionState();
      applyUsed();
    }
  }

  async function convertToAutoLinear() {
    const confirmed = window.confirm(
      'This channel already has calibration coefficients.\n\n'
      + 'Converting will recalculate Offset/Gain from Uncalibrated/Reference points, '
      + 'set C2/C3 to 0, and overwrite the existing coefficients when saved.\n\n'
      + 'Continue?'
    );
    if (!confirmed) {
      setStatus('Conversion canceled.', 'info');
      return;
    }

    const acquired = await ensureEditLock({ silent: true });
    if (!acquired) {
      state.editorMode = 'view';
      const allowScaleContinuation = fromScale && channelLooksLikeScaleResult(state.originalChannelObj);
      state.protectedByExisting = state.hasExistingCalibration && !allowScaleContinuation;
      applyInteractionState();
      applyUsed();
      setStatus('This device is currently being edited by another session.', 'err');
      return;
    }

    state.editorMode = 'auto-linear';
    state.protectedByExisting = false;
    deriveAutoLinearCoefficients();
    applyInteractionState();
    applyUsed();
    setStatus('Auto linear point editing is active.', 'ok');
  }

  tableBody.addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-row-idx]');
    if (!tr) return;
    const idx = toInt(tr.dataset.rowIdx, 0);
    state.selectedPreviewRow = idx;
    updateLivePreview();
  });

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-copy-target]');
    if (!btn) return;
    copyMetric(btn.dataset.copyTarget || '');
  });

  tableBody.addEventListener('input', (e) => {
    const inp = e.target.closest('input[data-k]');
    if (!inp) return;
    const tr = inp.closest('tr');
    const rowIdx = rows.indexOf(tr);
    const colKey = inp.dataset.k || '';
    const used = Math.max(0, Math.min(state.maxPoints, toInt(usedInput.value, 0)));
    state.selectedPreviewRow = rowIdx;

    if (rowIdx >= used) {
      inp.value = '0';
      clearInvalid(rowIdx, colKey);
      updateLivePreview();
      return;
    }

    const raw = String(inp.value || '').trim();
    if (raw === '') {
      markInvalid(rowIdx, colKey, raw, 'empty value');
    } else if (/^-?nan$/i.test(raw)) {
      markInvalid(rowIdx, colKey, raw, 'non-finite value');
    } else if (!Number.isFinite(Number(raw))) {
      markInvalid(rowIdx, colKey, raw, 'non-finite value');
    } else {
      clearInvalid(rowIdx, colKey);
      if (colKey === 'measure') {
        validateMeasureDistinct(false);
      }
    }

    updateDerivedViews();
    const msg = invalidMessage(true);
    if (msg) setStatus(msg, 'err');
    else setStatus('', 'info');
  });

  document.addEventListener('pointerdown', (e) => {
    const target = e.target.closest('input, select, button');
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

  usedInput.addEventListener('input', () => {
    const targetUsed = Math.max(0, Math.min(state.maxPoints, toInt(usedInput.value, 0)));
    ensureRowCapacity(targetUsed, true);
    applyUsed();
    const msg = invalidMessage(true);
    if (msg) setStatus(msg, 'err');
  });

  unitSelect.addEventListener('change', updateDerivedViews);
  calDateInput.addEventListener('input', () => setStatus('', 'info'));
  calPeriodInput.addEventListener('input', () => setStatus('', 'info'));

  btnRead.addEventListener('click', async () => {
    try {
      await readFromSdaq();
    } catch (err) {
      setStatus(err.message || 'Failed to read calibration data', 'err');
    }
  });

  btnConvert?.addEventListener('click', () => {
    window.setTimeout(async () => {
      try {
        await convertToAutoLinear();
      } catch (err) {
        setStatus(err.message || 'Failed to enter auto linear editing', 'err');
      }
    }, 0);
  });

  btnRevert.addEventListener('click', async () => {
    revertToLoadedState();
    await releaseEditLockIfHeld();
  });

  btnSave.addEventListener('click', async () => {
    try {
      setStatus('Saving calibration...', 'info');
      const data = await saveSelectedChannel();
      if (data) {
        setStatus(data.message || 'Saved', 'ok');
        await releaseEditLockIfHeld();
      }
    } catch (err) {
      setStatus(err.message || 'Save failed', 'err');
    }
  });

  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && String(e.key).toLowerCase() === 's' && !state.blockedByOtherSession) {
      e.preventDefault();
      btnSave?.click();
      return;
    }
    if (e.key === 'Escape') window.close();
  });

  window.addEventListener('pagehide', releaseEditLockOnUnload);
  window.addEventListener('beforeunload', releaseEditLockOnUnload);
  window.addEventListener('pagehide', stopLivePolling);
  window.addEventListener('beforeunload', stopLivePolling);

  (async function init() {
    try {
      state.renderRows = DEFAULT_VISIBLE_ROWS;
      buildRows();
      usedInput.value = '0';
      calPeriodInput.value = '0';
      calDateInput.value = todayYmd();
      setEditingEnabled(false);
      updateDerivedViews();

      await fetchUnits();
      await readFromSdaq();
      const lockData = await fetchEditStatus();
      applyLockStatus(lockData);
    } catch (err) {
      setStatus(err.message || 'Failed to load calibration data', 'err');
    }
  })();
})();
