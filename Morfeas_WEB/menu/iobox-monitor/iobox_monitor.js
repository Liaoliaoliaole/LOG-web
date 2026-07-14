(function () {
  const $ = (s, r = document) => r.querySelector(s);

  const RX_COUNT = 6;
  const CHANNEL_COUNT = 16;
  const REFRESH_MS = 1000;

  const params = new URLSearchParams(window.location.search);
  const deviceName = String(params.get('name') || '').trim();

  const deviceTitle = $('#deviceTitle');
  const deviceIp = $('#deviceIp');
  const lastUpdate = $('#lastUpdate');
  const connectionBadge = $('#connectionBadge');
  const message = $('#message');
  const powerSection = $('#powerSection');
  const powerGrid = $('#powerGrid');
  const rxSection = $('#rxSection');
  const rxTabs = $('#rxTabs');
  const rxPanel = $('#rxPanel');

  let pollTimer = null;
  let activeRx = 1;
  let latestData = null;

  function ioboxApi() {
    return window.LOG_WEB?.api?.iobox || null;
  }

  function setText(el, value) {
    if (el) el.textContent = value;
  }

  function formatNumber(value, digits = 2) {
    if (!Number.isFinite(value)) return '-';
    const fixed = value.toFixed(digits);
    return fixed.includes('.') ? fixed.replace(/\.?0+$/, '') : fixed;
  }

  function numberOrNull(value) {
    if (typeof value === 'number') return Number.isFinite(value) ? value : null;
    if (typeof value === 'string' && value.trim() !== '') {
      const parsed = Number(value);
      return Number.isFinite(parsed) ? parsed : null;
    }
    return null;
  }

  function reservedStatus(value) {
    const num = numberOrNull(value);
    if (num === null) return null;
    const code = Math.round(num);
    switch (code) {
      case -901: return 'Disconnected';
      case -902: return 'No sensor';
      case -905: return 'Unreachable';
      case -906: return 'Standby';
      case -907: return 'Signal Invalid';
      default: return null;
    }
  }

  function setBadge(text, ok) {
    setText(connectionBadge, text || 'Unknown');
    connectionBadge.classList.toggle('ok', !!ok);
    connectionBadge.classList.toggle('bad', !ok);
    connectionBadge.classList.toggle('warn', false);
  }

  function rxBadgeClass(statusLabel) {
    if (statusLabel === 'Okay') return 'ok';
    if (statusLabel === 'No sensor' || statusLabel === 'Standby' || statusLabel === 'Unknown') return 'warn';
    return 'bad';
  }

  function showMessage(text) {
    message.textContent = text;
    message.hidden = !text;
  }

  function setSectionsVisible(visible) {
    powerSection.hidden = !visible;
    rxSection.hidden = !visible;
  }

  function metricCard(label, value) {
    const card = document.createElement('div');
    card.className = 'metric-card';

    const labelEl = document.createElement('div');
    labelEl.className = 'metric-label';
    labelEl.textContent = label;
    card.appendChild(labelEl);

    const valueEl = document.createElement('div');
    valueEl.className = 'metric-value';
    valueEl.textContent = value;
    card.appendChild(valueEl);

    return card;
  }

  function channelCard(label, value) {
    const card = document.createElement('div');
    card.className = 'channel-card';

    const labelEl = document.createElement('div');
    labelEl.className = 'channel-label';
    labelEl.textContent = label;
    card.appendChild(labelEl);

    const valueEl = document.createElement('div');
    valueEl.className = 'channel-value';
    valueEl.textContent = value;
    card.appendChild(valueEl);

    return card;
  }

  function valueWithUnit(value, unit, digits = 2) {
    const num = numberOrNull(value);
    if (num === null) return String(value ?? '-');
    const status = reservedStatus(num);
    if (status !== null) return status;
    return `${formatNumber(num, digits)} ${unit}`;
  }

  function renderPower(power) {
    powerGrid.innerHTML = '';
    powerGrid.appendChild(metricCard('Input Vin', valueWithUnit(power?.Vin, 'V', 2)));
    for (let i = 1; i <= 4; i += 1) {
      powerGrid.appendChild(metricCard(`Output ${i} Vout`, valueWithUnit(power?.[`CH${i}_Vout`], 'V', 2)));
      powerGrid.appendChild(metricCard(`Output ${i} Iout`, valueWithUnit(power?.[`CH${i}_Iout`], 'A', 3)));
    }
  }

  function rxStatusLabel(rxData) {
    if (!rxData || typeof rxData !== 'object') return 'Unknown';
    const status = numberOrNull(rxData.Status);
    const success = numberOrNull(rxData.Success);
    const reserved = reservedStatus(status);
    if (reserved !== null) return reserved;
    if (status !== null && status !== 0) return 'Okay';
    if (status === 0) return 'Disconnected';
    if (success === 0) return 'Disconnected';
    return 'Unknown';
  }

  function renderRxPanel(index, rxData) {
    const panel = document.createElement('div');
    panel.className = 'rx-detail';

    const head = document.createElement('div');
    head.className = 'rx-detail-head';

    const title = document.createElement('strong');
    title.textContent = `RX${index}`;
    head.appendChild(title);

    const meta = document.createElement('div');
    meta.className = 'meta';

    const statusLabel = rxStatusLabel(rxData);
    const statusBadge = document.createElement('span');
    statusBadge.className = `badge ${rxBadgeClass(statusLabel)}`;
    statusBadge.textContent = `Status: ${statusLabel}`;
    meta.appendChild(statusBadge);

    const success = numberOrNull(rxData?.Success);
    const successBadge = document.createElement('span');
    successBadge.className = 'badge';
    successBadge.textContent = `Success: ${success === null ? '-' : `${formatNumber(success, 0)}%`}`;
    meta.appendChild(successBadge);

    head.appendChild(meta);
    panel.appendChild(head);

    const body = document.createElement('div');

    if (typeof rxData === 'string') {
      body.className = 'channel-grid';
      body.textContent = rxData;
    } else if (!rxData || typeof rxData !== 'object') {
      body.className = 'channel-grid';
      body.textContent = 'No RX data';
    } else {
      body.className = 'channel-grid';
      for (let ch = 1; ch <= CHANNEL_COUNT; ch += 1) {
        body.appendChild(channelCard(`CH${ch}`, valueWithUnit(rxData?.[`CH${ch}`], '°C', 2)));
      }
    }

    panel.appendChild(body);
    return panel;
  }

  function renderReceivers(data) {
    latestData = data || {};
    if (activeRx < 1 || activeRx > RX_COUNT) activeRx = 1;

    rxTabs.innerHTML = '';
    for (let i = 1; i <= RX_COUNT; i += 1) {
      const rxData = latestData?.[`RX${i}`];
      const statusLabel = rxStatusLabel(rxData);
      const success = numberOrNull(rxData?.Success);
      const tab = document.createElement('button');
      tab.type = 'button';
      tab.className = `rx-tab ${i === activeRx ? 'active' : ''}`;
      tab.setAttribute('role', 'tab');
      tab.setAttribute('aria-selected', i === activeRx ? 'true' : 'false');
      tab.innerHTML = `<span class="rx-tab-title">RX${i}</span>`;

      const statusBadge = document.createElement('span');
      statusBadge.className = `badge ${rxBadgeClass(statusLabel)}`;
      statusBadge.textContent = statusLabel;
      tab.appendChild(statusBadge);

      const successBadge = document.createElement('span');
      successBadge.className = 'badge';
      successBadge.textContent = success === null ? '-' : `${formatNumber(success, 0)}%`;
      tab.appendChild(successBadge);

      tab.addEventListener('click', () => {
        activeRx = i;
        renderReceivers(latestData);
      });
      rxTabs.appendChild(tab);
    }

    rxPanel.innerHTML = '';
    rxPanel.appendChild(renderRxPanel(activeRx, latestData?.[`RX${activeRx}`]));
  }

  function updateHeader(data) {
    const name = String(data?.Dev_name || deviceName || 'IOBOX');
    const ip = String(data?.IPv4_address || '-');
    const status = String(data?.Connection_status || 'Unknown');
    const ok = status.toLowerCase() === 'okay';

    const pageTitle = `IOBOX Monitor \u2014 ${name}`;
    document.title = pageTitle;
    setText(deviceTitle, pageTitle);
    setText(deviceIp, `IPv4: ${ip}`);
    setText(lastUpdate, `Last update: ${new Date().toLocaleTimeString()}`);
    setBadge(status, ok);

    return ok;
  }

  async function loadMonitorData() {
    if (!deviceName) {
      setBadge('Missing device name', false);
      showMessage('Missing IOBOX device name in URL.');
      setSectionsVisible(false);
      return;
    }

    try {
      const api = ioboxApi();
      if (!api?.fetchState) {
        throw new Error('IOBOX API helper is unavailable');
      }
      const payload = await api.fetchState(deviceName);
      const data = payload?.data || payload;
      const connected = updateHeader(data);
      if (!connected) {
        showMessage(String(data?.Connection_status || 'IOBOX is not connected.'));
        setSectionsVisible(false);
        return;
      }

      showMessage('');
      setSectionsVisible(true);
      renderPower(data.Power_Supply || {});
      renderReceivers(data);
    } catch (err) {
      setBadge('Read failed', false);
      showMessage(`Failed to read IOBOX logstat: ${err.message}`);
      setSectionsVisible(false);
    }
  }

  function stopPolling() {
    if (pollTimer) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
  }

  function startPolling() {
    if (document.hidden || pollTimer) return;
    pollTimer = setInterval(() => {
      if (!document.hidden) {
        loadMonitorData();
      }
    }, REFRESH_MS);
  }

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      stopPolling();
    } else {
      loadMonitorData();
      startPolling();
    }
  });

  loadMonitorData();
  startPolling();
  window.addEventListener('beforeunload', stopPolling);
})();
