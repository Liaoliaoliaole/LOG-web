(() => {
  const params = new URLSearchParams(window.location.search);
  const bus = String(params.get('bus') || '').trim().toLowerCase();
  const noxApi = window.LOG_WEB?.api?.nox;

  const $ = (s, r = document) => r.querySelector(s);

  const busLabel = $('#busLabel');
  const runtimeChip = $('#runtimeChip');
  const statusBox = $('#statusBox');
  const refreshBtn = $('#refreshBtn');
  const setAutoOffBtn = $('#setAutoOffBtn');
  const autoOffInput = $('#autoOffInput');
  const autoOffLabel = $('#autoOffLabel');
  const plotAddr = $('#plotAddr');
  const selectedHeaterOnBtn = $('#selectedHeaterOnBtn');
  const selectedHeaterOffBtn = $('#selectedHeaterOffBtn');
  const playPauseBtn = $('#playPauseBtn');
  const clearTrendBtn = $('#clearTrendBtn');
  const exportCsvBtn = $('#exportCsvBtn');
  const exportPdfBtn = $('#exportPdfBtn');
  const zoomStatsCheck = $('#zoomStatsCheck');
  const graphHost = $('#graphHost');
  const currentDataCard = $('#currentDataCard');
  const statsGrid = $('#statsGrid');

  const voltageValue = $('#voltageValue');
  const currentValue = $('#currentValue');
  const shuntValue = $('#shuntValue');
  const utilValue = $('#utilValue');
  const wsPortValue = $('#wsPortValue');
  const errorRateValue = $('#errorRateValue');
  const autoOffCountValue = $('#autoOffCountValue');
  const detectedCountValue = $('#detectedCountValue');
  const selectedNoxValue = $('#selectedNoxValue');
  const selectedO2Value = $('#selectedO2Value');
  const selectedHeaterMode = $('#selectedHeaterMode');
  const selectedHeaterError = $('#selectedHeaterError');
  const selectedNoxError = $('#selectedNoxError');
  const selectedO2Error = $('#selectedO2Error');
  const selectedLastSeen = $('#selectedLastSeen');
  const noxStatAvg = $('#noxStatAvg');
  const noxStatMax = $('#noxStatMax');
  const noxStatMin = $('#noxStatMin');
  const noxStatP2P = $('#noxStatP2P');
  const o2StatAvg = $('#o2StatAvg');
  const o2StatMax = $('#o2StatMax');
  const o2StatMin = $('#o2StatMin');
  const o2StatP2P = $('#o2StatP2P');

  const POLL_MS = 1000;
  const WS_PULL_MS = 100;
  const BUFFER_SIZE = 3600 / 0.1;
  const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const sleep = (ms) => new Promise((resolve) => window.setTimeout(resolve, ms));

  let pollTimer = null;
  let latestState = null;
  let busy = false;
  let graph = null;
  let dataBuffer = [];
  let wsTimer = null;
  let noxWs = null;
  let pauseOrPlay = 1;
  let statsCsv = '';
  let currentWsPort = null;

  function fmt(value, digits = 2, suffix = '') {
    if (!Number.isFinite(Number(value))) return '—';
    return `${Number(value).toFixed(digits)}${suffix}`;
  }

  function setStatus(message, type = 'info') {
    statusBox.textContent = message;
    if (type === 'error') {
      statusBox.style.background = '#fff1f0';
      statusBox.style.borderColor = '#ffccc7';
      statusBox.style.color = '#a8071a';
    } else if (type === 'success') {
      statusBox.style.background = '#f6ffed';
      statusBox.style.borderColor = '#b7eb8f';
      statusBox.style.color = '#135200';
    } else {
      statusBox.style.background = '#f7f9fb';
      statusBox.style.borderColor = '#d9dde3';
      statusBox.style.color = '#1f2328';
    }
  }

  function setRuntimeChip(status) {
    runtimeChip.textContent = status || 'Unknown';
    runtimeChip.classList.remove('ok', 'warn', 'muted');
    if (status === 'Connected') {
      runtimeChip.classList.add('ok');
    } else if (status === 'Not detected') {
      runtimeChip.classList.add('warn');
    } else {
      runtimeChip.classList.add('muted');
    }
  }

  function selectedAddr() {
    return Number(plotAddr.value || '0');
  }

  function selectedSensor(state) {
    return (state?.sensors || []).find((sensor) => Number(sensor?.addr) === selectedAddr()) || null;
  }

  function sensorText(value) {
    const text = String(value ?? '').trim();
    return text !== '' ? text : '—';
  }

  function formatLegacyMeasurement(sensor, valueKey, validKey, digits, suffix) {
    if (!sensor?.detected) return 'Not detected';
    if (!sensor?.status?.meas_state) return 'Not measuring';
    if (sensor?.status?.[validKey] && Number.isFinite(Number(sensor?.[valueKey]))) {
      return `${Number(sensor[valueKey]).toFixed(digits)}${suffix}`;
    }
    return 'Not Valid';
  }

  function renderSelectedSensor(state) {
    const sensor = selectedSensor(state);
    selectedNoxValue.textContent = formatLegacyMeasurement(sensor, 'NOx_value_avg', 'is_NOx_value_valid', 3, ' ppm');
    selectedO2Value.textContent = formatLegacyMeasurement(sensor, 'O2_value_avg', 'is_O2_value_valid', 3, ' %');
    selectedHeaterMode.textContent = sensorText(sensor?.status?.heater_mode_state || (sensor?.detected ? '' : 'Not detected'));
    selectedHeaterError.textContent = sensorText(sensor?.errors?.heater);
    selectedNoxError.textContent = sensorText(sensor?.errors?.NOx);
    selectedO2Error.textContent = sensorText(sensor?.errors?.O2);
    selectedLastSeen.textContent = sensor?.last_seen ? new Date(sensor.last_seen * 1000).toLocaleString() : '—';

    const measuring = !!sensor?.status?.meas_state;
    autoOffLabel.textContent = measuring ? 'PowerOFF_CNT (sec)' : 'PowerOFF (sec)';
    autoOffInput.readOnly = measuring;
    setAutoOffBtn.disabled = measuring;

    if (measuring) {
      const remaining = Number(state?.auto_sw_off_value) - Number(state?.auto_sw_off_cnt);
      autoOffInput.value = Number.isFinite(remaining) ? String(Math.max(0, remaining)) : '';
    } else {
      autoOffInput.value = Number.isFinite(Number(state?.auto_sw_off_value)) ? String(state.auto_sw_off_value) : '';
    }
  }

  function clearStats() {
    [noxStatAvg, noxStatMax, noxStatMin, noxStatP2P, o2StatAvg, o2StatMax, o2StatMin, o2StatP2P].forEach((el) => {
      if (el) el.textContent = '—';
    });
    statsCsv = '';
  }

  function updateZoomUi(isZoomed) {
    exportCsvBtn.style.display = isZoomed ? '' : 'none';
    exportPdfBtn.style.display = isZoomed ? '' : 'none';
    if (isZoomed && zoomStatsCheck.checked) {
      currentDataCard.style.display = 'none';
      statsGrid.style.display = 'grid';
    } else {
      currentDataCard.style.display = '';
      statsGrid.style.display = 'none';
    }
  }

  function graphTitle() {
    return `UniNOx:${selectedAddr()}`;
  }

  function resetGraphBuffer() {
    dataBuffer = [[new Date(), NaN, NaN]];
    clearStats();
    updateZoomUi(false);
  }

  function initGraph() {
    resetGraphBuffer();
    if (graph) {
      graph.destroy();
    }
    if (typeof window.Dygraph !== 'function') {
      return;
    }
    graph = new window.Dygraph(graphHost, dataBuffer, {
      drawPoints: false,
      showRoller: false,
      digitsAfterDecimal: 3,
      labels: ['Time', 'NOX(ppm)', 'O2(%)'],
      series: {
        'O2(%)': { axis: 'y2' },
      },
      title: graphTitle(),
      ylabel: 'NOX(ppm)',
      y2label: 'O2 (%)',
      legend: 'never',
      axisLabelWidth: 60,
      zoomCallback(minX, maxX) {
        if (!dataBuffer.length || minX >= maxX || !graph) {
          return;
        }
        if (graph.isZoomed('x')) {
          calcStats(minX, maxX);
          updateZoomUi(true);
          pauseOrPlay = 1;
        } else {
          clearStats();
          updateZoomUi(false);
          playPauseBtn.textContent = 'Pause';
          graph.updateOptions({ legend: 'never' });
          pauseOrPlay = 1;
        }
      },
      underlayCallback(ctx, area) {
        ctx.save();
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(area.x, area.y, area.w, area.h);
        ctx.restore();
      },
      labelsDivStyles: {
        textAlign: 'left',
      },
    });
  }

  function getTimestampMs(view) {
    if (typeof view.getBigUint64 === 'function') {
      return Number(view.getBigUint64(0, true));
    }
    const low = view.getUint32(0, true);
    const high = view.getUint32(4, true);
    return high * 4294967296 + low;
  }

  function closeWebSocket() {
    if (wsTimer) {
      clearInterval(wsTimer);
      wsTimer = null;
    }
    if (noxWs) {
      try {
        noxWs.close();
      } catch (_) {
        // ignore close errors
      }
      noxWs = null;
    }
    currentWsPort = null;
  }

  function onWsOpen() {
    if (!noxWs || noxWs.readyState !== noxWs.OPEN) {
      return;
    }
    noxWs.send('getMeasRAW');
    wsTimer = setInterval(() => {
      if (noxWs && noxWs.readyState === noxWs.OPEN) {
        noxWs.send('getMeasRAW');
      }
    }, WS_PULL_MS);
  }

  function onWsMessage(evt) {
    if (!(evt.data instanceof ArrayBuffer)) {
      return;
    }

    const msg = new DataView(evt.data);
    const timestamp = new Date(getTimestampMs(msg));
    const addr = selectedAddr();
    const noxVals = [Number(msg.getFloat32(8, true)), Number(msg.getFloat32(12, true))];
    const o2Vals = [Number(msg.getFloat32(16, true)), Number(msg.getFloat32(20, true))];
    const nox = noxVals[addr];
    const o2 = o2Vals[addr];

    if (Number.isNaN(nox) && Number.isNaN(o2)) {
      return;
    }

    if (dataBuffer.length >= BUFFER_SIZE) {
      dataBuffer.shift();
    }
    dataBuffer.push([timestamp, nox, o2]);
    if (pauseOrPlay && graph) {
      graph.updateOptions({ file: dataBuffer, title: graphTitle() });
    }
  }

  function ensureWebSocket(state) {
    const sensor = selectedSensor(state);
    const measuring = !!sensor?.status?.meas_state;
    const wsPort = Number.isFinite(Number(state?.ws_port)) ? Number(state.ws_port) : null;

    if (!measuring || !wsPort) {
      closeWebSocket();
      return;
    }

    if (noxWs && noxWs.readyState === noxWs.OPEN && currentWsPort === wsPort) {
      return;
    }

    closeWebSocket();
    currentWsPort = wsPort;
    noxWs = new WebSocket(`ws://${window.location.hostname}:${wsPort}`, 'Morfeas_NOX_WS_if');
    noxWs.binaryType = 'arraybuffer';
    noxWs.onopen = onWsOpen;
    noxWs.onmessage = onWsMessage;
    noxWs.onclose = () => {
      if (wsTimer) {
        clearInterval(wsTimer);
        wsTimer = null;
      }
    };
    noxWs.onerror = () => {
      setStatus('NOX raw trend websocket disconnected.', 'error');
    };
  }

  function calcStats(minX, maxX) {
    let start = 0;
    while (dataBuffer[start] && dataBuffer[start][0].getTime() <= minX) {
      start += 1;
    }
    if (!dataBuffer[start]) {
      clearStats();
      return;
    }

    const noxVals = [];
    const o2Vals = [];
    const rows = ['Timestamp,NOx(ppm),O2(%)'];

    for (let i = start; dataBuffer[i] && dataBuffer[i][0].getTime() <= maxX; i += 1) {
      const [t, nox, o2] = dataBuffer[i];
      if (Number.isFinite(nox)) noxVals.push(nox);
      if (Number.isFinite(o2)) o2Vals.push(o2);
      rows.push(`${t.toISOString()},${Number.isFinite(nox) ? nox.toFixed(3) : ''},${Number.isFinite(o2) ? o2.toFixed(3) : ''}`);
    }

    statsCsv = rows.join('\n');

    const fill = (el, value, unit) => {
      el.textContent = Number.isFinite(value) ? `${value.toFixed(3)} ${unit}` : '—';
    };

    const calc = (values) => {
      if (!values.length) return null;
      const sum = values.reduce((acc, value) => acc + value, 0);
      const min = Math.min(...values);
      const max = Math.max(...values);
      return { avg: sum / values.length, min, max, p2p: max - min };
    };

    const noxStats = calc(noxVals);
    const o2Stats = calc(o2Vals);
    fill(noxStatAvg, noxStats?.avg, 'ppm');
    fill(noxStatMax, noxStats?.max, 'ppm');
    fill(noxStatMin, noxStats?.min, 'ppm');
    fill(noxStatP2P, noxStats?.p2p, 'ppm');
    fill(o2StatAvg, o2Stats?.avg, '%');
    fill(o2StatMax, o2Stats?.max, '%');
    fill(o2StatMin, o2Stats?.min, '%');
    fill(o2StatP2P, o2Stats?.p2p, '%');
  }

  function playPause() {
    if (!graph) return;
    if (pauseOrPlay) {
      pauseOrPlay = 0;
      playPauseBtn.textContent = 'Play';
      graph.updateOptions({ legend: 'follow' });
      return;
    }

    pauseOrPlay = 1;
    playPauseBtn.textContent = 'Pause';
    graph.resetZoom();
    graph.updateOptions({ legend: 'never', file: dataBuffer, title: graphTitle() });
    updateZoomUi(false);
  }

  function renderState(state) {
    latestState = state;
    busLabel.value = state.bus || bus.toUpperCase();
    setRuntimeChip(state.runtime_status || 'Unknown');

    const electrics = state.electrics || {};
    voltageValue.textContent = fmt(electrics.BUS_voltage, 1, ' V');
    currentValue.textContent = fmt(electrics.BUS_amperage, 2, ' A');
    shuntValue.textContent = fmt(electrics.BUS_Shunt_Res_temp, 1, ' °F');
    utilValue.textContent = fmt(state.bus_utilization, 2, ' %');
    wsPortValue.textContent = Number.isFinite(Number(state.ws_port)) ? String(state.ws_port) : '—';
    errorRateValue.textContent = fmt(state.bus_error_rate, 4, '');
    autoOffCountValue.textContent = Number.isFinite(Number(state.auto_sw_off_cnt)) ? String(state.auto_sw_off_cnt) : '—';
    detectedCountValue.textContent = String((state.sensors || []).filter((sensor) => sensor?.detected).length);

    renderSelectedSensor(state);
    ensureWebSocket(state);

    if (graph) {
      graph.updateOptions({ title: graphTitle() });
    }

    const detected = (state.sensors || []).filter((sensor) => sensor?.detected).length;
    setStatus(`Loaded ${state.bus.toUpperCase()} • ${detected} sensor(s) detected.`, 'success');
  }

  async function loadState(silent = false) {
    try {
      if (!noxApi) throw new Error('NOX API unavailable');
      const json = await noxApi.fetchState(bus);
      if (json.ok === false) throw new Error(json.error || 'Load failed');
      renderState(json.data);
      return true;
    } catch (err) {
      if (!silent) {
        setStatus(`Failed to load NOX state: ${err.message}`, 'error');
      }
      return false;
    }
  }

  function syncPoll() {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(() => {
      if (!document.hidden && !busy) {
        loadState(true);
      }
    }, POLL_MS);
  }

  function findSensor(state, addr) {
    return (state?.sensors || []).find((sensor) => Number(sensor?.addr) === Number(addr)) || null;
  }

  function heaterStateMatches(sensor, enabled) {
    if (!sensor) return false;
    const mode = String(sensor?.status?.heater_mode_state || '').trim().toLowerCase();
    const measuring = !!sensor?.status?.meas_state;

    if (enabled) {
      return measuring || (mode !== '' && mode !== 'heater off');
    }

    return !measuring && (mode === 'heater off' || (!sensor?.detected && mode === ''));
  }

  async function waitForHeaterState(addr, enabled, attempts = 8, delayMs = 1000) {
    for (let i = 0; i < attempts; i += 1) {
      await sleep(delayMs);
      const ok = await loadState(true);
      if (ok && heaterStateMatches(findSensor(latestState, addr), enabled)) {
        return true;
      }
    }
    return false;
  }

  async function withBusy(task, successMessage) {
    if (busy) return;
    busy = true;
    try {
      const result = await task();
      if (result === false) return;
      await loadState(true);
      setStatus(successMessage, 'success');
    } catch (err) {
      setStatus(err.message || String(err), 'error');
    } finally {
      busy = false;
    }
  }

  function downloadCsv() {
    if (!statsCsv) {
      setStatus('Zoom the graph to export CSV.', 'error');
      return;
    }
    const d = new Date();
    const filename = `NOx_${bus}_${selectedAddr()}_${d.getMonth()}_${d.getDate()}_${d.getFullYear()}_${d.getHours()}_${d.getMinutes()}_${d.getSeconds()}.csv`;
    const blob = new Blob([statsCsv], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  function downloadPdf() {
    if (!graph || !graph.isZoomed('x')) {
      setStatus('Zoom the graph before exporting PDF.', 'error');
      return;
    }
    const target = $('.row-2');
    const d = new Date();
    const stamp = `${d.getMonth()}_${d.getDate()}_${d.getFullYear()}_${d.getHours()}_${d.getMinutes()}_${d.getSeconds()}`;
    const filename = `NOx_${bus}_${selectedAddr()}_${stamp}`;

    const previousStats = statsGrid.style.display;
    const previousCurrent = currentDataCard.style.display;
    if (!zoomStatsCheck.checked) {
      currentDataCard.style.display = 'none';
      statsGrid.style.display = 'grid';
    }

    window.html2canvas(target).then((canvas) => {
      const docDefinition = {
        pageSize: 'LETTER',
        pageOrientation: 'landscape',
        pageMargins: [40, 40, 40, 40],
        header: { columns: [{ text: window.location.hostname, alignment: 'center' }] },
        footer: {
          columns: [
            `NOx@${bus}_Addr:${selectedAddr()}`,
            { text: d.toLocaleString(), alignment: 'right' },
          ],
        },
        content: [{
          image: canvas.toDataURL(),
          width: 700,
          alignment: 'center',
        }],
      };
      window.pdfMake.createPdf(docDefinition).download(`${filename}.pdf`);
    }).catch((err) => {
      setStatus(`PDF export failed: ${err.message || err}`, 'error');
    }).finally(() => {
      if (!zoomStatsCheck.checked) {
        currentDataCard.style.display = previousCurrent;
        statsGrid.style.display = previousStats;
      }
    });
  }

  setAutoOffBtn.addEventListener('click', () => withBusy(async () => {
    const value = Number(autoOffInput.value);
    if (!Number.isInteger(value) || value < 0 || value > 65535) {
      throw new Error('Auto OFF value must be in range 0..65535.');
    }
    const json = await noxApi.setAutoOff(bus, value);
    if (json.ok === false) throw new Error(json.error || 'Auto OFF update failed');
  }, 'Auto OFF value updated.'));

  refreshBtn.addEventListener('click', () => {
    loadState(false);
  });

  function runSelectedHeater(enabled) {
    const addr = selectedAddr();
    withBusy(async () => {
      if (!window.confirm(`Turn heater ${enabled ? 'ON' : 'OFF'} for addr ${addr}?`)) return false;
      const json = await noxApi.setHeater(bus, addr, enabled);
      if (json.ok === false) throw new Error(json.error || 'Heater command failed');
      const settled = await waitForHeaterState(addr, enabled);
      if (!settled) {
        throw new Error(`Heater ${enabled ? 'ON' : 'OFF'} accepted, but addr ${addr} did not update yet.`);
      }
    }, `Heater ${enabled ? 'ON' : 'OFF'} sent for addr ${addr}.`);
  }

  selectedHeaterOnBtn.addEventListener('click', () => runSelectedHeater(true));
  selectedHeaterOffBtn.addEventListener('click', () => runSelectedHeater(false));

  playPauseBtn.addEventListener('click', playPause);

  clearTrendBtn.addEventListener('click', () => {
    resetGraphBuffer();
    initGraph();
    setStatus('Trend history cleared.');
  });

  plotAddr.addEventListener('change', () => {
    if (latestState) {
      renderSelectedSensor(latestState);
    }
    initGraph();
    ensureWebSocket(latestState);
  });

  zoomStatsCheck.addEventListener('change', () => {
    updateZoomUi(!!graph?.isZoomed('x'));
  });

  exportCsvBtn.addEventListener('click', downloadCsv);
  exportPdfBtn.addEventListener('click', downloadPdf);

  if (!bus) {
    setStatus('Missing bus in popup URL.', 'error');
    setAutoOffBtn.disabled = true;
    refreshBtn.disabled = true;
    return;
  }

  busLabel.value = bus.toUpperCase();
  initGraph();
  loadState(false);
  syncPoll();

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) {
      loadState(true);
    }
  });

  window.addEventListener('beforeunload', () => {
    if (pollTimer) clearInterval(pollTimer);
    closeWebSocket();
  });
})();
