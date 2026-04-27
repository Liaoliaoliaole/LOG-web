(() => {
  const params = new URLSearchParams(window.location.search);
  const bus = String(params.get('bus') || '').trim().toLowerCase();
  const noxApi = window.LOG_WEB?.api?.nox;

  const $ = (s, r = document) => r.querySelector(s);

  const busLabel = $('#busLabel');
  const runtimeChip = $('#runtimeChip');
  const statusBox = $('#statusBox');
  const refreshBtn = $('#refreshBtn');
  const globalHeaterOnBtn = $('#globalHeaterOnBtn');
  const globalHeaterOffBtn = $('#globalHeaterOffBtn');
  const setAutoOffBtn = $('#setAutoOffBtn');
  const autoOffInput = $('#autoOffInput');
  const plotAddr = $('#plotAddr');
  const clearTrendBtn = $('#clearTrendBtn');
  const exportCsvBtn = $('#exportCsvBtn');
  const trendCanvas = $('#trendCanvas');
  const sensorGrid = $('#sensorGrid');

  const voltageValue = $('#voltageValue');
  const currentValue = $('#currentValue');
  const shuntValue = $('#shuntValue');
  const utilValue = $('#utilValue');
  const wsPortValue = $('#wsPortValue');
  const errorRateValue = $('#errorRateValue');
  const autoOffCountValue = $('#autoOffCountValue');
  const detectedCountValue = $('#detectedCountValue');

  const POLL_MS = 1000;
  const MAX_HISTORY = 720;
  const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  let pollTimer = null;
  let latestState = null;
  let busy = false;
  const historyByAddr = {
    0: [],
    1: [],
  };

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

  function pushHistory(state) {
    const ts = Date.now();
    (state?.sensors || []).forEach((sensor) => {
      const addr = Number(sensor?.addr);
      if (!Number.isInteger(addr) || !(addr in historyByAddr)) return;
      const nox = Number(sensor?.NOx_value_avg);
      const o2 = Number(sensor?.O2_value_avg);
      if (!Number.isFinite(nox) && !Number.isFinite(o2)) return;
      historyByAddr[addr].push({
        t: ts,
        nox: Number.isFinite(nox) ? nox : null,
        o2: Number.isFinite(o2) ? o2 : null,
      });
      if (historyByAddr[addr].length > MAX_HISTORY) {
        historyByAddr[addr].splice(0, historyByAddr[addr].length - MAX_HISTORY);
      }
    });
  }

  function drawTrend() {
    const ctx = trendCanvas.getContext('2d');
    const width = trendCanvas.width;
    const height = trendCanvas.height;
    ctx.clearRect(0, 0, width, height);

    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, width, height);

    const pad = { l: 56, r: 16, t: 16, b: 28 };
    const innerW = width - pad.l - pad.r;
    const innerH = height - pad.t - pad.b;
    const addr = String(plotAddr.value || '0');
    const points = historyByAddr[addr] || [];

    ctx.strokeStyle = '#d8dee6';
    ctx.lineWidth = 1;
    for (let i = 0; i <= 4; i += 1) {
      const y = pad.t + (innerH / 4) * i;
      ctx.beginPath();
      ctx.moveTo(pad.l, y);
      ctx.lineTo(width - pad.r, y);
      ctx.stroke();
    }

    ctx.strokeStyle = '#c4ccd6';
    ctx.beginPath();
    ctx.moveTo(pad.l, pad.t);
    ctx.lineTo(pad.l, height - pad.b);
    ctx.lineTo(width - pad.r, height - pad.b);
    ctx.stroke();

    if (!points.length) {
      ctx.fillStyle = '#5b6472';
      ctx.font = '700 16px sans-serif';
      ctx.fillText('No trend data yet', pad.l + 12, pad.t + 28);
      return;
    }

    const validNox = points.map((p) => p.nox).filter((v) => Number.isFinite(v));
    const validO2 = points.map((p) => p.o2).filter((v) => Number.isFinite(v));
    const all = [...validNox, ...validO2];
    const min = all.length ? Math.min(...all) : 0;
    const max = all.length ? Math.max(...all) : 1;
    const minY = Math.floor(min);
    const maxY = Math.ceil(max <= min ? min + 1 : max);

    const minT = points[0].t;
    const maxT = points[points.length - 1].t || (minT + 1);
    const spanT = Math.max(1, maxT - minT);
    const spanY = Math.max(1, maxY - minY);

    ctx.fillStyle = '#5b6472';
    ctx.font = '12px sans-serif';
    ctx.fillText(String(maxY), 8, pad.t + 6);
    ctx.fillText(String(minY), 8, height - pad.b + 4);
    ctx.fillText('latest', width - pad.r - 34, height - 8);

    const drawSeries = (key, color) => {
      let started = false;
      ctx.strokeStyle = color;
      ctx.lineWidth = 2;
      ctx.beginPath();
      points.forEach((point) => {
        const value = point[key];
        if (!Number.isFinite(value)) return;
        const x = pad.l + ((point.t - minT) / spanT) * innerW;
        const y = pad.t + (1 - ((value - minY) / spanY)) * innerH;
        if (!started) {
          ctx.moveTo(x, y);
          started = true;
        } else {
          ctx.lineTo(x, y);
        }
      });
      if (started) ctx.stroke();
    };

    drawSeries('nox', '#1f7a3f');
    drawSeries('o2', '#1e5ed6');
  }

  function sensorChip(sensor) {
    if (!sensor?.detected) return ['Not detected', 'warn'];
    const noxOk = !!sensor?.status?.is_NOx_value_valid;
    const o2Ok = !!sensor?.status?.is_O2_value_valid;
    if (noxOk && o2Ok) return ['Connected', 'ok'];
    return ['Check Sensor', 'warn'];
  }

  function renderSensors(sensors) {
    sensorGrid.innerHTML = '';
    sensors.forEach((sensor) => {
      const chip = sensorChip(sensor);
      const card = document.createElement('div');
      card.className = 'sensor-card';
      card.innerHTML = `
        <div class="sensor-head">
          <div class="sensor-title">Addr ${sensor.addr}</div>
          <span class="chip ${chip[1]}">${chip[0]}</span>
        </div>
        <table class="table">
          <tr><td>NOx Avg</td><td>${fmt(sensor.NOx_value_avg, 2, ' ppm')}</td></tr>
          <tr><td>O2 Avg</td><td>${fmt(sensor.O2_value_avg, 3, ' %')}</td></tr>
          <tr><td>Heater Mode</td><td>${esc(sensor?.status?.heater_mode_state) || '—'}</td></tr>
          <tr><td>NOx Error</td><td>${esc(sensor?.errors?.NOx) || '—'}</td></tr>
          <tr><td>O2 Error</td><td>${esc(sensor?.errors?.O2) || '—'}</td></tr>
          <tr><td>Heater Error</td><td>${esc(sensor?.errors?.heater) || '—'}</td></tr>
          <tr><td>Last Seen</td><td>${sensor?.last_seen ? new Date(sensor.last_seen * 1000).toLocaleString() : '—'}</td></tr>
        </table>
        <div class="heater-actions">
          <button class="btn primary sensor-heater-btn" data-addr="${sensor.addr}" data-enabled="true">Heater ON</button>
          <button class="btn sensor-heater-btn" data-addr="${sensor.addr}" data-enabled="false">Heater OFF</button>
        </div>
      `;
      sensorGrid.appendChild(card);
    });
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
    autoOffInput.value = Number.isFinite(Number(state.auto_sw_off_value)) ? String(state.auto_sw_off_value) : '';

    renderSensors(state.sensors || []);
    pushHistory(state);
    drawTrend();

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

  globalHeaterOnBtn.addEventListener('click', () => withBusy(async () => {
    if (!window.confirm(`Turn all heaters ON for ${bus.toUpperCase()}?`)) return false;
    const json = await noxApi.setHeater(bus, -1, true);
    if (json.ok === false) throw new Error(json.error || 'Heater command failed');
  }, 'All heaters turned ON.'));

  globalHeaterOffBtn.addEventListener('click', () => withBusy(async () => {
    if (!window.confirm(`Turn all heaters OFF for ${bus.toUpperCase()}?`)) return false;
    const json = await noxApi.setHeater(bus, -1, false);
    if (json.ok === false) throw new Error(json.error || 'Heater command failed');
  }, 'All heaters turned OFF.'));

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

  sensorGrid.addEventListener('click', (e) => {
    const btn = e.target.closest('.sensor-heater-btn');
    if (!btn) return;
    const addr = Number(btn.dataset.addr);
    const enabled = btn.dataset.enabled === 'true';
    withBusy(async () => {
      if (!window.confirm(`Turn heater ${enabled ? 'ON' : 'OFF'} for addr ${addr}?`)) return false;
      const json = await noxApi.setHeater(bus, addr, enabled);
      if (json.ok === false) throw new Error(json.error || 'Heater command failed');
    }, `Heater ${enabled ? 'ON' : 'OFF'} sent for addr ${addr}.`);
  });

  clearTrendBtn.addEventListener('click', () => {
    historyByAddr[0] = [];
    historyByAddr[1] = [];
    drawTrend();
    setStatus('Trend history cleared.');
  });

  plotAddr.addEventListener('change', drawTrend);

  exportCsvBtn.addEventListener('click', () => {
    const addr = String(plotAddr.value || '0');
    const points = historyByAddr[addr] || [];
    if (!points.length) {
      setStatus('No trend data available for CSV export.', 'error');
      return;
    }

    const rows = ['timestamp,nox_ppm,o2_percent'];
    points.forEach((point) => {
      rows.push([
        new Date(point.t).toISOString(),
        point.nox ?? '',
        point.o2 ?? '',
      ].join(','));
    });

    const blob = new Blob([rows.join('\n')], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `nox_${bus}_addr${addr}.csv`;
    a.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  });

  if (!bus) {
    setStatus('Missing bus in popup URL.', 'error');
    globalHeaterOnBtn.disabled = true;
    globalHeaterOffBtn.disabled = true;
    setAutoOffBtn.disabled = true;
    refreshBtn.disabled = true;
    return;
  }

  busLabel.value = bus.toUpperCase();
  loadState(false);
  syncPoll();

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) {
      loadState(true);
    }
  });

  window.addEventListener('beforeunload', () => {
    if (pollTimer) clearInterval(pollTimer);
  });
})();
