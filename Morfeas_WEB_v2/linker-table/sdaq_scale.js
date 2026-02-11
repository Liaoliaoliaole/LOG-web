(() => {
  const $ = (s, r = document) => r.querySelector(s);

  const params = new URLSearchParams(window.location.search);
  const bus = (params.get('bus') || '').trim().toLowerCase();
  const addr = (params.get('addr') || '').trim();
  const ch = (params.get('ch') || '').trim();
  const iso = (params.get('iso') || '').trim();
  const sn = (params.get('sn') || '').trim();
  const devType = (params.get('devType') || '').trim();
  const focus = (params.get('focus') || 'scale').trim().toLowerCase();
  const rawParam = (params.get('raw') || '').trim();

  const focusChip = $('#focusChip');
  const isoText = $('#isoText');
  const typeText = $('#typeText');
  const snText = $('#snText');
  const ctxText = $('#ctxText');

  const tabMode = $('#tabMode');
  const tabScale = $('#tabScale');
  const panelMode = $('#panelMode');
  const panelScale = $('#panelScale');

  const tcType = $('#tcType');
  const wireAmount = $('#wireAmount');
  const modeHint = $('#modeHint');

  const rawValue = $('#rawValue');
  const rawLowValue = $('#rawLowValue');
  const rawHighValue = $('#rawHighValue');
  const engLowValue = $('#engLowValue');
  const engHighValue = $('#engHighValue');
  const engUnit = $('#engUnit');
  const scaledPreview = $('#scaledPreview');

  const btnSave = $('#btnSave');
  const btnReset = $('#btnReset');
  const status = $('#status');

  const storageKey = `sdaq-scale:${bus}:${addr}:${ch}`;

  function setStatus(msg, type = 'info') {
    status.textContent = msg || '';
    status.style.color = type === 'ok' ? '#16a34a' : (type === 'err' ? '#dc2626' : 'var(--color-muted)');
  }

  function parseNum(text, fallback = null) {
    const n = Number(text);
    return Number.isFinite(n) ? n : fallback;
  }

  function fixed6(n) {
    if (!Number.isFinite(n)) return '-';
    return String(Number(n.toFixed(6)));
  }

  function getRaw() {
    return parseNum(rawValue.value, null);
  }

  function getRawLow() {
    const n = parseNum(rawLowValue.value, null);
    return n === null ? 0 : n;
  }

  function getRawHigh() {
    const n = parseNum(rawHighValue.value, null);
    return n === null ? 0 : n;
  }

  function getEngLow() {
    const n = parseNum(engLowValue.value, null);
    return n === null ? 0 : n;
  }

  function getEngHigh() {
    const n = parseNum(engHighValue.value, null);
    return n === null ? 0 : n;
  }

  function isTcType(type) {
    const t = (type || '').toUpperCase();
    return t.includes('SDAQ-TC') || t.startsWith('SDAQ_TC') || t.includes('THERMOCOUPLE');
  }

  function isRtdType(type) {
    const t = (type || '').toUpperCase();
    return t.includes('SDAQ-RTD') || t.includes('PT100') || t.includes('RTD');
  }

  function updateModeAvailability() {
    const tc = isTcType(devType);
    const rtd = isRtdType(devType);

    tcType.disabled = !tc;
    wireAmount.disabled = !rtd;

    if (tc && rtd) {
      modeHint.textContent = 'This channel supports both TC type and RTD wire settings.';
    } else if (tc) {
      modeHint.textContent = 'This channel is thermocouple type. Configure TC type.';
    } else if (rtd) {
      modeHint.textContent = 'This channel is RTD/PT100 type. Configure wire amount.';
    } else {
      modeHint.textContent = 'Mode change is mainly for SDAQ TC or SDAQ RTD. Keep fields as Auto / Keep for other types.';
    }
  }

  function updateScaledPreview() {
    const raw = getRaw();
    const rawLow = getRawLow();
    const rawHigh = getRawHigh();
    const engLow = getEngLow();
    const engHigh = getEngHigh();

    if (raw === null) {
      scaledPreview.textContent = 'Scaled preview: raw value unavailable';
      return;
    }
    if (rawHigh === rawLow) {
      scaledPreview.textContent = 'Scaled preview: invalid range (RawHigh equals RawLow)';
      return;
    }

    const scaled = (raw - rawLow) * (engHigh - engLow) / (rawHigh - rawLow) + engLow;
    const unit = (engUnit.value || '').trim();
    scaledPreview.textContent =
      `Scaled preview: (${fixed6(raw)} - ${fixed6(rawLow)}) * (${fixed6(engHigh)} - ${fixed6(engLow)}) / ` +
      `(${fixed6(rawHigh)} - ${fixed6(rawLow)}) + ${fixed6(engLow)} = ${fixed6(scaled)}${unit ? ` ${unit}` : ''}`;
  }

  function toPayload() {
    return {
      bus,
      addr,
      ch,
      iso,
      sn,
      devType,
      updatedAt: new Date().toISOString(),
      mode: {
        tcType: tcType.value || '',
        wireAmount: wireAmount.value || '',
      },
      scale: {
        raw: getRaw(),
        rawLow: getRawLow(),
        rawHigh: getRawHigh(),
        engLow: getEngLow(),
        engHigh: getEngHigh(),
        unit: (engUnit.value || '').trim(),
      },
    };
  }

  function defaultScaleForType(type) {
    const t = (type || '').toUpperCase();
    if (t === 'SDAQ-I') {
      return { rawLow: 4, rawHigh: 20, engLow: 0, engHigh: 100 };
    }
    if (t === 'SDAQ-U') {
      return { rawLow: 0, rawHigh: 10, engLow: 0, engHigh: 100 };
    }
    return { rawLow: 0, rawHigh: 1, engLow: 0, engHigh: 1 };
  }

  function saveMock() {
    if (!bus || !addr || !ch) {
      setStatus('Missing bus/addr/ch. Cannot save mock data.', 'err');
      return;
    }
    const payload = toPayload();
    try {
      localStorage.setItem(storageKey, JSON.stringify(payload));
      setStatus(`Mock saved for ${bus.toUpperCase()} addr ${addr} ch ${ch}.`, 'ok');
    } catch (e) {
      setStatus(`Failed to save mock: ${e?.message || e}`, 'err');
    }
  }

  function loadMock() {
    let saved = null;
    try {
      const raw = localStorage.getItem(storageKey);
      if (raw) saved = JSON.parse(raw);
    } catch (_) {
      saved = null;
    }

    const rawFallback = parseNum(rawParam, null);
    const defaults = defaultScaleForType(devType);
    rawValue.value = saved?.scale?.raw ?? (rawFallback === null ? '' : String(rawFallback));
    rawLowValue.value = String(saved?.scale?.rawLow ?? defaults.rawLow);
    rawHighValue.value = String(saved?.scale?.rawHigh ?? defaults.rawHigh);
    engLowValue.value = String(saved?.scale?.engLow ?? defaults.engLow);
    engHighValue.value = String(saved?.scale?.engHigh ?? defaults.engHigh);
    engUnit.value = String(saved?.scale?.unit ?? '');

    tcType.value = saved?.mode?.tcType || '';
    wireAmount.value = saved?.mode?.wireAmount || '';

    updateScaledPreview();

    if (saved?.updatedAt) {
      setStatus(`Loaded mock config (last update ${saved.updatedAt}).`);
    }
  }

  function resetFields() {
    const defaults = defaultScaleForType(devType);
    rawLowValue.value = String(defaults.rawLow);
    rawHighValue.value = String(defaults.rawHigh);
    engLowValue.value = String(defaults.engLow);
    engHighValue.value = String(defaults.engHigh);
    engUnit.value = '';
    tcType.value = '';
    wireAmount.value = '';
    updateScaledPreview();
    setStatus('Fields reset.');
  }

  function activateTab(tab) {
    const modeActive = tab === 'mode';

    tabMode.classList.toggle('active', modeActive);
    panelMode.classList.toggle('active', modeActive);

    tabScale.classList.toggle('active', !modeActive);
    panelScale.classList.toggle('active', !modeActive);

    focusChip.textContent = `Focus: ${modeActive ? 'Change Mode' : 'Scale'}`;
  }

  function init() {
    isoText.textContent = iso || '-';
    typeText.textContent = devType || 'SDAQ';
    snText.textContent = sn || '-';
    ctxText.textContent = `${(bus || '-').toUpperCase()} / ${addr || '-'} / ${ch || '-'}`;

    updateModeAvailability();
    loadMock();

    activateTab(focus === 'mode' ? 'mode' : 'scale');

    tabMode.addEventListener('click', () => activateTab('mode'));
    tabScale.addEventListener('click', () => activateTab('scale'));

    rawValue.addEventListener('input', updateScaledPreview);
    rawLowValue.addEventListener('input', updateScaledPreview);
    rawHighValue.addEventListener('input', updateScaledPreview);
    engLowValue.addEventListener('input', updateScaledPreview);
    engHighValue.addEventListener('input', updateScaledPreview);
    engUnit.addEventListener('input', updateScaledPreview);

    btnSave.addEventListener('click', saveMock);
    btnReset.addEventListener('click', resetFields);

    document.addEventListener('keydown', (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        e.preventDefault();
        saveMock();
      }
      if (e.key === 'Escape') {
        window.close();
      }
    });
  }

  init();
})();
