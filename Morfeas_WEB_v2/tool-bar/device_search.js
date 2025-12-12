(() => {
  const $ = (s, r = document) => r.querySelector(s);
  const listEl = $('#list');
  const statusEl = $('#status');
  const typeLabel = $('#typeLabel');
  const refreshBtn = $('#refreshBtn');

  let isLoading = false;

  const params = new URLSearchParams(window.location.search);
  const type = (params.get('type') || '').toUpperCase();
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
    columns.innerHTML = `<div>Channel</div><div>Status</div><div>Measurement</div>`;
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
      if (item.is_meas_valid && item.meas_value != null) {
        const unit = item.meas_unit ? ` ${item.meas_unit}` : '';
        meas.textContent = `${item.meas_value}${unit}`;
      } else {
        meas.textContent = '—';
      }

      row.append(anchor, status, meas);
      row.addEventListener('click', () => {
        try {
          window.opener?.postMessage({
            type: 'device-selected',
            payload: {
              type,
              anchor: item.anchor,
              display_anchor: item.display_anchor || item.anchor,
              unit: item.meas_unit || '',
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
      if (type === 'SDAQ') {
        const reg = (item.registration || '').toLowerCase() === 'done';
        return reg && item.has_sensor;
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
      const res = await fetch('../backend/api_channels.php?include=pool', {
        headers: { Accept: 'application/json' },
        cache: 'no-store',
      });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const json = await res.json();
      const poolAll = json?.extras?.search_pool || {};
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
