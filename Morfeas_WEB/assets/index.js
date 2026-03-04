/* ===========================================================================
 * LOG WEB --- Index Bootstrap
 * ========================================================================== */

(function () {
  document.addEventListener('DOMContentLoaded', () => {

    /* =======================================================================
     * 0) CONSTANTS & LIGHT HELPERS
     * ======================================================================= */

    const config = window.LOG_WEB?.config;
    const channelsApi = window.LOG_WEB?.api?.channels;
    const systemStatusApi = window.LOG_WEB?.api?.systemStatus;
    const statusFormatter = window.LOG_WEB?.ui?.systemStatusFormatter;
    const ORIG_BASE_PATH = config?.basePath || window.ORIG_BASE_PATH || '';

    const $ = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    /* =======================================================================
     * 1) DOM REFERENCES
     * ======================================================================= */

    const menuBtn = $('#menuBtn');
    const dropdown = $('#mainMenu');

    const ticker = $('.ticker');
    const track = ticker ? ticker.querySelector('.track') : null;

    const searchInput = $('#searchInput');
    const searchBtn = $('#searchBtn');

    const master = $('#masterCheck');
    const tbody = $('#isoTableBody');

    const API_ISO = channelsApi?.buildUrl?.() || '/backend/api_channels.php';

    const AUTO_REFRESH_MS = 1000; // poll JSON every second

    const ctx = $('#ctx');

    const addBtn = $('#addBtn');
    const importBtn = $('#importBtn');

    let currentSearch = '';
    const columnFilters = {
      status: null,
      type: null
    };
    let openFilterMenu = null;
    const sortState = {
      key: null,
      dir: 'asc',
    };

    const GRAPH_LENGTH = 120;
    const measurementHistory = new Map();

    // Cache of the latest channel payloads (for Edit popup prefill)
    let isoChannelCache = [];
    const isoChannelMap = new Map();

    let tableFetchInFlight = false;
    let pendingTableReload = false;
    let lastTableSignature = null;

    /* =======================================================================
     * 2) GENERIC UTILITIES (SELECTION, POPUPS, ETC.)
     * ======================================================================= */

    const isRowVisible = (tr) => {
      if (!tr) return false;
      return !!(tr.offsetParent || tr.getClientRects().length);
    };

    const rowChecks = () =>
      $$('tbody input[type="checkbox"]').filter((c) =>
        isRowVisible(c.closest('tr'))
      );

    function updateChannelCache(channels) {
      isoChannelCache = Array.isArray(channels) ? channels : [];
      isoChannelMap.clear();
      isoChannelCache.forEach((ch) => {
        const key = (ch.iso_channel || '').toString().toUpperCase();
        if (key) isoChannelMap.set(key, ch);
      });
    }

    function findChannelByIso(iso) {
      if (!iso) return null;
      return isoChannelMap.get(iso.toString().toUpperCase()) || null;
    }

    function pushMeasurement(isoKey, measValue) {
      if (!isoKey) return;
      const arr = measurementHistory.get(isoKey) || [];
      if (Number.isFinite(measValue)) {
        arr.push(measValue);
        if (arr.length > GRAPH_LENGTH) {
          arr.splice(0, arr.length - GRAPH_LENGTH);
        }
      } else if (!measurementHistory.has(isoKey)) {
        measurementHistory.set(isoKey, []);
        return;
      }
      measurementHistory.set(isoKey, arr);
    }

    function buildSparkline(values) {
      const data = Array.isArray(values) ? values.filter(Number.isFinite) : [];
      if (data.length < 2) return null;

      const w = 80;
      const h = 24;
      const min = Math.min(...data);
      const max = Math.max(...data);
      const span = max - min || 1;

      const points = data.map((v, idx) => {
        const x = (idx / Math.max(1, data.length - 1)) * (w - 2) + 1;
        const y = h - 1 - ((v - min) / span) * (h - 2);
        return [x, y];
      });

      const pathData = points
        .map(([x, y], idx) => `${idx === 0 ? 'M' : 'L'}${x.toFixed(2)} ${y.toFixed(2)}`)
        .join(' ');

      const areaPath = `M1 ${h - 1} ${pathData} L${points[points.length - 1][0].toFixed(2)} ${h - 1} Z`;

      const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svg.classList.add('sparkline');
      svg.setAttribute('viewBox', `0 0 ${w} ${h}`);
      svg.setAttribute('preserveAspectRatio', 'none');

      const fill = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      fill.setAttribute('d', areaPath);
      fill.setAttribute('class', 'spark-fill');
      svg.appendChild(fill);

      const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      path.setAttribute('d', pathData);
      svg.appendChild(path);

      return svg;
    }

    const selectedRows = () =>
      rowChecks()
        .map((c) => c.closest('tr'))
        .filter((tr) => tr && tr.querySelector('input[type="checkbox"]').checked);

    function syncRowSelectedClass() {
      $$('tbody tr').forEach((tr) => {
        const cb = tr.querySelector('input[type="checkbox"]');
        tr.classList.toggle('selected', !!(cb && cb.checked));
      });
    }

    function updateMasterCheckbox() {
      const checks = rowChecks();
      const countChecked = checks.filter((c) => c.checked).length;

      if (checks.length === 0) {
        if (master) {
          master.checked = false;
          master.indeterminate = false;
        }
        syncRowSelectedClass();
        return;
      }

      if (master) {
        master.checked = countChecked === checks.length;
        master.indeterminate = countChecked > 0 && countChecked < checks.length;
      }
      syncRowSelectedClass();
    }

    function canUseGlobalDelete(e) {
      const el = document.activeElement;
      if (!el) return true;
      const tag = el.tagName;
      const editable = el.isContentEditable;
      return !(
        editable ||
        tag === 'INPUT' ||
        tag === 'TEXTAREA' ||
        tag === 'SELECT'
      );
    }

    async function deleteSelectedRows() {
      const rows = selectedRows();
      if (!rows.length) return;

      const isoList = rows
        .map((tr) => tr.dataset.iso || tr.querySelector('td[data-col="iso"]')?.textContent || '')
        .map((t) => t.trim())
        .filter(Boolean);

      const label = isoList.length === 1 ? isoList[0] : `${isoList.length} selected channels`;
      const ok = confirm(`Delete ${label}?`);
      if (!ok) return;

      try {
        for (const iso of isoList) {
          const json = channelsApi
            ? await channelsApi.deleteChannel(iso)
            : await (async () => {
              const res = await fetch(`${API_ISO}?iso=${encodeURIComponent(iso)}`, {
                method: 'DELETE',
                headers: { Accept: 'application/json' }
              });
              if (!res.ok) {
                throw new Error('HTTP ' + res.status);
              }
              return res.json();
            })();
          if (json && json.ok === false) {
            throw new Error(json.error || 'Delete failed');
          }
        }
        loadIsoTable(true);
      } catch (err) {
        alert('Failed to delete channel(s): ' + (err?.message || err));
      } finally {
        hideCtx && hideCtx();
      }
    }

    function visibleRows() {
      if (!tbody) return [];
      return $$('.row', tbody).filter((r) => isRowVisible(r));
    }

    /* =======================================================================
     * 3) MENU (OPEN/CLOSE)
     * ======================================================================= */

    const openMenu = () => {
      dropdown?.classList.add('open');
      menuBtn?.setAttribute('aria-expanded', 'true');
    };

    const closeMenu = () => {
      dropdown?.classList.remove('open');
      menuBtn?.setAttribute('aria-expanded', 'false');
    };

    function closeFilterMenu() {
      if (!openFilterMenu) return;
      if (openFilterMenu.parentNode) {
        openFilterMenu.parentNode.removeChild(openFilterMenu);
      }
      openFilterMenu = null;
    }

    menuBtn?.addEventListener('click', (e) => {
      e.stopPropagation();
      if (!dropdown) return;
      dropdown.classList.contains('open') ? closeMenu() : openMenu();
    });

    // Close dropdown once a menu item is chosen (matches legacy UX)
    if (dropdown) {
      $$('.menu-item', dropdown).forEach((item) => {
        item.addEventListener('click', () => closeMenu());
      });
    }

    document.addEventListener('click', (e) => {
      if (dropdown && !dropdown.contains(e.target) && e.target !== menuBtn) {
        closeMenu();
      }
      if (openFilterMenu && !openFilterMenu.contains(e.target)) {
        closeFilterMenu();
      }
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        closeMenu();
        closeFilterMenu();
      }
    });

    /* =======================================================================
     * 4) TICKER (SYSTEM STATUS)
     * ======================================================================= */

    function formatTickerValue(name, value, unit) {
      if (statusFormatter?.formatTickerValue) {
        return statusFormatter.formatTickerValue(name, value, unit);
      }
      if (value === null || value === undefined || value === '') {
        return '—';
      }

      const numeric = typeof value === 'number' ? value : Number(value);
      const hasNumber = Number.isFinite(numeric);

      if (name === 'CPU_temp' && hasNumber) {
        return `${numeric.toFixed(1)}°C`;
      }
      if (name === 'CPU_Util' && hasNumber) {
        return `${numeric.toFixed(2)}%`;
      }
      if (name === 'RAM_Util' && hasNumber) {
        return `${numeric.toFixed(2)}%`;
      }
      if (name === 'Disk_Util' && hasNumber) {
        return `${numeric.toFixed(1)}%`;
      }
      if (name === 'Up_time' && hasNumber) {
        const sec = Math.max(0, Math.floor(numeric));
        const h = Math.floor(sec / 3600);
        const m = Math.floor((sec % 3600) / 60);
        const s = sec % 60;
        return `${h}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
      }

      if (hasNumber) {
        const abs = Math.abs(numeric);
        const digits = abs >= 100 ? 0 : abs >= 10 ? 1 : 2;
        return `${numeric.toFixed(digits)}${unit || ''}`;
      }

      return `${value}${unit || ''}`;
    }

    async function loadTickerData() {
      if (!track || track.children.length) {
        setupTicker();
        return;
      }

      const fallback = [
        'CPU_temp\t—',
        'CPU_Util\t—',
        'RAM_Util\t—',
        'Disk_Util\t—',
        'Up_time\t—',
      ];

      try {
        const payload = systemStatusApi
          ? await systemStatusApi.fetchDetails()
          : await (async () => {
            const res = await fetch('backend/api_system_status.php?action=details', { cache: 'no-store' });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
          })();
        if (!payload.ok) throw new Error('Invalid system status payload');

        const sysEntry = (payload.entries || []).find((entry) => entry.if_name === 'RPi_Health_Status');
        const connections = Array.isArray(sysEntry?.connections) ? sysEntry.connections : [];
        const items = connections
          .filter((row) => row && row.name && row.name !== 'logstat_build_date_UNIX')
          .map((row) => {
            const name = row.name || '';
            const value = formatTickerValue(name, row.value, row.unit || '');
            return `${name}\t${value}`;
          })
          .filter(Boolean);

        const list = items.length ? items : fallback;
        list.forEach((t) => {
          const div = document.createElement('div');
          div.className = 'item';
          div.textContent = t;
          track.appendChild(div);
        });
        setupTicker();
      } catch (err) {
        fallback.forEach((t) => {
          const div = document.createElement('div');
          div.className = 'item';
          div.textContent = t;
          track.appendChild(div);
        });
        setupTicker();
      }
    }

    loadTickerData();

    function setupTicker() {
      if (!ticker || !track || !track.children.length) return;

      const first = track.children[0];
      const ITEM_H = Math.max(1, Math.round(first.getBoundingClientRect().height));

      ticker.style.height = ITEM_H + 'px';

      const chip = ticker.closest('.chip');
      if (chip) {
        const cs = getComputedStyle(chip);
        const padY = parseFloat(cs.paddingTop) + parseFloat(cs.paddingBottom);
        const need = ITEM_H + padY;
        if (chip.clientHeight < need) chip.style.minHeight = need + 'px';
      }

      let i = 0;
      const total = track.children.length;
      const step = () => {
        track.style.transform = `translate3d(0, ${-(ITEM_H * i)}px, 0)`;
      };

      step();
      setInterval(() => {
        i = (i + 1) % total;
        step();
      }, 2000);
    }

    /* =======================================================================
     * 5) SEARCH + COLUMN FILTERS + NEXT CALIBRATION COLOR
     * ======================================================================= */

    function getCellText(tr, key) {
      const cell = tr.querySelector('td[data-col="' + key + '"]');
      if (!cell) return '';
      if (key === 'status') {
        const baseStatus = (cell.dataset.status || '').trim();
        if (baseStatus) return baseStatus.toLowerCase();
      }
      return cell.textContent.trim().toLowerCase();
    }

    function updateRowVisibility() {
      const q = (currentSearch || '').trim().toLowerCase();

      $$('tbody tr').forEach((tr) => {
        let visible = true;

        if (q) {
          const rowText = tr.textContent.toLowerCase();
          if (!rowText.includes(q)) visible = false;
        }

        if (visible && columnFilters.status) {
          const wanted = columnFilters.status.toLowerCase();
          const st = getCellText(tr, 'status');
          if (st !== wanted) visible = false;
        }

        if (visible && columnFilters.type) {
          const tp = getCellText(tr, 'type');
          if (tp !== columnFilters.type.toLowerCase()) visible = false;
        }

        tr.style.display = visible ? '' : 'none';
      });

      updateMasterCheckbox();
      applySort();
    }

    function getSortValue(tr, key) {
      const cell = tr.querySelector(`td[data-col="${key}"]`);
      if (!cell) return '';
      return (cell.textContent || '').trim().toLowerCase();
    }

    function applySort() {
      if (!tbody || !tbody.children.length) return;
      const { key, dir } = sortState;
      if (!key) return;
      const rows = Array.from(tbody.querySelectorAll('tr'));
      const factor = dir === 'desc' ? -1 : 1;
      rows.sort((a, b) => {
        const av = getSortValue(a, key);
        const bv = getSortValue(b, key);
        if (av === bv) return 0;
        return av > bv ? factor : -factor;
      });
      rows.forEach((row) => tbody.appendChild(row));
      rows.forEach((row, idx) => {
        const indexCell = row.children[1];
        if (indexCell) indexCell.textContent = String(idx + 1);
      });
    }

    function setSortState(key, dir) {
      sortState.key = key;
      sortState.dir = dir;
      document.querySelectorAll('.sort-icon').forEach((icon) => {
        const parent = icon.closest('.sort-icons');
        const matchKey = parent?.dataset.sortKey;
        const matchDir = icon.dataset.dir;
        icon.classList.toggle('active', matchKey === key && matchDir === dir);
      });
      applySort();
    }

    function filterTable(query) {
      currentSearch = (query || '').trim();
      updateRowVisibility();
    }

    function launchSearch() {
      filterTable(searchInput ? searchInput.value : '');
    }

    searchBtn?.addEventListener('click', (e) => {
      e.preventDefault();
      launchSearch();
    });

    searchInput?.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        launchSearch();
      }
    });

    searchInput?.addEventListener('input', (e) => {
      const value = e.target?.value || '';
      if (!value.trim()) {
        filterTable('');
      }
    });

    function buildFilterMenu(th, key) {
      closeFilterMenu();

      const menu = document.createElement('div');
      menu.className = 'col-filter-menu';
      menu.dataset.key = key;

      const body = document.createElement('div');
      body.className = 'col-filter-menu-body';

      const current = columnFilters[key] || null;

      function addItem(label, value) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'col-filter-item';
        if ((value === null && current === null) || (value && value === current)) {
          btn.classList.add('active');
        }
        btn.textContent = label;
        btn.addEventListener('click', () => {
          columnFilters[key] = value;
          closeFilterMenu();
          updateRowVisibility();
        });
        body.appendChild(btn);
      }

      const values = new Set((isoChannelCache || []).map((ch) => {
        if (!ch) return null;
        if (key === 'status') return ch.status || ch.Status || null;
        if (key === 'type') return ch.dev_type || ch.interface_type || null;
        return null;
      }).filter(Boolean));

      $$('tbody td[data-col="' + key + '"]').forEach((td) => {
        const txt = key === 'status'
          ? (td.dataset.status || '').trim()
          : td.textContent.trim();
        if (txt) values.add(txt);
      });

      const sortTypeValues = (items) => {
        const order = ['SDAQ', 'IOBOX', 'MTI', 'NOX'];
        const rank = (val) => {
          const upper = String(val || '').toUpperCase();
          const idx = order.findIndex((prefix) => upper.startsWith(prefix));
          return idx === -1 ? order.length : idx;
        };
        return items.sort((a, b) => {
          const ra = rank(a);
          const rb = rank(b);
          if (ra !== rb) return ra - rb;
          return String(a).localeCompare(String(b));
        });
      };

      addItem('All', null);
      const sorted = Array.from(values).sort();
      const finalValues = key === 'type' ? sortTypeValues(sorted) : sorted;
      finalValues.forEach((v) => addItem(v, v));

      menu.appendChild(body);

      const rect = th.getBoundingClientRect();
      menu.style.minWidth = rect.width + 'px';
      menu.style.top = rect.bottom + 4 + window.scrollY + 'px';
      menu.style.left = rect.left + window.scrollX + 'px';

      document.body.appendChild(menu);
      openFilterMenu = menu;
    }

    const thead = $('thead');
    thead?.addEventListener('click', (e) => {
      const sortIcon = e.target.closest('.sort-icon');
      if (sortIcon) {
        e.preventDefault();
        e.stopPropagation();
        const sortKey = sortIcon.closest('.sort-icons')?.dataset.sortKey;
        const dir = sortIcon.dataset.dir;
        if (sortKey) setSortState(sortKey, dir);
        return;
      }
      const th = e.target.closest('th[data-filter-key]');
      if (!th) return;
      const key = th.getAttribute('data-filter-key');
      if (!key) return;
      e.stopPropagation();

      if (openFilterMenu && openFilterMenu.dataset.key === key) {
        closeFilterMenu();
      } else {
        buildFilterMenu(th, key);
      }
    });

    function markCalibrationCells() {
      if (!tbody || !tbody.children.length) return;

      const now = new Date();

      $$('tbody td[data-col="next-cal"]').forEach((td) => {
        td.classList.remove('calib-expired');

        const raw = (td.textContent || '').trim();
        if (!raw || raw === '—' || raw === '-') return;

        let dt = null;

        let m = raw.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
        if (m) {
          dt = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
        } else {
          m = raw.match(/^(\d{1,2})[./](\d{1,2})[./](\d{4})$/);
          if (m) {
            dt = new Date(Number(m[3]), Number(m[2]) - 1, Number(m[1]));
          }
        }

        if (!dt) return;

        if (dt.getTime() < now.getTime()) {
          td.classList.add('calib-expired');
        }
      });
    }

    /* =======================================================================
     * 6) LOAD TABLE DATA FROM BACKEND
     * ======================================================================= */

    async function fetchIsoChannels() {
      const json = channelsApi
        ? await channelsApi.fetchChannels()
        : await (async () => {
          const res = await fetch(API_ISO, {
            headers: { 'Accept': 'application/json' }
          });
          if (!res.ok) {
            throw new Error('HTTP ' + res.status + ' from ' + API_ISO);
          }
          return res.json();
        })();
      if (!json || json.ok === false) {
        throw new Error(json && json.error ? json.error : 'Invalid response');
      }
      return json.data || [];
    }

    function statusToDotClass(status) {
      const s = (status || '').toLowerCase();

      if (s === 'okay') return 'st-Okay';
      if (s === 'stall') return 'st-Stall';
      if (s === 'out of range') return 'st-Error';
      if (s === 'over range') return 'st-Error';
      if (s === 'no sensor') return 'st-Error';
      if (s === 'no_sensor') return 'st-Error';
      if (s === 'unclassified') return 'st-Error';
      if (s === 'open wire') return 'st-Error';
      if (s === 'short circuit') return 'st-Error';
      if (s === 'off-line' || s === 'offline') return 'st-Offline';
      if (s === 'disconnected') return 'st-Offline';

      return 'st-Unknown';
    }

    // Compute Next Calibration（YYYY-MM-DD）from cal_date + cal_period
    function computeNextCalibration(ch) {
      const calDate = ch.cal_date;
      const period = ch.cal_period;

      if (!calDate || !period) return '—';

      const m = String(calDate).match(/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})$/);
      if (!m) return '—';

      const year = parseInt(m[1], 10);
      const month = parseInt(m[2], 10) - 1;
      const day = parseInt(m[3], 10);
      const monthsToAdd = parseInt(period, 10);

      if (isNaN(monthsToAdd)) return '—';

      const targetMonth = month + monthsToAdd;
      const lastOfTargetMonth = new Date(Date.UTC(year, targetMonth + 1, 0));
      const safeDay = Math.min(day, lastOfTargetMonth.getUTCDate());
      const dt = new Date(Date.UTC(year, targetMonth, safeDay));
      if (isNaN(dt.getTime())) return '—';

      const yyyy = dt.getUTCFullYear();
      const mm = String(dt.getUTCMonth() + 1).padStart(2, '0');
      const dd = String(dt.getUTCDate()).padStart(2, '0');
      return `${yyyy}-${mm}-${dd}`;
    }

    function buildIsoRow(ch, index) {
      const tr = document.createElement('tr');
      tr.className = 'row';

      const rowIndex = index + 1;
      const type = ch.dev_type || ch.interface_type || '';
      const rawAnchor = (ch.anchor || '').toString();
      const displayAnchor = (ch.display_anchor || ch.anchor || '').toString().toUpperCase();
      const desc = ch.description || '';
      const min = ch.min ?? '—';
      const max = ch.max ?? '—';
      const isoName  = ch.iso_channel || '';
      const isoKey   = isoName.toString().toUpperCase();
      tr.dataset.iso = isoName;
      tr.dataset.type = type;
      tr.dataset.anchor = rawAnchor;
      tr.dataset.description = desc;
      tr.dataset.min = min;
      tr.dataset.max = max;

      const statusText = (() => {
        const raw = ch.status || 'Unknown';
        const lowered = raw.toLowerCase();
        return lowered === 'unlinked' || lowered === 'unlink' ? 'Unknown' : raw;
      })();
      const dotClass = statusToDotClass(statusText);
      const valueText = ch.meas != null && ch.meas !== '' ? ch.meas : '—';

      const nextCal = computeNextCalibration(ch);

      // 1 checkbox
      const tdCheck = document.createElement('td');
      const cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.setAttribute('aria-label', 'Select row ' + rowIndex);
      tdCheck.appendChild(cb);
      tr.appendChild(tdCheck);

      // 2 index
      const tdIndex = document.createElement('td');
      tdIndex.textContent = String(rowIndex);
      tr.appendChild(tdIndex);

      // 3 Color
      const tdColor = document.createElement('td');
      const dot = document.createElement('span');
      dot.className = 'dot ' + dotClass;
      tdColor.appendChild(dot);
      tr.appendChild(tdColor);

      // 4 Status
      const tdStatus = document.createElement('td');
      tdStatus.setAttribute('data-col', 'status');
      tdStatus.dataset.status = statusText;
      tdStatus.textContent = statusText;
      tr.appendChild(tdStatus);

      // 5 ISOChannel
      const tdIso = document.createElement('td');
      tdIso.textContent = isoName;
      tr.appendChild(tdIso);

      // 6 Type
      const tdType = document.createElement('td');
      tdIso.setAttribute('data-col', 'iso');
      tdType.setAttribute('data-col', 'type');
      tdType.textContent = type;
      tr.appendChild(tdType);

      // 7 Connection
      const tdConn = document.createElement('td');
      tdConn.setAttribute('data-col', 'anchor');
      tdConn.textContent = displayAnchor;
      tr.appendChild(tdConn);

      // 8 Value
      const tdVal = document.createElement('td');
      tdVal.setAttribute('data-col', 'value');
      tdVal.textContent = valueText;
      tr.appendChild(tdVal);

      // 9 History
      const tdHist = document.createElement('td');
      const history = measurementHistory.get(isoKey) || [];
      const spark = buildSparkline(history);
      if (spark) {
        tdHist.appendChild(spark);
      } else {
        tdHist.textContent = '—';
      }
      tr.appendChild(tdHist);

      // 10 Next Calibration
      const tdNext = document.createElement('td');
      tdNext.setAttribute('data-col', 'next-cal');
      tdNext.textContent = nextCal || '—';
      tr.appendChild(tdNext);

      // 11 Min
      const tdMin = document.createElement('td');
      tdMin.setAttribute('data-col', 'min');
      tdMin.textContent = min;
      tr.appendChild(tdMin);

      // 12 Max
      const tdMax = document.createElement('td');
      tdMax.setAttribute('data-col', 'max');
      tdMax.textContent = max;
      tr.appendChild(tdMax);

      // 13 Description
      const tdDesc = document.createElement('td');
      tdDesc.setAttribute('data-col', 'description');
      tdDesc.textContent = desc;
      tr.appendChild(tdDesc);

      return tr;
    }

    function renderIsoTable(channels, selectedIsoSet = new Set()) {
      if (!tbody) return;
      tbody.innerHTML = '';

      channels.forEach((ch, idx) => {
        const tr = buildIsoRow(ch, idx);
        const isoKey = (tr.dataset.iso || '').toString().toUpperCase();
        const cb = tr.querySelector('input[type="checkbox"]');
        if (cb && selectedIsoSet.has(isoKey)) {
          cb.checked = true;
        }
        tbody.appendChild(tr);
      });

      markCalibrationCells();
      applySort();
      updateRowVisibility();
    }

    function loadIsoTable(force = false) {
      if (document.hidden && !force) return;
      if (tableFetchInFlight) {
        if (force) pendingTableReload = true;
        return;
      }

      tableFetchInFlight = true;

      fetchIsoChannels()
        .then((channels) => {
          const signature = JSON.stringify(channels);
          const shouldRender = force || signature !== lastTableSignature;

          updateChannelCache(channels);
          lastTableSignature = signature;

          const seenIsoKeys = new Set();
          channels.forEach((ch) => {
            const isoKey = (ch.iso_channel || '').toString().toUpperCase();
            if (!isoKey) return;
            seenIsoKeys.add(isoKey);
            const meas = parseFloat(ch.meas);
            const hasMeas = ch.meas !== null && ch.meas !== '' && Number.isFinite(meas);
            pushMeasurement(isoKey, hasMeas ? meas : NaN);
          });

          measurementHistory.forEach((_, key) => {
            if (!seenIsoKeys.has(key)) {
              measurementHistory.delete(key);
            }
          });

          if (!shouldRender) return;

          const selectedIsoSet = new Set(
            selectedRows()
              .map((tr) => (tr.dataset.iso || tr.querySelector('td[data-col="iso"]')?.textContent || '').toString().trim())
              .filter(Boolean)
              .map((iso) => iso.toUpperCase())
          );

          renderIsoTable(channels, selectedIsoSet);
        })
        .catch((err) => {
          console.error('Failed to load ISO channels:', err);
        })
        .finally(() => {
          tableFetchInFlight = false;
          if (pendingTableReload) {
            pendingTableReload = false;
            loadIsoTable();
          }
        });
    }

    /* =======================================================================
     * 7) TABLE SELECTION
     * ======================================================================= */

    master?.addEventListener('change', () => {
      const checks = rowChecks();
      checks.forEach((c) => { c.checked = master.checked; });
      updateMasterCheckbox();
    });

    document.addEventListener('change', (e) => {
      const cb = e.target.closest('tbody input[type="checkbox"]');
      if (!cb) return;
      updateMasterCheckbox();
    });

    let lastClickedIndex = null;

    tbody?.addEventListener('click', (e) => {
      const tr = e.target.closest('tr.row');
      if (!tr) return;

      if (e.target.closest('input[type="checkbox"]')) {
        updateMasterCheckbox();
        lastClickedIndex = visibleRows().indexOf(tr);
        return;
      }

      if (e.target.closest('.more, a, button')) return;

      const rows = visibleRows();
      const idx = rows.indexOf(tr);
      const cb = tr.querySelector('input[type="checkbox"]');
      if (!cb) return;

      if (e.shiftKey && lastClickedIndex !== null && lastClickedIndex !== -1) {
        const [start, end] =
          idx > lastClickedIndex ? [lastClickedIndex, idx] : [idx, lastClickedIndex];
        for (let i = start; i <= end; i++) {
          const c = rows[i].querySelector('input[type="checkbox"]');
          if (c) c.checked = true;
        }
      } else {
        cb.checked = !cb.checked;
      }

      lastClickedIndex = idx;
      updateMasterCheckbox();
    });

    updateMasterCheckbox();

    /* =======================================================================
     * 8) CONTEXT MENU
     * ======================================================================= */

    function clearCtx() { if (ctx) ctx.innerHTML = ''; }

    function addCtxItem(label, action) {
      const div = document.createElement('div');
      div.className = 'item';
      div.dataset.action = action;
      div.textContent = label;
      ctx.appendChild(div);
    }

    function buildRowDataFromDom(tr) {
      if (!tr) return null;
      const readByDataCol = (key) => (tr.querySelector(`td[data-col="${key}"]`)?.textContent || '').trim();
      const iso = (tr.dataset.iso || readByDataCol('iso') || '').trim();
      if (!iso) return null;

      return {
        iso_channel: iso,
        interface_type: tr.dataset.type || readByDataCol('type'),
        anchor: tr.dataset.anchor || readByDataCol('anchor'),
        description: tr.dataset.description || readByDataCol('description'),
        min: tr.dataset.min || readByDataCol('min'),
        max: tr.dataset.max || readByDataCol('max'),
        unit: '',
      };
    }

    async function fetchLatestChannelByIso(iso) {
      const key = (iso || '').toString().trim();
      if (!key) return null;

      try {
        if (channelsApi?.fetchChannel) {
          const json = await channelsApi.fetchChannel(key);
          if (json && json.ok !== false && json.data) {
            return json.data;
          }
          return null;
        }

        const res = await fetch(`${API_ISO}?iso=${encodeURIComponent(key)}`, {
          cache: 'no-store',
          headers: { Accept: 'application/json' },
        });
        if (!res.ok) return null;
        const json = await res.json();
        if (json && json.ok !== false && json.data) {
          return json.data;
        }
      } catch (err) {
        console.warn('Failed to fetch latest channel by ISO:', key, err);
      }

      return null;
    }

    function buildCtxFor(count, row) {
      clearCtx();
      if (count === 1) {
        const status = row ? getCellText(row, 'status') : '';
        const type = (row?.dataset.type || getCellText(row, 'type') || '').toString().trim().toUpperCase();
        const iso = (row?.dataset.iso || row?.querySelector('td[data-col="iso"]')?.textContent || '').trim();
        const cached = findChannelByIso(iso);
        const devType = (cached?.dev_type || type || '').toString().trim().toUpperCase();
        const isSdaq = devType === 'SDAQ' || devType.startsWith('SDAQ');
        const isSdaqIU = devType === 'SDAQ-I' || devType === 'SDAQ-U';
        if (status === 'off-line' || status === 'offline') {
          addCtxItem('Replace', 'replace');
        }
        addCtxItem('Edit', 'edit');
        addCtxItem('Delete', 'delete');
        if (isSdaq) {
          if (isSdaqIU) addCtxItem('Scale', 'scale');
          addCtxItem('Calibration', 'calibration');
        }
        addCtxItem('Export', 'export');
      } else if (count >= 2) {
        addCtxItem('Delete', 'delete');
        addCtxItem('Export', 'export');
      }
    }

    function parseSdaqCalibrationContext(anchorText) {
      const raw = (anchorText || '').toString().trim();
      if (!raw) return null;

      let match = raw.match(/CAN\s*(\d+)\.ADDR:\s*(\d+)\.CH:\s*(\d+)/i);
      if (!match) {
        match = raw.match(/CAN\s*(\d+)\.ADDR:\s*(\d+)\.CH\s*(\d+)/i);
      }
      if (!match) {
        match = raw.match(/CAN\s*(\d+)\.(\d+)\.CH\s*(\d+)/i);
      }
      if (!match) return null;

      return {
        bus: `can${parseInt(match[1], 10)}`,
        addr: parseInt(match[2], 10),
        ch: parseInt(match[3], 10),
      };
    }

    function parseSerialFromAnchor(anchorText) {
      const raw = (anchorText || '').toString().trim();
      if (!raw) return '';
      const match = raw.match(/^(\d+)\.CH\d+$/i);
      return match ? match[1] : '';
    }

    function parseMeasNumber(text) {
      const raw = (text || '').toString().trim();
      if (!raw || raw === '—' || raw === '-') return null;
      const m = raw.match(/^-?\d+(?:\.\d+)?/);
      if (!m) return null;
      const n = Number(m[0]);
      return Number.isFinite(n) ? n : null;
    }

    function openSdaqScalePopup() {
      const tr = selectedRows()[0];
      const iso = (tr?.dataset.iso || tr?.querySelector('td[data-col="iso"]')?.textContent || '').trim();
      const cached = findChannelByIso(iso);
      const rawAnchor = (cached?.anchor || tr?.dataset.anchor || '').trim();
      const displayAnchor = (cached?.display_anchor
        || tr?.querySelector('td[data-col="anchor"]')?.textContent
        || rawAnchor
        || '').trim();

      const ctx = parseSdaqCalibrationContext(displayAnchor);
      if (!ctx) {
        alert(`Cannot parse SDAQ context from anchor: ${displayAnchor || '(empty)'}`);
        return;
      }

      const sn = parseSerialFromAnchor(rawAnchor) || parseSerialFromAnchor(displayAnchor);
      const measText = tr?.querySelector('td[data-col="value"]')?.textContent || '';
      const rawMeas = parseMeasNumber(measText);
      const devType = (cached?.dev_type || tr?.querySelector('td[data-col="type"]')?.textContent || '').trim();
      const devTypeUpper = devType.toUpperCase();
      if (devTypeUpper !== 'SDAQ-I' && devTypeUpper !== 'SDAQ-U') {
        alert(`Scale is available only for SDAQ-I / SDAQ-U. Current type: ${devType || '(unknown)'}`);
        return;
      }

      const params = new URLSearchParams({
        bus: ctx.bus,
        addr: String(ctx.addr),
        ch: String(ctx.ch),
        iso,
      });
      if (sn) params.set('sn', sn);
      if (devType) params.set('devType', devType);
      if (rawMeas !== null) params.set('raw', String(rawMeas));

      openCenteredPopup(`linker-table/sdaq_scale.html?${params.toString()}`,
        'sdaq_scale_popup', { width: 1200, height: 820 });
    }

    function showCtx(x, y) {
      if (!ctx) return;
      ctx.style.left = x + 'px';
      ctx.style.top = y + 'px';
      ctx.style.display = 'block';
    }

    function hideCtx() {
      if (!ctx) return;
      ctx.style.display = 'none';
    }

    function buildExportEntry(channel) {
      if (!channel) return null;
      const entry = {
        ISO_CHANNEL: channel.iso_channel,
        INTERFACE_TYPE: channel.interface_type || channel.dev_type || '',
        ANCHOR: channel.anchor || '',
        DESCRIPTION: channel.description || '',
        MIN: channel.min ?? '',
        MAX: channel.max ?? '',
      };

      if (channel.alarm_high_val !== undefined) {
        entry.ALARM_HIGH_VAL = channel.alarm_high_val;
      }
      if (channel.alarm_low_val !== undefined) {
        entry.ALARM_LOW_VAL = channel.alarm_low_val;
      }
      if (channel.alarm_high !== undefined) {
        entry.ALARM_HIGH = channel.alarm_high;
      }
      if (channel.alarm_low !== undefined) {
        entry.ALARM_LOW = channel.alarm_low;
      }

      const type = entry.INTERFACE_TYPE;
      if (type && type !== 'SDAQ') {
        entry.UNIT = channel.unit || '';
        if (channel.cal_date && channel.cal_period) {
          entry.CAL_DATE = channel.cal_date;
          entry.CAL_PERIOD = channel.cal_period;
        }
      }

      return entry;
    }

    function downloadJson(filename, data) {
      const payload = JSON.stringify(data, null, '\t');
      const blob = new Blob([payload], { type: 'application/json;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    }

    function exportSelectedChannels() {
      const rows = selectedRows();
      if (!rows.length) {
        alert('No channels selected for export.');
        return;
      }

      const entries = rows.map((row) => {
        const iso = (row.dataset.iso || row.querySelector('td[data-col="iso"]')?.textContent || '').trim();
        const cached = findChannelByIso(iso);
        if (cached) return buildExportEntry(cached);
        const fallback = buildRowDataFromDom(row);
        return fallback ? buildExportEntry(fallback) : null;
      }).filter(Boolean);

      if (!entries.length) {
        alert('No channel data available for export.');
        return;
      }

      const now = new Date();
      const pad = (val) => String(val).padStart(2, '0');
      const timestamp = [
        now.getFullYear(),
        pad(now.getMonth() + 1),
        pad(now.getDate())
      ].join('') + '_' + [pad(now.getHours()), pad(now.getMinutes()), pad(now.getSeconds())].join('');
      const filename = `LOG_ISOChannel_Linker_Export_Selection_${timestamp}.json`;
      downloadJson(filename, entries);
    }

    document.addEventListener('contextmenu', (e) => {
      const tr = e.target.closest('tr.row');
      if (!tr) return;

      const currentSelection = selectedRows();
      if (currentSelection.length === 0) {
        const cb = tr.querySelector('input[type="checkbox"]');
        if (cb) cb.checked = true;
      } else if (
        currentSelection.length >= 1 &&
        !tr.classList.contains('selected') &&
        !e.ctrlKey &&
        !e.metaKey
      ) {
        rowChecks().forEach((c) => (c.checked = false));
        const cb = tr.querySelector('input[type="checkbox"]');
        if (cb) cb.checked = true;
      }

      updateMasterCheckbox();

      const count = selectedRows().length;
      if (count < 1) return;

      e.preventDefault();
      buildCtxFor(count, selectedRows()[0]);
      showCtx(e.clientX, e.clientY);
    });

    document.addEventListener('click', (e) => {
      if (ctx && !ctx.contains(e.target)) hideCtx();
    });

    ctx?.addEventListener('click', async (e) => {
      const item = e.target.closest('.item');
      if (!item) return;

      const action = item.dataset.action;
      switch (action) {
        case 'edit':
          const trEdit = selectedRows()[0];
          const iso = (trEdit?.dataset.iso || trEdit?.querySelector('td[data-col="iso"]')?.textContent || '').trim();
          const latest = await fetchLatestChannelByIso(iso);
          const cached = findChannelByIso(iso);
          const rowData = latest || cached || buildRowDataFromDom(trEdit);
          if (!rowData) {
            alert('No channel data available for edit');
            break;
          }
          try {
            sessionStorage.setItem('edit_channel_payload', JSON.stringify(rowData));
          } catch (_) { }
          const win = openCenteredPopup('linker-table/edit_channel.html', 'edit_channel_popup', { width: 880, height: 820 });
          try { window.__EDIT_LINK_DATA = rowData; } catch (_) { }
          break;
        case 'replace':
          const trReplace = selectedRows()[0];
          const isoReplace = (trReplace?.dataset.iso || trReplace?.querySelector('td[data-col="iso"]')?.textContent || '').trim();
          const latestReplace = await fetchLatestChannelByIso(isoReplace);
          const cachedReplace = findChannelByIso(isoReplace);
          const rowDataReplace = latestReplace || cachedReplace || buildRowDataFromDom(trReplace);
          if (!rowDataReplace) {
            alert('No channel data available for replace');
            break;
          }
          try {
            sessionStorage.setItem('edit_channel_payload', JSON.stringify(rowDataReplace));
          } catch (_) { }
          openCenteredPopup('linker-table/edit_channel.html?mode=replace', 'replace_channel_popup', { width: 880, height: 820 });
          try { window.__EDIT_LINK_DATA = rowDataReplace; } catch (_) { }
          break;
        case 'scale':
          openSdaqScalePopup();
          break;
        case 'calibration':
          const trCal = selectedRows()[0];
          const isoCal = (trCal?.dataset.iso || trCal?.querySelector('td[data-col="iso"]')?.textContent || '').trim();
          const cachedCal = findChannelByIso(isoCal);
          const rawAnchorCal = (cachedCal?.anchor || trCal?.dataset.anchor || '').trim();
          const displayAnchorCal = (cachedCal?.display_anchor
            || trCal?.querySelector('td[data-col="anchor"]')?.textContent
            || rawAnchorCal
            || '').trim();

          const calCtx = parseSdaqCalibrationContext(displayAnchorCal);
          if (!calCtx) {
            alert(`Cannot parse SDAQ context from anchor: ${displayAnchorCal || '(empty)'}`);
            break;
          }

          const snCal = parseSerialFromAnchor(rawAnchorCal) || parseSerialFromAnchor(displayAnchorCal);

          const params = new URLSearchParams({
            bus: calCtx.bus,
            addr: String(calCtx.addr),
            ch: String(calCtx.ch),
            points: '8',
            iso: isoCal,
          });
          if (snCal) params.set('sn', snCal);

          openCenteredPopup(`linker-table/calibration.html?${params.toString()}`,
            'calibration_popup', { width: 2200, height: 1600 });
          break;
        case 'delete':
          deleteSelectedRows();
          break;
        case 'export':
          exportSelectedChannels();
          break;
        default:
          // TODO: replace placeholder action
          alert(`${action} (placeholder)`);
      }
      hideCtx();
    });

    /* =======================================================================
     * 9) POPUP OPENER
     * ======================================================================= */

    const popupRegistry = new Map();

    function openCenteredPopup(url, name, arg3, arg4) {
      let opts = {};
      if (typeof arg3 === 'object' && arg3 !== null) {
        opts = arg3;
      } else {
        const w = Number(arg3) || 760;
        const h = Number(arg4) || 820;
        opts = { width: w, height: h };
      }

      const w = Number(opts.width) || 760;
      const h = Number(opts.height) || 820;
      const resizable = opts.resizable !== false;
      const scrollbars = opts.scrollbars !== false;

      const existing = popupRegistry.get(name);
      if (existing && !existing.closed) {
        try {
          if (existing.location && url && existing.location.href !== url) {
            existing.location.href = url;
          }
          existing.focus();
          return existing;
        } catch (_) {
          try { existing.focus(); } catch (_) { }
          return existing;
        }
      }

      const dualLeft = (window.screenLeft !== undefined ? window.screenLeft : window.screenX) || 0;
      const dualTop = (window.screenTop !== undefined ? window.screenTop : window.screenY) || 0;
      const vw = window.innerWidth || document.documentElement.clientWidth || screen.width;
      const vh = window.innerHeight || document.documentElement.clientHeight || screen.height;
      const safeW = Math.max(320, Math.min(w, Math.floor(vw * 0.95)));
      const safeH = Math.max(320, Math.min(h, Math.floor(vh * 0.95)));
      const left = Math.max(0, Math.round((vw - safeW) / 2 + dualLeft));
      const top = Math.max(0, Math.round((vh - safeH) / 2 + dualTop));

      const features = [
        `width=${safeW}`, `height=${safeH}`,
        `left=${left}`, `top=${top}`,
        `resizable=${resizable ? 'yes' : 'no'}`,
        `scrollbars=${scrollbars ? 'yes' : 'no'}`,
        'toolbar=no', 'location=no', 'status=no', 'menubar=no'
      ].join(',');

      const win = window.open(url, name || 'popup', features);
      if (win) {
        popupRegistry.set(name, win);
        try { win.focus(); } catch (_) { }
      }
      return win;
    }

    document.addEventListener('click', (e) => {
      const a = e.target.closest('a[data-popup]');
      if (!a) return;

      if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

      e.preventDefault();

      const url = a.dataset.url || a.getAttribute('href') || '#';
      const name = a.dataset.name || a.id || 'popup';
      const w = parseInt(a.dataset.width || a.dataset.w || '760', 10);
      const h = parseInt(a.dataset.height || a.dataset.h || '820', 10);
      const res = a.dataset.resizable !== 'false';
      const scr = a.dataset.scrollbars !== 'false';

      openCenteredPopup(url, name, { width: w, height: h, resizable: res, scrollbars: scr });
    });

    /* =======================================================================
     * 10) TOOLBAR BUTTONS
     * ======================================================================= */

    addBtn?.addEventListener('click', (e) => {
      e.preventDefault();
      openCenteredPopup('tool-bar/add_channel.html', 'add_channel_popup', { width: 880, height: 820 });
    });

    const importInput = document.createElement('input');
    importInput.type = 'file';
    importInput.accept = '.json,application/json';
    importInput.style.display = 'none';
    document.body.appendChild(importInput);

    function mapImportEntry(entry) {
      if (!entry) return null;
      const iso = entry.ISO_CHANNEL;
      const type = entry.INTERFACE_TYPE;
      const anchor = entry.ANCHOR;
      if (!iso || !type || !anchor) return null;

      const payload = {
        iso_channel: iso,
        interface_type: type,
        anchor,
        description: entry.DESCRIPTION || '',
        min: entry.MIN ?? '',
        max: entry.MAX ?? '',
        unit: entry.UNIT || '',
        alarm_high_val: entry.ALARM_HIGH_VAL,
        alarm_low_val: entry.ALARM_LOW_VAL,
        alarm_high: entry.ALARM_HIGH,
        alarm_low: entry.ALARM_LOW,
        cal_date: entry.CAL_DATE,
        cal_period: entry.CAL_PERIOD,
      };

      if (payload.interface_type === 'SDAQ') {
        delete payload.unit;
        delete payload.cal_date;
        delete payload.cal_period;
      }

      return payload;
    }

    async function importChannelsFromFile(file) {
      if (!file) return;
      let data;
      try {
        const text = await file.text();
        data = JSON.parse(text);
      } catch (err) {
        alert('Invalid JSON file: ' + (err?.message || err));
        return;
      }

      if (!Array.isArray(data)) {
        alert('Import expects a JSON array of ISO channels.');
        return;
      }

      const payloads = data.map(mapImportEntry).filter(Boolean);
      if (!payloads.length) {
        alert('No valid ISO channels found in the JSON file.');
        return;
      }

      for (const payload of payloads) {
        try {
          if (channelsApi?.createChannel) {
            await channelsApi.createChannel(payload);
          } else {
            const res = await fetch(API_ISO, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify(payload),
            });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const json = await res.json();
            if (json && json.ok === false) {
              throw new Error(json.error || 'Import failed');
            }
          }
        } catch (err) {
          alert('Failed to import channel ' + payload.iso_channel + ': ' + (err?.message || err));
          return;
        }
      }

      loadIsoTable(true);
    }

    importInput.addEventListener('change', () => {
      const file = importInput.files?.[0];
      if (!file) return;
      importChannelsFromFile(file).finally(() => {
        importInput.value = '';
      });
    });

    importBtn?.addEventListener('click', () => {
      importInput.click();
    });

    /* =======================================================================
     * 11) CROSS-WINDOW EVENTS
     * ======================================================================= */

    window.addEventListener('message', (e) => {
      const data = e.data || {};
      if (data.type === 'channel-added' || data.type === 'channel-updated') {
        loadIsoTable(true);
      }
    });

    /* =======================================================================
     * 12) GLOBAL KEYS + INITIAL LOAD
     * ======================================================================= */

    setInterval(() => loadIsoTable(), AUTO_REFRESH_MS);

    loadIsoTable(true);

    document.addEventListener('keydown', (e) => {
      if (!canUseGlobalDelete(e)) return;

      if (e.key === 'Delete') {
        const anySelected = selectedRows().length > 0;
        if (anySelected) {
          e.preventDefault();
          deleteSelectedRows();
        }
        return;
      }

      // Add Channel shortcut: Ctrl/Cmd + Shift + A
      if ((e.ctrlKey || e.metaKey) && e.shiftKey && String(e.key).toLowerCase() === 'a') {
        e.preventDefault();
        openCenteredPopup('tool-bar/add_channel.html', 'add_channel_popup', { width: 880, height: 820 });
      }
    });

  });
})();
