(() => {
  const params = new URLSearchParams(window.location.search);
  const initialName = String(params.get('name') || '').trim();
  const mtiApi = window.LOG_WEB?.api?.mti;

  const $ = (s, r = document) => r.querySelector(s);

  const deviceSelect = $('#deviceSelect');
  const runtimeChip = $('#runtimeChip');
  const statusBox = $('#statusBox');
  const refreshBtn = $('#refreshBtn');
  const ipValue = $('#ipValue');
  const cpuValue = $('#cpuValue');
  const batteryValue = $('#batteryValue');
  const radioValue = $('#radioValue');
  const modeSelect = $('#modeSelect');
  const rfChannelInput = $('#rfChannelInput');
  const samplesValidInput = $('#samplesValidInput');
  const samplesInvalidInput = $('#samplesInvalidInput');
  const globalControlCheck = $('#globalControlCheck');
  const globalOnBtn = $('#globalOnBtn');
  const globalOffBtn = $('#globalOffBtn');
  const saveConfigBtn = $('#saveConfigBtn');
  const tcExtra = $('#tcExtra');
  const rmswExtra = $('#rmswExtra');
  const quadExtra = $('#quadExtra');
  const pwmConfigHost = $('#pwmConfigHost');
  const stateBody = $('#stateBody');
  const telemetryHost = $('#telemetryHost');

  const POLL_MS = 1000;
  const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

  let pollTimer = null;
  let latestState = null;
  let busy = false;
  let setupDirty = false;

  function fmt(value, digits = 2, suffix = '') {
    if (!Number.isFinite(Number(value))) return '-';
    return `${Number(value).toFixed(digits)}${suffix}`;
  }

  function text(value) {
    const out = String(value ?? '').trim();
    return out !== '' ? out : '-';
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
    } else if (status === 'Not connected' || status === 'Not detected') {
      runtimeChip.classList.add('warn');
    } else {
      runtimeChip.classList.add('muted');
    }
  }

  function selectedName() {
    return String(deviceSelect.value || '').trim();
  }

  function currentMode() {
    return String(modeSelect.value || '').trim();
  }

  function isTempMode(mode) {
    return ['TC16', 'TC8', 'TC4'].includes(mode);
  }

  function updateSetupVisibility() {
    const mode = currentMode();
    tcExtra.style.display = isTempMode(mode) ? 'flex' : 'none';
    rmswExtra.style.display = mode === 'RMSW/MUX' ? 'flex' : 'none';
    quadExtra.style.display = mode === 'QUAD' ? 'block' : 'none';
    rfChannelInput.disabled = mode === 'RMSW/MUX';
  }

  function normalizeRfChannel() {
    let value = Number(rfChannelInput.value);
    if (!Number.isFinite(value)) value = 0;
    value = Math.max(0, Math.min(126, Math.trunc(value)));
    if (value % 2 !== 0) value -= 1;
    rfChannelInput.value = String(value);
  }

  function fillSelect(names) {
    const current = selectedName() || initialName;
    deviceSelect.innerHTML = '';

    if (!names.length && current) {
      names = [current];
    }

    if (!names.length) {
      const opt = document.createElement('option');
      opt.value = '';
      opt.textContent = 'No MTI devices';
      deviceSelect.appendChild(opt);
      return;
    }

    names.forEach((name) => {
      const opt = document.createElement('option');
      opt.value = name;
      opt.textContent = name;
      if (current && name === current) opt.selected = true;
      deviceSelect.appendChild(opt);
    });
  }

  function setMetricValues(state) {
    const mti = state?.mti_status || {};
    ipValue.textContent = text(state?.ipv4_address);
    cpuValue.textContent = fmt(mti.MTI_CPU_temp, 1, ' C');
    batteryValue.textContent = `${fmt(mti.MTI_batt_volt, 2, ' V')} / ${fmt(mti.MTI_batt_capacity, 0, ' %')}`;
    radioValue.textContent = text(mti.Tele_Device_type);
  }

  function setInputsFromState(state) {
    if (setupDirty) {
      updateSetupVisibility();
      return;
    }

    const mti = state?.mti_status || {};
    const tele = state?.tele_data || {};
    const mode = text(mti.Tele_Device_type);

    if (Array.from(modeSelect.options).some((option) => option.value === mode)) {
      modeSelect.value = mode;
    }
    rfChannelInput.value = Number.isFinite(Number(mti.Radio_CH)) ? String(mti.Radio_CH) : '0';

    if (isTempMode(mode)) {
      samplesValidInput.value = Number.isFinite(Number(tele.Samples_toValid)) ? String(tele.Samples_toValid) : '0';
      samplesInvalidInput.value = Number.isFinite(Number(tele.samples_toInvalid)) ? String(tele.samples_toInvalid) : '0';
    }

    if (mode === 'RMSW/MUX') {
      globalControlCheck.checked = !!mti?.MTI_Global_state?.Global_ON_OFF;
    }

    updateSetupVisibility();
  }

  function renderPwmConfig(state) {
    const pwmConfig = Array.isArray(state?.mti_status?.PWMs_config) ? state.mti_status.PWMs_config : [];
    const items = [0, 1].map((idx) => {
      const cfg = pwmConfig[idx] || {};
      return `
        <div class="pwm-card" data-pwm-idx="${idx}">
          <div class="tele-title">PWM Gens ${idx + 1} (Telemetry CH${idx + 1})</div>
          <label>Scaler
            <input class="input" data-pwm-field="scaler" type="number" step="any" value="${esc(cfg.Scaler ?? '')}" />
          </label>
          <label>Min
            <input class="input" data-pwm-field="min" type="number" step="any" value="${esc(cfg.PWM_min ?? '')}" />
          </label>
          <label>Max
            <input class="input" data-pwm-field="max" type="number" step="any" value="${esc(cfg.PWM_max ?? '')}" />
          </label>
          <label style="display:flex;align-items:center;gap:8px;">
            <input data-pwm-field="saturation" type="checkbox" ${cfg.Saturation_mode ? 'checked' : ''} />
            Saturation
          </label>
          <button class="btn sm" type="button" data-pwm-save="${idx}">Set PWM ${idx + 1}</button>
        </div>
      `;
    });
    pwmConfigHost.innerHTML = items.join('');
  }

  function row(label, value) {
    return `<tr><td>${esc(label)}</td><td>${esc(value)}</td></tr>`;
  }

  function rxStatus(value) {
    switch (Number(value)) {
      case 1: return 'RX_1';
      case 2: return 'RX_2';
      case 3: return 'Both';
      default: return 'None';
    }
  }

  function renderStateTable(state) {
    const mti = state?.mti_status || {};
    const tele = state?.tele_data || {};
    const buttons = mti.MTI_buttons_state || {};
    const pwm = Array.isArray(mti.PWM_CHs_outDuty) ? mti.PWM_CHs_outDuty : [];

    const rows = [
      row('Connection', state?.runtime_status || 'Unknown'),
      row('Detail', state?.runtime_detail || '-'),
      row('Identifier', state?.identifier ?? '-'),
      row('Charge Status', mti.MTI_charge_status || '-'),
      row('PWM Gen Clock', Number.isFinite(Number(mti.PWM_gen_out_freq)) ? `${Number(mti.PWM_gen_out_freq) / 1000} Kc` : '-'),
      row('PWM CH1', fmt(pwm[0], 1, ' %')),
      row('PWM CH2', fmt(pwm[1], 1, ' %')),
      row('PWM CH3', fmt(pwm[2], 1, ' %')),
      row('PWM CH4', fmt(pwm[3], 1, ' %')),
      row('RF Channel', mti.Radio_CH ?? '-'),
      row('Modem Speed', mti.Modem_data_rate || '-'),
      row('RX Success', Number.isFinite(Number(tele.RX_Success_Ratio)) ? `${tele.RX_Success_Ratio}%` : '-'),
      row('Active RX', rxStatus(tele.RX_Status)),
      row('PB1', buttons.PB1 ? 'Pressed' : 'Off'),
      row('PB2', buttons.PB2 ? 'Pressed' : 'Off'),
      row('PB3', buttons.PB3 ? 'Pressed' : 'Off'),
    ];

    if (mti.MTI_Global_state) {
      rows.push(row('Global Control', mti.MTI_Global_state.Global_ON_OFF ? 'Enabled' : 'Disabled'));
      rows.push(row('Global Power', mti.MTI_Global_state.Global_Power_state ? 'ON' : 'OFF'));
    }

    stateBody.innerHTML = rows.join('');
  }

  function channelValue(value, suffix = '') {
    if (Number.isFinite(Number(value))) return `${Number(value).toPrecision(5)}${suffix}`;
    return text(value);
  }

  function getTcRef(refs, idx, mode) {
    if (!Array.isArray(refs) || refs.length === 0) return null;
    if (mode === 'TC4') return refs[Math.floor(idx / 2)] ?? null;
    return refs[idx] ?? null;
  }

  function renderTcTelemetry(mode, tele) {
    const chs = Array.isArray(tele?.CHs) ? tele.CHs : [];
    const refs = Array.isArray(tele?.CHs_refs) ? tele.CHs_refs : [];
    const limit = mode === 'TC4' ? 4 : mode === 'TC8' ? 8 : 16;
    const cards = [];

    if (!tele?.IsValid) {
      telemetryHost.innerHTML = '<div class="tele-card"><div class="tele-title">Invalid Data</div><div class="subtle">Waiting for valid telemetry.</div></div>';
      return;
    }

    for (let i = 0; i < limit; i += 1) {
      const refVal = getTcRef(refs, i, mode);
      const ref = refVal !== null ? `<div class="subtle">Ref: ${esc(channelValue(refVal, ' C'))}</div>` : '';
      cards.push(`
        <div class="tele-card">
          <div class="tele-title">CH${i + 1}</div>
          <div>${esc(channelValue(chs[i], ' C'))}</div>
          ${ref}
        </div>
      `);
    }

    telemetryHost.innerHTML = cards.join('');
  }

  function renderQuadTelemetry(tele) {
    if (!tele?.IsValid) {
      telemetryHost.innerHTML = '<div class="tele-card"><div class="tele-title">Invalid Data</div><div class="subtle">Waiting for valid telemetry.</div></div>';
      return;
    }

    const chs = Array.isArray(tele?.CHs) ? tele.CHs : [];
    const cnts = Array.isArray(tele?.CNTs) ? tele.CNTs : [];
    telemetryHost.innerHTML = [0, 1].map((idx) => `
      <div class="tele-card">
        <div class="tele-title">CH${idx + 1}</div>
        <div>Value: ${esc(channelValue(chs[idx]))}</div>
        <div class="subtle">CNT: ${esc(channelValue(cnts[idx]))}</div>
      </div>
    `).join('');
  }

  function controlBtn(label, teleType, memPos, swName, newState, active) {
    return `
      <button class="btn sm" type="button"
        data-tele-type="${esc(teleType)}"
        data-mem-pos="${esc(memPos)}"
        data-sw-name="${esc(swName)}"
        data-new-state="${newState ? '1' : '0'}">
        ${esc(label)}<span class="led ${active ? 'on' : ''}"></span>
      </button>
    `;
  }

  function renderMuxControls(dev) {
    const controls = dev?.Controls || {};
    const memPos = dev?.Mem_offset ?? 0;
    return [1, 2, 3, 4].map((ch) => {
      const current = controls[`CH${ch}`] || 'A';
      return `
        <div class="control-row">
          <strong>CH${ch}: ${esc(current)}</strong>
          ${controlBtn('A', 'MUX', memPos, `Sel_${ch}`, false, current === 'A')}
          ${controlBtn('B', 'MUX', memPos, `Sel_${ch}`, true, current === 'B')}
        </div>
      `;
    }).join('');
  }

  function renderRmswControls(dev, globalEnabled) {
    if (globalEnabled) {
      return '<div class="subtle" style="margin-top:8px;">Global control is enabled.</div>';
    }

    const controls = dev?.Controls || {};
    const memPos = dev?.Mem_offset ?? 0;
    const switches = [
      { label: 'Main', swName: 'Main_SW', stateKey: 'Main' },
      { label: 'CH1', swName: 'SW_1', stateKey: 'CH1' },
      { label: 'CH2', swName: 'SW_2', stateKey: 'CH2' },
    ];
    return switches.map(({ label, swName, stateKey }) => `
      <div class="control-row">
        <strong>${esc(label)}:</strong>
        ${controlBtn('ON', 'RMSW', memPos, swName, true, !!controls[stateKey])}
        ${controlBtn('OFF', 'RMSW', memPos, swName, false, !controls[stateKey])}
      </div>
    `).join('');
  }

  function renderMiniRmswControls(dev, globalEnabled) {
    if (globalEnabled) {
      return '<div class="subtle" style="margin-top:8px;">Global control is enabled.</div>';
    }

    const main = !!dev?.Controls?.Main;
    return `
      <div class="control-row">
        <strong>Main:</strong>
        ${controlBtn('ON', 'Mini_RMSW', dev?.Mem_offset ?? 0, 'Main_SW', true, main)}
        ${controlBtn('OFF', 'Mini_RMSW', dev?.Mem_offset ?? 0, 'Main_SW', false, !main)}
      </div>
    `;
  }

  function renderRmswMuxTelemetry(tele) {
    const list = Array.isArray(tele) ? tele : [];
    if (!list.length) {
      telemetryHost.innerHTML = '<div class="tele-card"><div class="tele-title">No RF devices</div><div class="subtle">No RMSW/MUX telemetry detected.</div></div>';
      return;
    }

    const globalEnabled = !!latestState?.mti_status?.MTI_Global_state?.Global_ON_OFF;
    telemetryHost.innerHTML = list.map((dev) => {
      const controls = dev?.Controls || {};
      const meas = Array.isArray(dev?.CHs_meas) ? dev.CHs_meas : [];
      let measHtml = meas.length
        ? meas.map((value, idx) => `<div>CH${idx + 1}: ${esc(channelValue(value, idx % 2 ? ' A' : ' V'))}</div>`).join('')
        : Object.entries(controls).map(([key, value]) => `<div>${esc(key)}: ${esc(value)}</div>`).join('');
      let controlsHtml = '';

      if (dev?.Dev_type === 'MUX') {
        controlsHtml = renderMuxControls(dev);
      } else if (dev?.Dev_type === 'RMSW') {
        controlsHtml = renderRmswControls(dev, globalEnabled);
      } else if (dev?.Dev_type === 'Mini_RMSW') {
        measHtml = meas.length
          ? meas.map((value, idx) => `<div>TC_CH${idx + 1}: ${esc(Number.isFinite(Number(value)) ? `${Number(value).toFixed(3)} C` : text(value))}</div>`).join('')
          : measHtml;
        controlsHtml = renderMiniRmswControls(dev, globalEnabled);
      }

      return `
        <div class="tele-card">
          <div class="tele-title">${esc(dev?.Dev_type || 'Device')} ${esc(dev?.Dev_ID ?? '')}</div>
          <div class="subtle">Last RX: ${esc(dev?.Time_from_last_msg ?? '-')} sec</div>
          <div class="subtle">Temp: ${esc(channelValue(dev?.Dev_temp, ' C'))}</div>
          <div class="subtle">Supply: ${esc(channelValue(dev?.Supply_volt, ' V'))}</div>
          ${measHtml}
          ${controlsHtml}
        </div>
      `;
    }).join('');
  }

  function renderTelemetry(state) {
    const mode = state?.mti_status?.Tele_Device_type || '';
    const tele = state?.tele_data;

    if (state?.runtime_status !== 'Connected') {
      telemetryHost.innerHTML = '<div class="tele-card"><div class="tele-title">Not connected</div><div class="subtle">Telemetry is unavailable.</div></div>';
      return;
    }

    if (['TC16', 'TC8', 'TC4'].includes(mode)) {
      renderTcTelemetry(mode, tele || {});
    } else if (mode === 'QUAD') {
      renderQuadTelemetry(tele || {});
    } else if (mode === 'RMSW/MUX') {
      renderRmswMuxTelemetry(tele || []);
    } else {
      telemetryHost.innerHTML = '<div class="tele-card"><div class="tele-title">Radio disabled</div><div class="subtle">No telemetry mode is active.</div></div>';
    }
  }

  function render(state) {
    latestState = state;
    setRuntimeChip(state?.runtime_status || 'Unknown');
    setMetricValues(state);
    setInputsFromState(state);
    renderPwmConfig(state);
    renderStateTable(state);
    renderTelemetry(state);

    const detail = state?.runtime_detail ? `: ${state.runtime_detail}` : '';
    setStatus(`Connection: ${state?.runtime_status || 'Unknown'}${detail}`, state?.runtime_status === 'Connected' ? 'success' : 'info');
  }

  async function loadDeviceNames() {
    if (!mtiApi) throw new Error('MTI API unavailable');
    const json = await mtiApi.fetchDevices();
    if (json.ok === false) throw new Error(json.error || 'Failed to load MTI devices');
    fillSelect(Array.isArray(json.data?.devices) ? json.data.devices : []);
  }

  async function loadState(silent = false) {
    if (!mtiApi) throw new Error('MTI API unavailable');
    const name = selectedName();
    if (!name) {
      setRuntimeChip('Unknown');
      setStatus('No MTI device selected.');
      return false;
    }

    try {
      const json = await mtiApi.fetchState(name);
      if (json.ok === false) throw new Error(json.error || 'Failed to load MTI state');
      render(json.data || {});
      return true;
    } catch (err) {
      if (!silent) setStatus(`Failed to load MTI data: ${err.message}`, 'error');
      return false;
    }
  }

  function startPoll() {
    stopPoll();
    pollTimer = window.setInterval(() => {
      if (!document.hidden && !busy) loadState(true);
    }, POLL_MS);
  }

  function stopPoll() {
    if (pollTimer) {
      window.clearInterval(pollTimer);
      pollTimer = null;
    }
  }

  async function saveConfig() {
    const name = selectedName();
    if (!name || busy) return;
    normalizeRfChannel();

    const payload = {
      mode: currentMode(),
      rf_channel: Number(rfChannelInput.value || 0),
      samples_to_valid: Number(samplesValidInput.value || 0),
      samples_to_invalid: Number(samplesInvalidInput.value || 0),
      global_control: !!globalControlCheck.checked,
    };

    busy = true;
    saveConfigBtn.disabled = true;
    setStatus('Sending MTI setup...');
    try {
      const json = await mtiApi.setConfig(name, payload);
      if (json.ok === false) throw new Error(json.error || 'MTI setup failed');
      setupDirty = false;
      render(json.data?.state || latestState || {});
      setStatus(json.data?.result?.reply || 'MTI setup updated.', 'success');
    } catch (err) {
      setStatus(`Failed to set MTI setup: ${err.message}`, 'error');
    } finally {
      busy = false;
      saveConfigBtn.disabled = false;
      await loadState(true);
    }
  }

  async function setGlobalPower(enabled) {
    const name = selectedName();
    if (!name || busy) return;

    busy = true;
    globalOnBtn.disabled = true;
    globalOffBtn.disabled = true;
    setStatus(enabled ? 'Turning global power on...' : 'Turning global power off...');
    try {
      const json = await mtiApi.setGlobalPower(name, enabled);
      if (json.ok === false) throw new Error(json.error || 'MTI global switch failed');
      render(json.data?.state || latestState || {});
      setStatus(json.data?.result?.reply || 'MTI global power updated.', 'success');
    } catch (err) {
      setStatus(`Failed to set global power: ${err.message}`, 'error');
    } finally {
      busy = false;
      globalOnBtn.disabled = false;
      globalOffBtn.disabled = false;
      await loadState(true);
    }
  }

  async function setTeleSwitch(payload) {
    const name = selectedName();
    if (!name || busy) return;

    busy = true;
    setStatus('Sending telemetry switch command...');
    try {
      const json = await mtiApi.setTeleSwitch(name, payload);
      if (json.ok === false) throw new Error(json.error || 'MTI telemetry switch failed');
      render(json.data?.state || latestState || {});
      setStatus(json.data?.result?.reply || 'Telemetry switch updated.', 'success');
    } catch (err) {
      setStatus(`Failed to set telemetry switch: ${err.message}`, 'error');
    } finally {
      busy = false;
      await loadState(true);
    }
  }

  function readPwmCard(idx) {
    const card = pwmConfigHost.querySelector(`[data-pwm-idx="${idx}"]`);
    if (!card) return null;

    const get = (field) => card.querySelector(`[data-pwm-field="${field}"]`);
    return {
      scaler: Number(get('scaler')?.value || 0),
      min: Number(get('min')?.value || 0),
      max: Number(get('max')?.value || 0),
      saturation: !!get('saturation')?.checked,
    };
  }

  async function savePwmConfig(idx) {
    const name = selectedName();
    if (!name || busy) return;

    const payload = [null, null];
    payload[idx] = readPwmCard(idx);

    busy = true;
    setStatus(`Sending PWM ${idx + 1} config...`);
    try {
      const json = await mtiApi.setPwmConfig(name, payload);
      if (json.ok === false) throw new Error(json.error || 'MTI PWM config failed');
      render(json.data?.state || latestState || {});
      setStatus(json.data?.result?.reply || `PWM ${idx + 1} config updated.`, 'success');
    } catch (err) {
      setStatus(`Failed to set PWM config: ${err.message}`, 'error');
    } finally {
      busy = false;
      await loadState(true);
    }
  }

  refreshBtn.addEventListener('click', () => loadState(false));
  deviceSelect.addEventListener('change', () => {
    setupDirty = false;
    loadState(false);
  });
  modeSelect.addEventListener('change', () => {
    setupDirty = true;
    updateSetupVisibility();
  });
  rfChannelInput.addEventListener('change', () => {
    setupDirty = true;
    normalizeRfChannel();
  });
  [samplesValidInput, samplesInvalidInput, globalControlCheck].forEach((el) => {
    el.addEventListener('change', () => {
      setupDirty = true;
    });
    el.addEventListener('input', () => {
      setupDirty = true;
    });
  });
  saveConfigBtn.addEventListener('click', saveConfig);
  globalOnBtn.addEventListener('click', () => setGlobalPower(true));
  globalOffBtn.addEventListener('click', () => setGlobalPower(false));
  telemetryHost.addEventListener('click', (event) => {
    const btn = event.target.closest('button[data-tele-type]');
    if (!btn) return;
    setTeleSwitch({
      tele_type: btn.dataset.teleType || '',
      mem_pos: Number(btn.dataset.memPos || 0),
      sw_name: btn.dataset.swName || '',
      new_state: btn.dataset.newState === '1',
    });
  });
  pwmConfigHost.addEventListener('click', (event) => {
    const btn = event.target.closest('button[data-pwm-save]');
    if (!btn) return;
    savePwmConfig(Number(btn.dataset.pwmSave || 0));
  });

  window.addEventListener('beforeunload', stopPoll);
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      stopPoll();
    } else {
      loadState(true);
      startPoll();
    }
  });

  (async () => {
    if (!mtiApi) {
      setStatus('MTI API unavailable.', 'error');
      return;
    }

    try {
      await loadDeviceNames();
      await loadState(false);
      startPoll();
    } catch (err) {
      setStatus(`Failed to initialize MTI page: ${err.message}`, 'error');
    }
  })();
})();
