/* ============================================================================
 * System Status (popup)
 * ----------------------------------------------------------------------------
 * Purpose : Render system details + logs (Details wired to backend logstat feed).
 * Tabs    : Details (tables) / Logs (simple viewer placeholder).
 * ========================================================================== */

(function () {
  /* ------------------------------------------
   * Shorthand DOM helpers
   * ------------------------------------------ */
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

  /* ------------------------------------------
   * Backend endpoint
   * ------------------------------------------ */
  const DEFAULT_ENDPOINT = '../../backend/api_system_status.php?action=details';
  const mockBadge = $('#mockBadge');

  function resolveEndpoint() {
    const fromWindow = typeof window !== 'undefined' && typeof window.API_ENDPOINT === 'string' && window.API_ENDPOINT.trim();
    return fromWindow || DEFAULT_ENDPOINT;
  }

  /* ------------------------------------------
   * Tabs
   * ------------------------------------------ */
  const panels = {
    details: $('#panel-details'),
    logs: $('#panel-logs')
  };

  let detailsCache = null;
  let detailsError = null;

  async function showTab(key) {
    Object.values(panels).forEach(p => p.classList.remove('show'));
    panels[key].classList.add('show');

    if (key === 'details') await renderDetails();
    if (key === 'logs') renderLogs();
  }

  document.addEventListener('click', (e) => {
    const t = e.target.closest('.tab[data-tab]');
    if (!t) return;

    e.preventDefault();

    // Visual selected state
    $$('.tab').forEach(b => {
      b.classList.toggle('primary', b === t);
      b.setAttribute('aria-selected', b === t ? 'true' : 'false');
    });

    showTab(t.dataset.tab);
  });

  /* ------------------------------------------
   * Rendering – Details
   * ------------------------------------------ */
  const prettyLabels = {
    BUS_Utilization: 'Bus Utilization',
    BUS_Error_Rate:  'Bus Error Rate',
    Detected_SDAQs:  'Detected SDAQs',
    Incomplete_SDAQs:'Incomplete SDAQs',
  };

  function formatPercent(value, digits = 2) {
    if (typeof value !== 'number' || Number.isNaN(value)) return value;
    const abs = Math.abs(value);
    const dp  = abs >= 100 ? 0 : abs >= 10 ? Math.min(1, digits) : digits;
    return `${value.toFixed(dp)}%`;
  }

  function formatShuntTempF(value) {
    if (typeof value !== 'number' || Number.isNaN(value)) return value;
    const c = (value - 32) * 5 / 9;
    return `${c.toFixed(1)}°C (${value.toFixed(1)}°F)`;
  }

  function formatCpuTempF(value) {
    if (typeof value !== 'number' || Number.isNaN(value)) return value;
    const c = (value - 32) * 5 / 9;
    return `${c.toFixed(1)}°C (${value.toFixed(1)}°F)`;
  }

  function formatRow(row) {
    const label = (() => {
      if (prettyLabels[row.name]) return prettyLabels[row.name];
      if (/^SDAQnet_\(.+\)_outVoltage$/i.test(row.name)) return 'Bus Voltage';
      if (/^SDAQnet_\(.+\)_outAmperage$/i.test(row.name)) return 'Bus Amperage';
      if (/^SDAQnet_\(.+\)_ShuntTemp$/i.test(row.name)) return 'Shunt Temperature';
      if (/^SDAQnet_\(.+\)_last_calibration_UNIX$/i.test(row.name)) return 'Last SDAQ Net Power Calibration';
      return row.name.replace('_UNIX', '');
    })();

    if (row.name.includes('last_calibration_UNIX')) {
      const value = row.value ? new Date(row.value * 1000).toLocaleDateString() : 'UnCalibrated';
      return { label, value };
    }

    if (row.name === 'BUS_Utilization') {
      return { label, value: formatPercent(row.value, 1) };
    }

    if (row.name === 'BUS_Error_Rate') {
      const percent = typeof row.value === 'number' ? row.value * 100 : row.value;
      return { label, value: formatPercent(percent, 4) };
    }

    if (row.name === 'CPU_temp') {
      return { label, value: formatCpuTempF(row.value) };
    }

    if (/^SDAQnet_\(.+\)_ShuntTemp$/i.test(row.name)) {
      return { label, value: formatShuntTempF(row.value) };
    }

    if (row.name === 'Up_time' && typeof row.value === 'number') {
      const sec = Math.max(0, Math.floor(row.value));
      const h   = Math.floor(sec / 3600);
      const m   = Math.floor((sec % 3600) / 60);
      const s   = sec % 60;
      return { label, value: `${h}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}` };
    }

    if (row.name === 'Detected_SDAQs' || row.name === 'Incomplete_SDAQs') {
      const value = typeof row.value === 'number' ? Math.round(row.value) : row.value;
      return { label, value: `${value}` };
    }

    if (typeof row.value === 'number') {
      const abs    = Math.abs(row.value);
      const digits = abs >= 100 ? 0 : abs >= 10 ? 1 : 2;
      return { label, value: `${row.value.toFixed(digits)}${row.unit || ''}` };
    }

    return { label, value: `${row.value}${row.unit || ''}` };
  }

  function mkDetailsTable(block) {
    const tbl = document.createElement('table');
    tbl.className = 'table';

    // Header
    const thead = document.createElement('thead');
    thead.innerHTML = `<tr><th colspan="2">${block.if_name}</th></tr>`;
    tbl.appendChild(thead);

    // Body
    const tb = document.createElement('tbody');

    (block.connections || []).forEach(row => {
      const tr = document.createElement('tr');
      const { label, value } = formatRow(row);

      tr.innerHTML = `<td>${label}</td><td>${value}</td>`;
      tb.appendChild(tr);
    });

    tbl.appendChild(tb);
    return tbl;
  }

  function renderDetails() {
    const wrap = $('#detailsWrap');
    wrap.innerHTML = '';

    if (detailsError) {
      const err = document.createElement('div');
      err.textContent = detailsError;
      wrap.appendChild(err);
      return;
    }

    if (!detailsCache) {
      const loading = document.createElement('div');
      loading.textContent = 'Loading…';
      wrap.appendChild(loading);
      return;
    }

    detailsCache.forEach(b => wrap.appendChild(mkDetailsTable(b)));
  }

  /* ------------------------------------------
   * Rendering – Logs
   * ------------------------------------------ */
  function renderLogs() {
    const sel = $('#loggerSelect');
    const term = $('#logTerminal');

    // TODO: Replace with real fetch / stream tail
    term.textContent = 'Logs coming soon…';
  }

  async function fetchDetails() {
    if (detailsCache || detailsError) return;

    try {
      const res = await fetch(resolveEndpoint(), { cache: 'no-store' });
      if (!res.ok) throw new Error('Failed to load system status');

      const payload = await res.json();
      if (!payload.ok) throw new Error(payload.error || 'Malformed response');

      detailsCache = payload.entries || [];
      mockBadge.hidden = true;
    } catch (err) {
      detailsError = err.message || String(err);
      mockBadge.hidden = true;
    } finally {
      renderDetails();
    }
  }

  // Initial paint (Details tab)
  fetchDetails();
  renderDetails();
})();