(() => {
  const $ = (s, r = document) => r.querySelector(s);
  const listEl = $('#list');
  const statusEl = $('#status');
  const typeLabel = $('#typeLabel');
  const refreshBtn = $('#refreshBtn');

  const searchPoolService = window.LOG_WEB?.services?.searchPool;

  let isLoading = false;

  const params = new URLSearchParams(window.location.search);
  const type = (params.get('type') || '').toUpperCase();
  const flow = (params.get('flow') || '').toLowerCase();
  const isAddChannelFlow = flow === 'add_channel';
  typeLabel.textContent = type ? `Type: ${type}` : 'Type not set';

  function setStatus(msg, tone = 'muted') {
    statusEl.textContent = msg;
    statusEl.style.color = tone === 'error' ? '#e11d48' : 'var(--muted)';
  }

  function renderEmpty(msg) {
    listEl.innerHTML = '';
    const div = document.createElement('div');
    div.className = 'empty';
    div.textContent = msg;
    listEl.appendChild(div);
  }

  function renderList(items) {
    listEl.innerHTML = '';

    const columns = document.createElement('div');
    columns.className = 'columns';
    if (type === 'SDAQ') {
      columns.style.gridTemplateColumns = '1.25fr 1.25fr 1fr 0.8fr 1fr';
      columns.innerHTML = `<div>Serial Channel</div><div>Address</div><div>Device Type</div><div>Status</div><div>Measurement</div>`;
    } else {
      columns.innerHTML = `<div>Channel</div><div>Status</div><div>Measurement</div>`;
    }
    listEl.appendChild(columns);

    items.forEach((item) => {
      const row = document.createElement('button');
      row.type = 'button';
      row.className = 'row';

      const anchor = document.createElement('div');
      anchor.textContent = item.display_anchor || item.anchor;

      const status = document.createElement('div');
      status.textContent = item.status || 'Unknown';

      const meas = document.createElement('div');
      const measUnit = item.meas_unit || item.unit || '';
      if (item.is_meas_valid && item.meas_value != null) {
        const unit = measUnit ? ` ${measUnit}` : '';
        meas.textContent = `${item.meas_value}${unit}`;
      } else {
        meas.textContent = '—';
      }

      if (type === 'SDAQ') {
        row.style.gridTemplateColumns = '1.25fr 1.25fr 1fr 0.8fr 1fr';
        const address = document.createElement('div');
        address.textContent = item.address_anchor || '—';
        const deviceType = document.createElement('div');
        deviceType.textContent = item.device_type || '—';
        row.append(anchor, address, deviceType, status, meas);
      } else {
        row.append(anchor, status, meas);
      }
      row.addEventListener('click', () => {
        try {
          window.opener?.postMessage({
            type: 'device-selected',
            payload: {
              type,
              anchor: item.anchor,
              display_anchor: item.display_anchor || item.anchor,
              unit: measUnit,
              device_type: item.device_type || type,
            },
          }, '*');
        } catch (_) {}
        window.close();
      });

      listEl.appendChild(row);
    });
  }

  function filterPool(pool) {
    if (!Array.isArray(pool)) return [];
    return pool.filter((item) => {
      if (item.linked_in_xml) return false;
      if (item.link_state && item.link_state.toLowerCase() !== 'unlinked') return false;
      if (type === 'IOBOX' && isAddChannelFlow) {
        if (!item.anchor || !/\.RX\d+\.CH\d+$/i.test(item.anchor)) return false;
      }
      if (type === 'SDAQ') {
        // Legacy behavior: list unlinked SDAQ channels regardless of No_Sensor.
        // Only filter out channels already linked in XML (handled above).
        return true;
      }
      return true;
    });
  }

  async function loadPool(manual = false) {
    if (!type) {
      setStatus('Missing device type', 'error');
      renderEmpty('No device type specified.');
      return;
    }

    if (isLoading) return;
    isLoading = true;
    setStatus(manual ? 'Refreshing…' : 'Loading...');

    try {
      if (!searchPoolService) throw new Error('Search pool service unavailable');
      const poolAll = await searchPoolService.loadSearchPool();
      const pool = filterPool(poolAll[type] || []);

      if (!pool.length) {
        setStatus('No available channels');
        renderEmpty('No channels available for this type.');
        isLoading = false;
        return;
      }

      setStatus(`${pool.length} available channel(s)`);
      renderList(pool);
    } catch (err) {
      console.error(err);
      setStatus(err.message || 'Failed to load devices', 'error');
      renderEmpty('Failed to load devices.');
    } finally {
      isLoading = false;
    }
  }

  refreshBtn?.addEventListener('click', (e) => {
    e.preventDefault();
    loadPool(true);
  });

  loadPool();
})();
