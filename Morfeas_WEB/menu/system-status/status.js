/* ============================================================================
 * System Status (popup)
 * ----------------------------------------------------------------------------
 * Purpose : Render system details + logs.
 * Tabs    : Details / System logs / System Journal.
 * ========================================================================== */

(function () {
  /* ------------------------------------------
   * Shorthand DOM helpers
   * ------------------------------------------ */
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

  const systemStatusApi = window.LOG_WEB?.api?.systemStatus;
  const statusFormatter = window.LOG_WEB?.ui?.systemStatusFormatter;

  /* ------------------------------------------
   * Backend endpoint
   * ------------------------------------------ */
  const DEFAULT_ENDPOINT = '../../backend/api_system_status.php?action=details';

  function resolveEndpoint() {
    const fromWindow = typeof window !== 'undefined' && typeof window.API_ENDPOINT === 'string' && window.API_ENDPOINT.trim();
    return fromWindow || DEFAULT_ENDPOINT;
  }

  /* ------------------------------------------
   * Tabs
   * ------------------------------------------ */
  const panels = {
    details: $('#panel-details'),
    logs: $('#panel-logs'),
    journal: $('#panel-journal'),
  };
  const loggerSelect = $('#loggerSelect');
  const loggerExportSelect = $('#loggerExportSelect');
  const logTerminal = $('#logTerminal');
  const reloadLoggersBtn = $('#reloadLoggers');
  const exportLogsBtn = $('#exportLogs');
  const journalScope = $('#journalScope');
  const journalLines = $('#journalLines');
  const journalTerminal = $('#journalTerminal');
  const reloadJournalBtn = $('#reloadJournal');
  const exportJournalBtn = $('#exportJournal');

  let detailsCache = null;
  let detailsError = null;
  const logsState = {
    active: false,
    timer: null,
    selected: '',
    exportSelected: [],
    mtime: 0,
    primed: false,
  };

  const journalState = {
    active: false,
    timer: null,
    lastContent: '',
  };

  async function showTab(key) {
    Object.values(panels).forEach((p) => p && p.classList.remove('show'));
    if (panels[key]) {
      panels[key].classList.add('show');
    }

    logsState.active = key === 'logs';
    journalState.active = key === 'journal';
    if (key === 'details') await renderDetails();
    if (key === 'logs') {
      renderLogs();
      try {
        await refreshLoggerNames(true);
        await pollLogger(false);
      } catch (err) {
        if (logTerminal) {
          logTerminal.textContent = `Log load failed: ${err.message || err}`;
        }
      }
      ensureLogTimer();
    }
    if (key === 'journal') {
      renderJournal();
      try {
        await pollJournal(false);
      } catch (err) {
        if (journalTerminal) {
          journalTerminal.textContent = `Journal load failed: ${err.message || err}`;
        }
      }
      ensureJournalTimer();
    }
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

  function formatShuntTempC(value) {
    if (typeof value !== 'number' || Number.isNaN(value)) return value;
    const c = (value - 32) * 5 / 9;
    return `${c.toFixed(1)}°C`;
  }

  function formatCpuTempC(value) {
    if (typeof value !== 'number' || Number.isNaN(value)) return value;
    const c = (value - 32) * 5 / 9;
    return `${c.toFixed(1)}°C`;
  }

  function fallbackFormatRow(row) {
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
      return { label, value: formatCpuTempC(row.value) };
    }

    if (/^SDAQnet_\(.+\)_ShuntTemp$/i.test(row.name)) {
      return { label, value: formatShuntTempC(row.value) };
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

  const formatRow = (row) => statusFormatter?.formatRow?.(row) || fallbackFormatRow(row);

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
  function escapeHtml(value) {
    return String(value ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#39;');
  }

  function ansiColorizeToHtml(raw) {
    const palette = {
      '31': '#ef4444',
      '32': '#22c55e',
      '33': '#facc15',
      '34': '#60a5fa',
      '35': '#d946ef',
      '36': '#22d3ee',
    };

    let html = escapeHtml(raw || '');
    html = html.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    html = html.replace(/\x1b\[(0|3[1-6])m/g, (full, code) => {
      if (code === '0') return '</span>';
      const color = palette[code];
      return color ? `<span style="color:${color}">` : '';
    });
    html = html.replace(/\n/g, '<br>');
    return html;
  }

  function renderLogs() {
    if (!loggerSelect || !logTerminal) return;
    if (!logsState.selected) {
      logTerminal.textContent = 'Select a logger file.';
    }
  }


  function fileTimestampNow() {
    const d = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}${pad(d.getMonth() + 1)}${pad(d.getDate())}_${pad(d.getHours())}${pad(d.getMinutes())}${pad(d.getSeconds())}`;
  }

  function downloadTextFile(filename, content) {
    const blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  }

  function normalizeJournalScopeSelection() {
    if (!journalScope) return;
    const values = readMultiSelectValues(journalScope);
    if (values.length === 0) {
      const allOpt = journalScope.querySelector('option[value="all"]');
      if (allOpt) allOpt.selected = true;
      return;
    }
    if (values.includes('all') && values.length > 1) {
      Array.from(journalScope.options || []).forEach((opt) => {
        if (String(opt.value).toLowerCase() === 'all') {
          opt.selected = false;
        }
      });
    }
  }

  async function exportSelectedLogs() {
    if (!loggerExportSelect || !systemStatusApi?.buildLoggersExportUrl) {
      if (logTerminal) logTerminal.textContent = 'Log export API unavailable.';
      return;
    }

    const selected = readMultiSelectValues(loggerExportSelect);
    logsState.exportSelected = selected;

    if (!selected.length) {
      if (logTerminal) logTerminal.textContent = 'Select one or more log files to export.';
      return;
    }

    const url = systemStatusApi.buildLoggersExportUrl(selected);
    const a = document.createElement('a');
    a.href = url;
    a.rel = 'noopener';
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    a.remove();

    if (logTerminal) {
      logTerminal.textContent = `Export started for ${selected.length} log file(s) as one txt file.`;
    }
  }

  async function exportJournalContent() {
    if (!journalState.lastContent || !journalState.lastContent.trim()) {
      await pollJournal(false);
    }
    const content = (journalState.lastContent || '').trim();
    if (!content) {
      if (journalTerminal) journalTerminal.textContent = 'No journal content available for export.';
      return;
    }

    downloadTextFile(`system_journal_${fileTimestampNow()}.txt`, `${journalState.lastContent}\n`);
    if (journalTerminal) {
      journalTerminal.scrollTop = journalTerminal.scrollHeight;
    }
  }

  async function refreshLoggerNames(forceKeepCurrent = false) {
    if (!systemStatusApi?.fetchLoggers || !loggerSelect) return;
    const payload = await systemStatusApi.fetchLoggers();
    if (!payload?.ok) throw new Error(payload?.error || 'Failed to load logger names');

    const names = Array.isArray(payload.logger_names) ? payload.logger_names : [];
    const prev = forceKeepCurrent ? logsState.selected : (loggerSelect.value || logsState.selected || '');
    const prevExport = new Set(
      (logsState.exportSelected && logsState.exportSelected.length)
        ? logsState.exportSelected
        : readMultiSelectValues(loggerExportSelect)
    );

    loggerSelect.innerHTML = '';

    const baseOpt = document.createElement('option');
    baseOpt.value = '';
    baseOpt.textContent = names.length ? 'Select logger…' : 'No loggers found';
    loggerSelect.appendChild(baseOpt);

    names.forEach((name) => {
      const opt = document.createElement('option');
      opt.value = name;
      opt.textContent = name;
      loggerSelect.appendChild(opt);
    });

    if (loggerExportSelect) {
      loggerExportSelect.innerHTML = '';
      names.forEach((name) => {
        const opt = document.createElement('option');
        opt.value = name;
        opt.textContent = name;
        if (prevExport.has(name)) opt.selected = true;
        loggerExportSelect.appendChild(opt);
      });
      logsState.exportSelected = readMultiSelectValues(loggerExportSelect);
    }

    if (prev && names.includes(prev)) {
      loggerSelect.value = prev;
      logsState.selected = prev;
    } else {
      loggerSelect.value = '';
      logsState.selected = '';
      logsState.mtime = 0;
      logsState.primed = false;
    }
  }

  async function pollLogger(ifUpdated = true) {
    if (!logsState.active || !systemStatusApi?.fetchLogger || !loggerSelect || !logTerminal) {
      return;
    }

    const selected = loggerSelect.value || logsState.selected;
    if (!selected) {
      logTerminal.textContent = 'Select a logger file.';
      return;
    }

    const payload = await systemStatusApi.fetchLogger(selected, {
      ifUpdated: ifUpdated && logsState.primed,
      mtime: logsState.mtime,
    });

    if (!payload?.ok) throw new Error(payload?.error || 'Failed to load logger content');

    if (payload.updated === false) {
      logsState.primed = true;
      logsState.mtime = Number(payload.mtime) || logsState.mtime;
      return;
    }

    logsState.selected = selected;
    logsState.primed = true;
    logsState.mtime = Number(payload.mtime) || 0;
    logTerminal.innerHTML = ansiColorizeToHtml(payload.content || '');
    logTerminal.scrollTop = logTerminal.scrollHeight;
  }

  function ensureLogTimer() {
    if (logsState.timer) return;
    logsState.timer = window.setInterval(async () => {
      if (!logsState.active) return;
      if (document.hidden) return;
      try {
        await pollLogger(true);
      } catch (err) {
        if (logTerminal) logTerminal.textContent = `Log update failed: ${err.message || err}`;
      }
    }, 1000);
  }

  function readMultiSelectValues(selectEl) {
    if (!selectEl) return [];
    return Array.from(selectEl.selectedOptions || [])
      .map((opt) => String(opt.value || '').trim())
      .filter(Boolean);
  }

  function resolveJournalUnits() {
    const selected = readMultiSelectValues(journalScope)
      .map((v) => v.toLowerCase());

    if (!selected.length || selected.includes('all')) {
      return '';
    }

    const units = [];
    if (selected.includes('morfeas')) units.push('Morfeas_system.service');
    if (selected.includes('apache2')) units.push('apache2.service');
    if (selected.includes('ssh')) units.push('ssh.service');

    return units.join(',');
  }

  function resolveJournalLines() {
    const fallback = 500;
    const raw = Number.parseInt(journalLines?.value || `${fallback}`, 10);
    if (!Number.isFinite(raw)) return fallback;
    if (raw < 50) return 50;
    if (raw > 3000) return 3000;
    return raw;
  }

  function renderJournal() {
    if (!journalTerminal) return;
    if ((journalTerminal.textContent || '').trim() === '') {
      journalTerminal.textContent = 'Loading journal...';
    }
  }

  async function pollJournal(scrollToBottom = true) {
    if (!journalState.active || !systemStatusApi?.fetchJournal || !journalTerminal) {
      return;
    }

    const payload = await systemStatusApi.fetchJournal({
      units: resolveJournalUnits(),
      lines: resolveJournalLines(),
    });

    if (!payload?.ok) throw new Error(payload?.error || 'Failed to load system journal');

    journalState.lastContent = payload.content || '';
    journalTerminal.innerHTML = ansiColorizeToHtml(journalState.lastContent);
    if (scrollToBottom) {
      journalTerminal.scrollTop = journalTerminal.scrollHeight;
    } else {
      journalTerminal.scrollTop = 0;
    }
  }

  function ensureJournalTimer() {
    if (journalState.timer) return;
    journalState.timer = window.setInterval(async () => {
      if (!journalState.active) return;
      if (document.hidden) return;
      try {
        await pollJournal(false);
      } catch (err) {
        if (journalTerminal) {
          journalTerminal.textContent = `Journal update failed: ${err.message || err}`;
        }
      }
    }, 5000);
  }

  async function fetchDetails() {
    if (detailsCache || detailsError) return;

    try {
      const payload = systemStatusApi
        ? await systemStatusApi.fetchDetails()
        : await (async () => {
          const res = await fetch(resolveEndpoint(), { cache: 'no-store' });
          if (!res.ok) throw new Error('Failed to load system status');
          return res.json();
        })();
      if (!payload.ok) throw new Error(payload.error || 'Malformed response');

      detailsCache = payload.entries || [];
    } catch (err) {
      detailsError = err.message || String(err);
    } finally {
      renderDetails();
    }
  }

  // Initial paint (Details tab)
  reloadLoggersBtn?.addEventListener('click', async () => {
    try {
      await refreshLoggerNames(true);
      await pollLogger(false);
    } catch (err) {
      if (logTerminal) logTerminal.textContent = `Reload failed: ${err.message || err}`;
    }
  });

  loggerSelect?.addEventListener('change', async () => {
    logsState.selected = loggerSelect.value || '';
    logsState.mtime = 0;
    logsState.primed = false;
    try {
      await pollLogger(false);
    } catch (err) {
      if (logTerminal) logTerminal.textContent = `Log load failed: ${err.message || err}`;
    }
  });

  loggerExportSelect?.addEventListener('change', () => {
    logsState.exportSelected = readMultiSelectValues(loggerExportSelect);
  });

  exportLogsBtn?.addEventListener('click', async () => {
    try {
      await exportSelectedLogs();
    } catch (err) {
      if (logTerminal) logTerminal.textContent = `Log export failed: ${err.message || err}`;
    }
  });

  reloadJournalBtn?.addEventListener('click', async () => {
    try {
      await pollJournal(true);
    } catch (err) {
      if (journalTerminal) journalTerminal.textContent = `Journal reload failed: ${err.message || err}`;
    }
  });

  exportJournalBtn?.addEventListener('click', async () => {
    try {
      await exportJournalContent();
    } catch (err) {
      if (journalTerminal) journalTerminal.textContent = `Journal export failed: ${err.message || err}`;
    }
  });

  journalScope?.addEventListener('change', async () => {
    normalizeJournalScopeSelection();
    try {
      await pollJournal(false);
    } catch (err) {
      if (journalTerminal) journalTerminal.textContent = `Journal load failed: ${err.message || err}`;
    }
  });

  journalLines?.addEventListener('change', async () => {
    try {
      await pollJournal(false);
    } catch (err) {
      if (journalTerminal) journalTerminal.textContent = `Journal load failed: ${err.message || err}`;
    }
  });

  document.addEventListener('visibilitychange', async () => {
    if (document.hidden) return;
    try {
      if (logsState.active) {
        await pollLogger(false);
      } else if (journalState.active) {
        await pollJournal(false);
      }
    } catch (err) {
      // Silent resume guard: tab visibility toggle should not break page state.
    }
  });

  normalizeJournalScopeSelection();
  fetchDetails();
  renderDetails();
})();
