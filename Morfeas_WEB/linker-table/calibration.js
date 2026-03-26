(() => {
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

  const params = new URLSearchParams(location.search);
  const requestedPoints = Math.max(1, parseInt(params.get('points') || '8', 10));
  const requestedCh = Math.max(1, parseInt(params.get('ch') || '1', 10));
  const requestedUnit = params.get('unit') || '';
  const requestedSn = (params.get('sn') || '').trim();
  const bus = (params.get('bus') || '').trim().toLowerCase();
  const addrRaw = params.get('addr');

  const addr = addrRaw !== null && /^\d+$/.test(addrRaw) ? parseInt(addrRaw, 10) : null;
  const apiUrl = new URL('../backend/api_calibration.php', window.location.href);

  const unitBox = $('#unitBox');
  const calDateInput = $('#calDate');
  const calPeriodInput = $('#calPeriod');
  const usedInput = $('#usedPoints');
  const tableBody = $('#calTable tbody');
  const statusEl = $('#status');

  const infoEls = {
    serial: $('#devSerial'),
    type: $('#devType'),
    avail: $('#devAvailChannels'),
    sample: $('#devSampleRate'),
    maxPoints: $('#devMaxPoints'),
    channel: $('#channelFixed'),
  };

  const pointCols = [
    { xml: 'Measure', key: 'measure' },
    { xml: 'Reference', key: 'reference' },
    { xml: 'Offset', key: 'offset' },
    { xml: 'Gain', key: 'gain' },
    { xml: 'C2', key: 'c2' },
    { xml: 'C3', key: 'c3' },
  ];

  const state = {
    sourceXmlDoc: null,
    sourceObj: null,
    selectedCh: requestedCh,
    maxPoints: requestedPoints,
    availableChannels: 64,
    invalidCells: new Map(),
    originalChannelObj: null,
  };

  const rows = [];

  function setStatus(msg, type = 'info') {
    statusEl.textContent = msg || '';
    statusEl.style.color = type === 'ok' ? '#16a34a' : (type === 'err' ? '#dc2626' : 'var(--muted)');
  }

  function getText(parent, selector, fallback = '') {
    const node = parent?.querySelector(selector);
    return node?.textContent?.trim() || fallback;
  }

  function toInt(value, fallback) {
    const n = parseInt(String(value), 10);
    return Number.isFinite(n) ? n : fallback;
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

  function buildRows() {
    tableBody.innerHTML = '';
    rows.length = 0;
    for (let i = 0; i < state.maxPoints; i++) {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td><b>${i}</b></td>
        <td><input type="number" step="any" class="input input--w110" data-k="measure"></td>
        <td><input type="number" step="any" class="input input--w110" data-k="reference"></td>
        <td><input type="number" step="any" class="input input--w110" data-k="offset"></td>
        <td><input type="number" step="any" class="input input--w110" data-k="gain"></td>
        <td><input type="number" step="any" class="input input--w110" data-k="c2"></td>
        <td><input type="number" step="any" class="input input--w110" data-k="c3"></td>`;
      tableBody.appendChild(tr);
      rows.push(tr);
    }
  }

  function invalidKey(rowIdx, colKey) {
    return `${rowIdx}:${colKey}`;
  }

  function colLabel(colKey) {
    const found = pointCols.find((c) => c.key === colKey);
    return found ? found.xml : colKey;
  }

  function clearAllInvalidMarks() {
    state.invalidCells.clear();
    rows.forEach((tr) => {
      $$('input', tr).forEach((inp) => inp.classList.remove('cell-invalid'));
    });
  }

  function markInvalid(rowIdx, colKey, rawValue) {
    const tr = rows[rowIdx];
    const inp = tr?.querySelector(`input[data-k="${colKey}"]`);
    if (inp) inp.classList.add('cell-invalid');
    state.invalidCells.set(invalidKey(rowIdx, colKey), {
      rowIdx,
      colKey,
      rawValue: String(rawValue ?? ''),
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
    return `Invalid NaN values found: ${items.map((it) => `Point_${it.rowIdx} / ${colLabel(it.colKey)} (value: ${it.rawValue})`).join('; ')}`;
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
        // Unused points are always sanitized to zero.
        pointCols.forEach((c) => {
          const el = tr.querySelector(`input[data-k="${c.key}"]`);
          if (el) el.value = '0';
          clearInvalid(idx, c.key);
        });
      }
    });

    const msg = invalidMessage(true);
    if (msg) setStatus(msg, 'err');
  }

  function xmlNodeToObject(node) {
    if (!node || node.nodeType !== Node.ELEMENT_NODE) return null;

    const childElements = Array.from(node.children || []);
    if (!childElements.length) {
      const raw = (node.textContent || '').trim();
      if (raw === '') return '';
      const n = Number(raw);
      return Number.isFinite(n) ? n : raw;
    }

    const obj = {};
    childElements.forEach((child) => {
      obj[child.nodeName] = xmlNodeToObject(child);
    });
    return obj;
  }

  function normalizeScalar(v) {
    if (v === null || v === undefined) return '0';
    const s = String(v).trim();
    if (s === '') return '0';
    if (/^-?nan$/i.test(s)) return 'nan';
    const n = Number(s);
    return Number.isFinite(n) ? String(n) : s;
  }

  function selectedChannelTag() {
    return `CH${Math.max(1, state.selectedCh)}`;
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

  function setCellValue(rowIdx, colKey, value) {
    const tr = rows[rowIdx];
    const inp = tr?.querySelector(`input[data-k="${colKey}"]`);
    if (!inp) return;
    inp.value = value;
  }

  function fillFormFromChannelObj(chObj) {
    clearAllInvalidMarks();

    const used = Math.max(0, Math.min(state.maxPoints, toInt(chObj?.Used_Points ?? 0, 0)));
    usedInput.value = String(used);

    const ymd = slashToYmd(chObj?.Calibration_date || '');
    calDateInput.value = ymd || todayYmd();
    calPeriodInput.value = String(Math.max(0, toInt(chObj?.Calibration_Period ?? 0, 0)));
    unitBox.value = String(chObj?.Unit ?? requestedUnit ?? '');

    for (let i = 0; i < state.maxPoints; i++) {
      const point = chObj?.Points?.[`Point_${i}`] || {};
      const isUsed = i < used;

      pointCols.forEach((c) => {
        if (!isUsed) {
          setCellValue(i, c.key, '0');
          clearInvalid(i, c.key);
          return;
        }

        const raw = String(point[c.key] ?? '0').trim();
        if (/^-?nan$/i.test(raw)) {
          setCellValue(i, c.key, '');
          markInvalid(i, c.key, raw);
          return;
        }

        const num = Number(raw);
        setCellValue(i, c.key, Number.isFinite(num) ? String(num) : '0');
        clearInvalid(i, c.key);
      });
    }

    applyUsed();

    const msg = invalidMessage(true);
    if (msg) setStatus(msg, 'err');
  }

  function collectChannelObjFromForm() {
    const used = Math.max(0, Math.min(state.maxPoints, toInt(usedInput.value, 0)));

    const obj = {
      Calibration_date: ymdToSlash(calDateInput.value) || ymdToSlash(todayYmd()),
      Calibration_Period: String(Math.max(0, toInt(calPeriodInput.value, 0))),
      Used_Points: String(used),
      Unit: String(unitBox.value || ''),
      Points: {},
    };

    for (let i = 0; i < state.maxPoints; i++) {
      const p = {};
      pointCols.forEach((c) => {
        if (i >= used) {
          p[c.key] = '0';
          return;
        }

        const tr = rows[i];
        const inp = tr?.querySelector(`input[data-k="${c.key}"]`);
        const raw = String(inp?.value || '').trim();
        p[c.key] = normalizeScalar(raw === '' ? '0' : raw);
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
    const a = JSON.stringify(normalizeChannelForDiff(currentObj));
    const b = JSON.stringify(normalizeChannelForDiff(originalObj));
    return a !== b;
  }

  function createEmptyDoc() {
    const xml = '<?xml version="1.0" encoding="utf-8"?><SDAQ/>';
    return new DOMParser().parseFromString(xml, 'application/xml');
  }

  function appendTextNode(doc, parent, tag, value) {
    const n = doc.createElement(tag);
    n.appendChild(doc.createTextNode(String(value ?? '')));
    parent.appendChild(n);
    return n;
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

    // Legacy-compatible: send only used points for selected channel.
    for (let i = 0; i < used; i++) {
      const pNode = outDoc.createElement(`Point_${i}`);
      const p = channelObj.Points[`Point_${i}`] || {};
      pointCols.forEach((c) => {
        appendTextNode(outDoc, pNode, c.xml, normalizeScalar(p[c.key]));
      });
      pointsNode.appendChild(pNode);
    }

    chNode.appendChild(pointsNode);
    calData.appendChild(chNode);
    sdaqRoot.appendChild(calData);

    return new XMLSerializer().serializeToString(outDoc);
  }

  async function fetchUnits() {
    const url = new URL(apiUrl.toString());
    url.searchParams.set('action', 'units');

    const res = await fetch(url.toString(), { method: 'GET', cache: 'no-store', headers: { Accept: 'application/json' } });
    if (!res.ok) throw new Error(`Units request failed: HTTP ${res.status}`);

    const payload = await res.json();
    if (!payload?.ok || !Array.isArray(payload?.data?.SDAQ_UNITs)) {
      throw new Error(payload?.error || 'Invalid units payload');
    }

    let unitList = document.getElementById('unitList');
    if (!unitList) {
      unitList = document.createElement('datalist');
      unitList.id = 'unitList';
      document.body.appendChild(unitList);
    }
    unitList.innerHTML = '';
    payload.data.SDAQ_UNITs.forEach((u) => {
      const opt = document.createElement('option');
      opt.value = String(u);
      unitList.appendChild(opt);
    });
    unitBox.setAttribute('list', 'unitList');
  }

  async function fetchCalibrationXml() {
    if (!bus || addr === null) throw new Error('Missing bus/addr in popup URL');

    const url = new URL(apiUrl.toString());
    url.searchParams.set('action', 'xml');
    url.searchParams.set('bus', bus);
    url.searchParams.set('addr', String(addr));

    const res = await fetch(url.toString(), { method: 'GET', cache: 'no-store', headers: { Accept: 'application/xml, text/xml' } });
    if (!res.ok) {
      const t = await res.text();
      throw new Error(t || `Calibration XML request failed: HTTP ${res.status}`);
    }

    const xmlText = await res.text();
    const xmlDoc = new DOMParser().parseFromString(xmlText, 'application/xml');
    if (xmlDoc.querySelector('parsererror')) throw new Error('Failed to parse calibration XML');

    state.sourceXmlDoc = xmlDoc;
    state.sourceObj = xmlNodeToObject(xmlDoc.documentElement);

    const maxFromInfo = toInt(getText(xmlDoc, 'SDAQ > SDAQ_info > Max_num_of_cal_points', String(requestedPoints)), requestedPoints);
    const chFromInfo = toInt(getText(xmlDoc, 'SDAQ > SDAQ_info > Available_Channels', '64'), 64);
    state.maxPoints = Math.max(1, maxFromInfo);
    state.availableChannels = Math.max(1, chFromInfo);
    state.selectedCh = Math.min(Math.max(1, requestedCh), state.availableChannels);
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

  function validateUsedRowsNoNan() {
    const used = Math.max(0, Math.min(state.maxPoints, toInt(usedInput.value, 0)));

    for (let i = 0; i < used; i++) {
      pointCols.forEach((c) => {
        const inp = rows[i]?.querySelector(`input[data-k="${c.key}"]`);
        const raw = String(inp?.value || '').trim();
        if (/^-?nan$/i.test(raw)) {
          markInvalid(i, c.key, raw);
        } else {
          clearInvalid(i, c.key);
        }
      });
    }

    const msg = invalidMessage(true);
    if (msg) throw new Error(msg);
  }

  async function saveSelectedChannel() {
    validateUsedRowsNoNan();

    const currentObj = collectChannelObjFromForm();
    if (!hasSelectedChannelDiff(currentObj, state.originalChannelObj)) {
      setStatus(`No changes for ${selectedChannelTag()}.`, 'info');
      return null;
    }

    const xmlContent = buildSaveXmlOnlySelectedChannel(currentObj);
    const payload = { bus, addr, xmlContent };

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

  tableBody.addEventListener('input', (e) => {
    const inp = e.target.closest('input[data-k]');
    if (!inp) return;

    const tr = inp.closest('tr');
    const rowIdx = rows.indexOf(tr);
    const colKey = inp.dataset.k || '';
    const raw = String(inp.value || '').trim();
    const used = Math.max(0, Math.min(state.maxPoints, toInt(usedInput.value, 0)));

    if (rowIdx >= used) {
      inp.value = '0';
      clearInvalid(rowIdx, colKey);
      return;
    }

    if (/^-?nan$/i.test(raw)) {
      markInvalid(rowIdx, colKey, raw);
      setStatus(invalidMessage(true), 'err');
      return;
    }

    clearInvalid(rowIdx, colKey);
    const msg = invalidMessage(true);
    if (msg) setStatus(msg, 'err');
  });

  usedInput.addEventListener('input', () => {
    applyUsed();
  });

  $('#btnSave').addEventListener('click', async () => {
    try {
      setStatus('Saving calibration...', 'info');
      const data = await saveSelectedChannel();
      if (data) {
        setStatus(data.message || 'Saved', 'ok');
      }
    } catch (err) {
      setStatus(err.message || 'Save failed', 'err');
    }
  });

  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && String(e.key).toLowerCase() === 's') {
      e.preventDefault();
      $('#btnSave')?.click();
      return;
    }
    if (e.key === 'Escape') {
      window.close();
    }
  });

  (async function init() {
    try {
      buildRows();
      usedInput.value = '0';
      calPeriodInput.value = '0';
      calDateInput.value = todayYmd();
      unitBox.value = requestedUnit;
      applyUsed();

      await fetchUnits();
      await fetchCalibrationXml();

      buildRows();
      populateInfo();
      loadSelectedChannelFromXml();

      setStatus(`Loaded calibration for ${bus.toUpperCase()} addr ${addr} CH ${state.selectedCh}`, 'ok');
    } catch (err) {
      setStatus(err.message || 'Failed to load calibration data', 'err');
    }
  })();
})();
