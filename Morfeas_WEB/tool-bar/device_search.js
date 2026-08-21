(() => {
  const $ = (s, r = document) => r.querySelector(s);
  const listEl = $('#list');
  const statusEl = $('#status');
  const typeLabel = $('#typeLabel');
  const refreshBtn = $('#refreshBtn');

  const searchPoolService = window.LOG_WEB?.services?.searchPool;

  let isLoading = false;

  const params = new URLSearchParams(window.location.search);
  const requestedType = (params.get('type') || '').toUpperCase();
  const flow = (params.get('flow') || '').toLowerCase();
  const isReplaceFlow = flow === 'replace';
  const isAddChannelFlow = flow === 'add_channel';
  const sourceType = (params.get('source_type') || '').toUpperCase();
  const sourceDevType = params.get('source_dev_type') || '';
  const sourceKnown = params.get('source_known') === '1';

  if (isReplaceFlow) {
    // Replace only ever exists for SDAQ (device-relocation) sources.
    typeLabel.textContent = 'Replace Mode: SDAQ';
  } else {
    typeLabel.textContent = requestedType ? 'Type: ' + requestedType : 'Type not set';
  }

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

  function normalizedFamily(item) {
    const fromIf = (item.interface_type || item.pool_type || '').toString().trim().toUpperCase();
    if (fromIf) return fromIf;
    const fromDev = (item.device_type || '').toString().trim().toUpperCase();
    if (fromDev.startsWith('SDAQ')) return 'SDAQ';
    if (fromDev.startsWith('MTI')) return 'MTI';
    if (fromDev.startsWith('IOBOX')) return 'IOBOX';
    if (fromDev.startsWith('NOX')) return 'NOX';
    return '';
  }

  function isIoboxAnchor(value) {
    return /\.RX\d+\.(?:CH\d+|Status|Success)$/i.test((value || '').toString().trim());
  }

  function renderColumns() {
    const columns = document.createElement('div');
    columns.className = 'columns';

    if (isReplaceFlow) {
      columns.style.gridTemplateColumns = '1.4fr 1fr 0.9fr 1fr';
      columns.innerHTML = '<div>Channel</div><div>Type</div><div>Status</div><div>Measurement</div>';
      return columns;
    }

    if (requestedType === 'SDAQ') {
      columns.style.gridTemplateColumns = '1.25fr 1.25fr 1fr 0.8fr 1fr';
      columns.innerHTML = '<div>Serial Channel</div><div>Address</div><div>Device Type</div><div>Status</div><div>Measurement</div>';
      return columns;
    }

    columns.innerHTML = '<div>Channel</div><div>Status</div><div>Measurement</div>';
    return columns;
  }

  function renderList(items) {
    listEl.innerHTML = '';
    listEl.appendChild(renderColumns());

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
        const unit = measUnit ? ' ' + measUnit : '';
        meas.textContent = String(item.meas_value) + unit;
      } else {
        meas.textContent = '—';
      }

      if (isReplaceFlow) {
        row.style.gridTemplateColumns = '1.4fr 1fr 0.9fr 1fr';
        const typeCell = document.createElement('div');
        typeCell.textContent = item.device_type || item.interface_type || item.pool_type || '—';
        row.append(anchor, typeCell, status, meas);
      } else if (requestedType === 'SDAQ') {
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
        const family = normalizedFamily(item) || requestedType;
        try {
          window.opener?.postMessage({
            type: 'device-selected',
            payload: {
              type: family,
              interface_type: family,
              anchor: item.anchor,
              display_anchor: item.display_anchor || item.anchor,
              unit: measUnit,
              device_type: item.device_type || family,
              device_type_known: !!item.device_type,
            },
          }, '*');
        } catch (_) {}
        window.close();
      });

      listEl.appendChild(row);
    });
  }

  function flattenPool(poolAll) {
    const out = [];
    Object.entries(poolAll || {}).forEach(([poolType, list]) => {
      if (!Array.isArray(list)) return;
      list.forEach((item) => {
        if (!item || typeof item !== 'object') return;
        out.push({
          ...item,
          pool_type: String(poolType || '').toUpperCase(),
          interface_type: (item.interface_type || poolType || '').toString().toUpperCase(),
        });
      });
    });
    return out;
  }

  function filterPool(pool) {
    if (!Array.isArray(pool)) return [];

    return pool.filter((item) => {
      if (item.linked_in_xml) return false;
      if (item.link_state && item.link_state.toLowerCase() !== 'unlinked') return false;

      if (isReplaceFlow) {
        if (!sourceKnown) return false;
        // Replace only ever exists for SDAQ sources; never offer a
        // cross-family candidate here even though the backend would also
        // reject it (replace_type_mismatch/replace_source_not_sdaq).
        const family = normalizedFamily(item);
        if (family && family !== 'SDAQ') return false;
      } else {
        // Add Channel must stay inside the selected device family. This guards
        // against stale/mixed search-pool data showing IOBOX candidates for MTI
        // or the reverse when the backend logstat files are changing.
        const family = normalizedFamily(item);
        if (family && requestedType && family !== requestedType) return false;

        if (requestedType === 'IOBOX' && isAddChannelFlow) {
          if (!item.anchor || !isIoboxAnchor(item.anchor)) return false;
        }
        if (requestedType === 'MTI' && (isIoboxAnchor(item.anchor) || isIoboxAnchor(item.display_anchor))) {
          return false;
        }
      }

      return true;
    });
  }

  async function loadPool(manual = false) {
    if (!isReplaceFlow && !requestedType) {
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

      const sourceInfo = [];
      if (isReplaceFlow && sourceType) {
        sourceInfo.push('Source: ' + sourceType + (sourceDevType ? ' (' + sourceDevType + ')' : ''));
        sourceInfo.push(sourceKnown ? 'known subtype' : 'unknown subtype');
      }

      const rawPool = isReplaceFlow
        ? flattenPool(poolAll)
        : (poolAll[requestedType] || []);
      const pool = filterPool(rawPool);

      if (!pool.length) {
        setStatus('No available channels' + (sourceInfo.length ? ' • ' + sourceInfo.join(', ') : ''));
        renderEmpty('No channels available for this type.');
        isLoading = false;
        return;
      }

      setStatus(String(pool.length) + ' available channel(s)' + (sourceInfo.length ? ' • ' + sourceInfo.join(', ') : ''));
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
