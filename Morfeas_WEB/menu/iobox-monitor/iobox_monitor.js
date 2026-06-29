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
  const powerBody = $('#powerBody');
  const rxSection = $('#rxSection');
  const rxGrid = $('#rxGrid');

  let pollTimer = null;

  function ioboxApi() {
    return window.LOG_WEB?.api?.iobox || null;
  }

  function setText(el, value) {
    if (el) el.textContent = value;
  }

  function formatNumber(value, digits = 2) {
    if (!Number.isFinite(value)) return '-';
    return value.toFixed(digits).replace(/\.?0+$/, '');
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

  function makeCell(text) {
    const td = document.createElement('td');
    td.textContent = text;
    return td;
  }

  function valueWithUnit(value, unit, digits = 2) {
    const num = numberOrNull(value);
    if (num === null) return String(value ?? '-');
    const status = reservedStatus(num);
    if (status !== null) return status;
    return `${formatNumber(num, digits)} ${unit}`;
  }

  function renderPower(power) {
    powerBody.innerHTML = '';
    const row = document.createElement('tr');
    row.appendChild(makeCell(valueWithUnit(power?.Vin, 'V', 2)));
    for (let i = 1; i <= 4; i += 1) {
      row.appendChild(makeCell(valueWithUnit(power?.[`CH${i}_Vout`], 'V', 2)));
      row.appendChild(makeCell(valueWithUnit(power?.[`CH${i}_Iout`], 'A', 3)));
    }
    powerBody.appendChild(row);
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

  function renderChannelRows(table, rxData) {
    for (let rowStart = 1; rowStart <= CHANNEL_COUNT; rowStart += 8) {
      const head = document.createElement('tr');
      const values = document.createElement('tr');
      for (let ch = rowStart; ch < rowStart + 8; ch += 1) {
        const th = document.createElement('th');
        th.textContent = `CH${ch}`;
        head.appendChild(th);
        values.appendChild(makeCell(valueWithUnit(rxData?.[`CH${ch}`], '°C', 2)));
      }
      table.appendChild(head);
      table.appendChild(values);
    }
  }

  function renderRxPanel(index, rxData) {
    const panel = document.createElement('div');
    panel.className = 'rx-panel';

    const head = document.createElement('div');
    head.className = 'rx-head';

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
    body.className = 'rx-body';

    if (typeof rxData === 'string') {
      body.textContent = rxData;
    } else if (!rxData || typeof rxData !== 'object') {
      body.textContent = 'No RX data';
    } else {
      const table = document.createElement('table');
      table.className = 'table channel-table';
      renderChannelRows(table, rxData);
      body.appendChild(table);
    }

    panel.appendChild(body);
    return panel;
  }

  function renderReceivers(data) {
    rxGrid.innerHTML = '';
    for (let i = 1; i <= RX_COUNT; i += 1) {
      rxGrid.appendChild(renderRxPanel(i, data?.[`RX${i}`]));
    }
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
