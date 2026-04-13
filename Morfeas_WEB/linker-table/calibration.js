(() => {
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

  const DEFAULT_VISIBLE_ROWS = 10;
  const LIVE_POLL_MS = 1000;
  const DEFAULT_POINT_VALUES = {
    measure: '0',
    reference: '0',
    offset: '0',
    gain: '1',
    c2: '0',
    c3: '0',
  };

  const params = new URLSearchParams(location.search);
  const requestedPoints = Math.max(1, parseInt(params.get('points') || '8', 10));
  const requestedCh = Math.max(1, parseInt(params.get('ch') || '1', 10));
  const requestedUnit = params.get('unit') || '';
  const requestedSn = (params.get('sn') || '').trim();
  const bus = (params.get('bus') || '').trim().toLowerCase();
  const addrRaw = params.get('addr');
  const addr = addrRaw !== null && /^\d+$/.test(addrRaw) ? parseInt(addrRaw, 10) : null;
  const apiUrl = new URL('../backend/api_calibration.php', window.location.href);

  const unitSelect = $('#unitBox');
  const calDateInput = $('#calDate');
  const calPeriodInput = $('#calPeriod');
  const usedInput = $('#usedPoints');
  const tableBody = $('#calTable tbody');
  const statusEl = $('#status');
  const btnRead = $('#btnRead');
  const btnRevert = $('#btnRevert');
  const btnSave = $('#btnSave');

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
    correct: $('#liveCorrectValue'),
  };

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
    liveMeasurement: {
      rawLastMeas: null,
      lastMeas: null,
      unit: null,
    },
    liveTimerId: null,
  };

  const rows = [];

  function setStatus(msg, type = 'info') {
    statusEl.textContent = msg || '';
    statusEl.dataset.state = type;
    statusEl.style.color = type === 'ok' ? '#16a34a' : (type === 'err' ? '#dc2626' : 'var(--color-muted)');
  }

  function getText(parent, selector, fallback = '') {
    const node = parent?.querySelector(selector);
    return node?.textContent?.trim() || fallback;
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
        <td><input type="number" step="any" class="input" data-k="offset" value="${DEFAULT_POINT_VALUES.offset}"></td>
        <td><input type="number" step="any" class="input" data-k="gain" value="${DEFAULT_POINT_VALUES.gain}"></td>
        <td><input type="number" step="any" class="input" data-k="c2" value="${DEFAULT_POINT_VALUES.c2}"></td>
        <td><input type="number" step="any" class="input" data-k="c3" value="${DEFAULT_POINT_VALUES.c3}"></td>
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

    let selected = points[points.length - 1];
    for (let i = 0; i < points.length - 1; i++) {
      if (rawMeasure <= points[i + 1].measure) {
        selected = points[i];
        break;
      }
    }

    return selected.offset
      + (selected.gain * rawMeasure)
      + (selected.c2 * rawMeasure * rawMeasure)
      + (selected.c3 * rawMeasure * rawMeasure * rawMeasure);
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
    const currentObj = collectChannelObjFromForm();
    const corrected = computeChannelCorrectValue(currentObj, rawMeasure);
    const unit = unitSelect.value || state.liveMeasurement.unit || '';

    liveEls.measured.textContent = displayWithUnit(rawMeasure, '');
    liveEls.correct.textContent = displayWithUnit(corrected, unit);
  }

  function updateDerivedViews() {
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
          p[c.key] = '0';
          if (c.key === 'gain') p[c.key] = '0';
          return;
        }
        const raw = String(getCellInput(i, c.key)?.value || '').trim();
        p[c.key] = normalizeScalar(raw);
      });
      obj.Points[`Point_${i}`] = p;
    }
    return obj;
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
        p[c.key] = i < used ? normalizeScalar(src[c.key]) : '0';
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

  function buildSaveXmlOnlySelectedChannel(channelObj) {
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
      pointCols.forEach((c) => appendTextNode(outDoc, pNode, c.xml, normalizeScalar(p[c.key])));
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
    validateMeasureAscending(false);
    updateDerivedViews();
    const msg = invalidMessage(true);
    if (!options.suppressStatus) {
      setStatus(msg || `Loaded ${selectedChannelTag()}`, msg ? 'err' : 'ok');
    }
  }

  async function fetchUnits() {
    const url = new URL(apiUrl.toString());
    url.searchParams.set('action', 'units');
    const res = await fetch(url.toString(), {
      method: 'GET',
      cache: 'no-store',
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

    const url = new URL(apiUrl.toString());
    url.searchParams.set('action', 'xml');
    url.searchParams.set('bus', bus);
    url.searchParams.set('addr', String(addr));

    const res = await fetch(url.toString(), {
      method: 'GET',
      cache: 'no-store',
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

    const url = new URL(apiUrl.toString());
    url.searchParams.set('action', 'live_measurement');
    url.searchParams.set('bus', bus);
    url.searchParams.set('addr', String(addr));
    url.searchParams.set('ch', String(state.selectedCh));

    const res = await fetch(url.toString(), {
      method: 'GET',
      cache: 'no-store',
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
    if (state.liveTimerId) {
      clearInterval(state.liveTimerId);
    }
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
    fillFormFromChannelObj(chObj);
  }

  function applyUsed() {
    const used = Math.max(0, Math.min(state.maxPoints, toInt(usedInput.value, 0)));
    if (String(used) !== usedInput.value) usedInput.value = String(used);

    rows.forEach((tr, idx) => {
      const active = idx < used;
      tr.classList.toggle('row-disabled', !active);
      $$('input', tr).forEach((inp) => {
        inp.disabled = !active;
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

    updateDerivedViews();
  }

  function validateMeasureAscending(throwOnError = true) {
    const used = Math.max(0, Math.min(state.maxPoints, toInt(usedInput.value, 0)));
    for (let i = 1; i < used; i++) {
      const prev = toFiniteNumber(getCellInput(i - 1, 'measure')?.value);
      const curr = toFiniteNumber(getCellInput(i, 'measure')?.value);
      if (prev !== null && curr !== null && curr <= prev) {
        markInvalid(i, 'measure', getCellInput(i, 'measure')?.value, `value must be greater than previous ${colLabel('measure')}`);
        const msg = invalidMessage(true);
        if (throwOnError) throw new Error(msg);
        setStatus(msg, 'err');
        return false;
      }
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
    for (let i = 0; i < used; i++) {
      pointCols.forEach((c) => {
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
    validateMeasureAscending(true);
  }

  async function saveSelectedChannel() {
    validateBeforeSave();
    const currentObj = collectChannelObjFromForm();
    if (!hasSelectedChannelDiff(currentObj, state.originalChannelObj)) {
      setStatus(`No changes for ${selectedChannelTag()}.`, 'info');
      return null;
    }

    const xmlContent = buildSaveXmlOnlySelectedChannel(currentObj);
    const payload = { action: 'calibration_save', bus, addr, xmlContent };
    const res = await fetch(apiUrl.toString(), {
      method: 'POST',
      cache: 'no-store',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(payload),
    });

    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data?.ok) throw new Error(data?.error || `Save failed: HTTP ${res.status}`);
    state.originalChannelObj = JSON.parse(JSON.stringify(currentObj));
    return data;
  }

  async function readFromSdaq() {
    setStatus('Reading calibration from SDAQ...', 'info');
    await fetchCalibrationXml();
    populateInfo();
    loadSelectedChannelFromXml();
    await refreshLiveMeasurement({ silent: false });
    setStatus(`Loaded calibration for ${bus.toUpperCase()} addr ${addr} CH ${state.selectedCh}`, 'ok');
  }

  function revertToLoadedState() {
    if (!state.originalChannelObj) {
      setStatus('Nothing to revert.', 'info');
      return;
    }
    fillFormFromChannelObj(JSON.parse(JSON.stringify(state.originalChannelObj)));
    setStatus(`Discarded changes for ${selectedChannelTag()} and restored the last loaded state.`, 'ok');
  }

  tableBody.addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-row-idx]');
    if (!tr) return;
    const idx = toInt(tr.dataset.rowIdx, 0);
    state.selectedPreviewRow = idx;
    updateLivePreview();
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
        validateMeasureAscending(false);
      }
    }

    updateDerivedViews();
    const msg = invalidMessage(true);
    if (msg) setStatus(msg, 'err');
    else setStatus('', 'info');
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

  btnRevert.addEventListener('click', () => {
    revertToLoadedState();
  });

  btnSave.addEventListener('click', async () => {
    try {
      setStatus('Saving calibration...', 'info');
      const data = await saveSelectedChannel();
      if (data) setStatus(data.message || 'Saved', 'ok');
    } catch (err) {
      setStatus(err.message || 'Save failed', 'err');
    }
  });

  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && String(e.key).toLowerCase() === 's') {
      e.preventDefault();
      btnSave?.click();
      return;
    }
    if (e.key === 'Escape') window.close();
  });

  (async function init() {
    try {
      state.renderRows = DEFAULT_VISIBLE_ROWS;
      buildRows();
      usedInput.value = '0';
      calPeriodInput.value = '0';
      calDateInput.value = todayYmd();
      updateDerivedViews();

      await fetchUnits();
      await readFromSdaq();
      startLivePolling();
    } catch (err) {
      setStatus(err.message || 'Failed to load calibration data', 'err');
    }
  })();
})();
