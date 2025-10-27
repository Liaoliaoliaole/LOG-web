/* ============================================================================
 * System Status (popup)
 * ----------------------------------------------------------------------------
 * Purpose : Visual prototype for Details + System logs.
 * Mode    : IS_MOCK=true renders mock data; flip to false when wiring backend.
 * Tabs    : Details (tables) / Logs (simple viewer).
 * Notes   : Replace `detailsData` and `renderLogs` with real fetch in integration.
 * ========================================================================== */

(function () {
  /* ------------------------------------------
   * Shorthand DOM helpers
   * ------------------------------------------ */
  const $  = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

  /* ------------------------------------------
   * Config: mock mode flag
   * ------------------------------------------ */
  const IS_MOCK = true;
  $('#mockBadge').hidden = !IS_MOCK;

  /* ------------------------------------------
   * Tabs
   * ------------------------------------------ */
  const panels = {
    details: $('#panel-details'),
    logs:    $('#panel-logs')
  };

  function showTab(key) {
    Object.values(panels).forEach(p => p.classList.remove('show'));
    panels[key].classList.add('show');

    if (key === 'details') renderDetails();
    if (key === 'logs')    renderLogs();
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
   * Mock dataset (field names aligned with original codebase)
   * BUS metrics are merged into the connections rows for a unified two-column view.
   * ------------------------------------------ */
  const detailsData = [
    {
      if_name: 'SDAQs (can0)',
      BUS_Utilization: 0.00,
      Detected_SDAQs: 0,
      Incomplete_SDAQs: 0,
      last_calibration_UNIX: 0,
      Electrics: { BUS_voltage: 24.01, BUS_amperage: 0.03 },
      connections: [
        { name: 'BUS_Utilization', value: 0.00, unit: '%' },
        { name: 'Detected_SDAQs', value: 0, unit: '' },
        { name: 'Incomplete_SDAQs', value: 0, unit: '' },
        { name: 'SDAQnet_(can0)_last_calibration_UNIX', value: 0, unit: '' },
        { name: 'SDAQnet_(can0)_outVoltage', value: '24.01', unit: 'V' },
        { name: 'SDAQnet_(can0)_outAmperage', value: '0.03', unit: 'A' },
        { name: 'SDAQnet_(can0)_ShuntTemp', value: '83.0', unit: '°F' },
        { name: 'BUS Voltage', value: 24.01, unit: 'V' },
        { name: 'BUS Current', value: 0.03, unit: 'A' }
      ]
    },
    {
      if_name: 'SDAQs (can1)',
      BUS_Utilization: 4.70,
      Detected_SDAQs: 1,
      Incomplete_SDAQs: 0,
      last_calibration_UNIX: 0,
      Electrics: { BUS_voltage: 23.86, BUS_amperage: 0.04 },
      connections: [
        { name: 'BUS_Utilization', value: 4.70, unit: '%' },
        { name: 'Detected_SDAQs', value: 1, unit: '' },
        { name: 'Incomplete_SDAQs', value: 0, unit: '' },
        { name: 'SDAQnet_(can1)_last_calibration_UNIX', value: 0, unit: '' },
        { name: 'SDAQnet_(can1)_outVoltage', value: '23.86', unit: 'V' },
        { name: 'SDAQnet_(can1)_outAmperage', value: '0.04', unit: 'A' },
        { name: 'SDAQnet_(can1)_ShuntTemp', value: '83.0', unit: '°F' },
        { name: 'BUS Voltage', value: 23.86, unit: 'V' },
        { name: 'BUS Current', value: 0.04, unit: 'A' }
      ]
    },
    {
      if_name: 'RPi_Health_Status',
      connections: [
        { name: 'CPU_temp', value: '93.3', unit: '°F' },
        { name: 'CPU_Util', value: '0.25', unit: '%' },
        { name: 'RAM_Util', value: '1.52', unit: '%' },
        { name: 'Disk_Util', value: '23.92', unit: '%' },
        { name: 'Up_time', value: '0:1:20', unit: '' }
      ]
    }
  ];

  /* ------------------------------------------
   * Rendering – Details
   * ------------------------------------------ */
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

      // Label cleanup
      const label = row.name.replace('_UNIX', '');

      // Value formatting
      let value;
      if (row.name.includes('last_calibration_UNIX')) {
        value = row.value ? new Date(row.value * 1000).toLocaleDateString() : 'UnCalibrated';
      } else if (typeof row.value === 'number') {
        value = `${row.value}${row.unit || ''}`;
      } else {
        value = `${row.value}${row.unit || ''}`;
      }

      tr.innerHTML = `<td>${label}</td><td>${value}</td>`;
      tb.appendChild(tr);
    });

    tbl.appendChild(tb);
    return tbl;
  }

  function renderDetails() {
    const wrap = $('#detailsWrap');
    wrap.innerHTML = '';
    detailsData.forEach(b => wrap.appendChild(mkDetailsTable(b)));
  }

  /* ------------------------------------------
   * Rendering – Logs
   * ------------------------------------------ */
  function renderLogs() {
    const sel  = $('#loggerSelect');
    const term = $('#logTerminal');

    if (IS_MOCK) {
      term.textContent =
`[MOCK] Morfeas boot…
[MOCK] webif ready
[MOCK] sdaq0: 16 channels online
[MOCK] can1 utilization 74%
[MOCK] tail -f ${sel.value || 'system.log'}`;
    } else {
      // TODO: Replace with real fetch / stream tail
      term.textContent = 'Loading logs…';
    }
  }

  // Initial paint (Details tab)
  renderDetails();
})();
