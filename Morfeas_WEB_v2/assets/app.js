/* ===========================================================================
 * Morfeas WEB – App bootstrap
 * ========================================================================== */

(function () {
  document.addEventListener('DOMContentLoaded', () => {

    /* =======================================================================
     * 0) CONSTANTS & LIGHT HELPERS
     * ======================================================================= */

    const ORIG_BASE_PATH = window.ORIG_BASE_PATH || '../LOG_WEB_v2';

    const $  = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    /* =======================================================================
     * 1) DOM REFERENCES
     * ======================================================================= */

    const menuBtn  = $('#menuBtn');
    const dropdown = $('#mainMenu');

    const ticker = $('.ticker');
    const track  = ticker ? ticker.querySelector('.track') : null;

    const searchInput = $('#searchInput');
    const searchBtn   = $('#searchBtn');

    const master = $('#masterCheck');
    const tbody  = $('tbody');

    const API_ISO = '/backend/api_iso.php';

    const ctx = $('#ctx');

    const addBtn    = $('#addBtn');
    const importBtn = $('#importBtn');

    let currentSearch = '';
    const columnFilters = {
      status: null,
      type: null
    };
    let openFilterMenu = null;

    /* =======================================================================
     * 2) GENERIC UTILITIES (SELECTION, POPUPS, ETC.)
     * ======================================================================= */

    const rowChecks = () =>
      $$('tbody input[type="checkbox"]').filter(
        (c) => c.closest('tr').style.display !== 'none'
      );

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

    function deleteSelectedRows() {
      const rows = selectedRows();
      if (!rows.length) return;

      const ok = confirm(`Delete ${rows.length} selected row(s)?`);
      if (!ok) return;

      rows.forEach((tr) => tr.parentNode && tr.parentNode.removeChild(tr));
      updateMasterCheckbox();
      hideCtx && hideCtx();
    }

    function visibleRows() {
      if (!tbody) return [];
      return $$('.row', tbody).filter((r) => r.style.display !== 'none');
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
     * 4) TICKER (MOCK DATA SCROLLER)
     * ======================================================================= */

    if (track && track.children.length === 0) {
      [
        'MOCK DATA',
        'CPU_temp\t121.4°F',
        'CPU_Util\t0.25%',
        'RAM_Util\t1.79%',
        'Disk_Util\t23.92%',
        'Up_time\t1 day 2:12:27'
      ].forEach((t) => {
        const div = document.createElement('div');
        div.className = 'item';
        div.textContent = t;
        track.appendChild(div);
      });
    }

    if (ticker && track && track.children.length) {
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
      return cell ? cell.textContent.trim().toLowerCase() : '';
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
          const st = getCellText(tr, 'status');
          if (st !== columnFilters.status.toLowerCase()) visible = false;
        }

        if (visible && columnFilters.type) {
          const tp = getCellText(tr, 'type');
          if (tp !== columnFilters.type.toLowerCase()) visible = false;
        }

        tr.style.display = visible ? '' : 'none';
      });

      updateMasterCheckbox();
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

      const values = new Set();
      $$('tbody td[data-col="' + key + '"]').forEach((td) => {
        const txt = td.textContent.trim();
        if (txt) values.add(txt);
      });

      addItem('All', null);
      Array.from(values).sort().forEach((v) => addItem(v, v));

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
          } else {
            const tmp = new Date(raw);
            if (!isNaN(tmp.getTime())) dt = tmp;
          }
        }

        if (!dt) return;

        if (dt.getTime() < now.getTime()) {
          td.classList.add('calib-expired');
        }
      });
    }

    markCalibrationCells();

    /* =======================================================================
     * 6) LOAD TABLE DATA FROM BACKEND
     * ======================================================================= */

    async function fetchIsoChannels() {
      const res = await fetch(API_ISO, {
        headers: { 'Accept': 'application/json' }
      });
      if (!res.ok) {
        throw new Error('HTTP ' + res.status + ' from ' + API_ISO);
      }
      const json = await res.json();
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
      if (s === 'unclassified') return 'st-Error';
      if (s === 'open wire') return 'st-Error';
      if (s === 'short circuit') return 'st-Error';
      if (s === 'unlinked' || s === 'unlink') return 'st-Unlinked';
      if (s === 'off-line' || s === 'offline') return 'st-Offline';
      if (s === 'disconnected') return 'st-Offline';

      return 'st-Unknown';
    }

    // 根据 cal_date + cal_period 算 Next Calibration（YYYY-MM-DD）
    function computeNextCalibration(ch) {
      const calDate = ch.cal_date;
      const period  = ch.cal_period;

      if (!calDate || !period) return '—';

      const m = String(calDate).match(/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})$/);
      if (!m) return '—';

      const year  = parseInt(m[1], 10);
      const month = parseInt(m[2], 10) - 1;
      const day   = parseInt(m[3], 10);
      const monthsToAdd = parseInt(period, 10);

      if (isNaN(monthsToAdd)) return '—';

      const dt = new Date(year, month + monthsToAdd, day);
      if (isNaN(dt.getTime())) return '—';

      const yyyy = dt.getFullYear();
      const mm   = String(dt.getMonth() + 1).padStart(2, '0');
      const dd   = String(dt.getDate()).padStart(2, '0');
      return `${yyyy}-${mm}-${dd}`;
    }

    function buildIsoRow(ch, index) {
      const tr = document.createElement('tr');
      tr.className = 'row';

      const rowIndex = index + 1;
      const isoName  = ch.iso_channel || '';
      const type     = ch.dev_type || ch.interface_type || '';
      const anchor   = ch.anchor || '';
      const desc     = ch.description || '';
      const min      = ch.min ?? '—';
      const max      = ch.max ?? '—';

      const statusText = ch.status || 'Unknown';
      const dotClass   = statusToDotClass(statusText);
      const valueText  = ch.meas != null && ch.meas !== '' ? ch.meas : '—';

      const nextCal    = computeNextCalibration(ch);

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
      tdStatus.textContent = statusText;
      tr.appendChild(tdStatus);

      // 5 ISOChannel
      const tdIso = document.createElement('td');
      tdIso.textContent = isoName;
      tr.appendChild(tdIso);

      // 6 Type
      const tdType = document.createElement('td');
      tdType.setAttribute('data-col', 'type');
      tdType.textContent = type;
      tr.appendChild(tdType);

      // 7 Connection
      const tdConn = document.createElement('td');
      tdConn.textContent = anchor;
      tr.appendChild(tdConn);

      // 8 Value
      const tdVal = document.createElement('td');
      tdVal.textContent = valueText;
      tr.appendChild(tdVal);

      // 9 History
      const tdHist = document.createElement('td');
      tdHist.textContent = '(history)';
      tr.appendChild(tdHist);

      // 10 Next Calibration
      const tdNext = document.createElement('td');
      tdNext.setAttribute('data-col', 'next-cal');
      tdNext.textContent = nextCal || '—';
      tr.appendChild(tdNext);

      // 11 Min
      const tdMin = document.createElement('td');
      tdMin.textContent = min;
      tr.appendChild(tdMin);

      // 12 Max
      const tdMax = document.createElement('td');
      tdMax.textContent = max;
      tr.appendChild(tdMax);

      // 13 Description
      const tdDesc = document.createElement('td');
      tdDesc.textContent = desc;
      tr.appendChild(tdDesc);

      return tr;
    }

    function renderIsoTable(channels) {
      if (!tbody) return;
      tbody.innerHTML = '';

      channels.forEach((ch, idx) => {
        const tr = buildIsoRow(ch, idx);
        tbody.appendChild(tr);
      });

      markCalibrationCells();
      updateRowVisibility();
    }

    function loadIsoTable() {
      fetchIsoChannels()
        .then((channels) => {
          renderIsoTable(channels);
        })
        .catch((err) => {
          console.error('Failed to load ISO channels:', err);
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
      const idx  = rows.indexOf(tr);
      const cb   = tr.querySelector('input[type="checkbox"]');
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

    function buildCtxFor(count) {
      clearCtx();
      if (count === 1) {
        addCtxItem('Edit', 'edit');
        addCtxItem('Delete', 'delete');
        addCtxItem('Scale', 'scale');
        addCtxItem('Calibration', 'calibration');
        addCtxItem('Export', 'export');
      } else if (count >= 2) {
        addCtxItem('Delete', 'delete');
        addCtxItem('Export', 'export');
      }
    }

    function showCtx(x, y) {
      if (!ctx) return;
      ctx.style.left = x + 'px';
      ctx.style.top  = y + 'px';
      ctx.style.display = 'block';
    }

    function hideCtx() {
      if (!ctx) return;
      ctx.style.display = 'none';
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
      buildCtxFor(count);
      showCtx(e.clientX, e.clientY);
    });

    document.addEventListener('click', (e) => {
      if (ctx && !ctx.contains(e.target)) hideCtx();
    });

    ctx?.addEventListener('click', (e) => {
      const item = e.target.closest('.item');
      if (!item) return;

      const action = item.dataset.action;
      switch (action) {
        case 'edit':
          const rowData = {/* TODO: build from selected row when你需要 */};
          const win = openCenteredPopup('linker-table/edit_channel.html', 'edit_channel_popup', {width: 880, height: 820});
          if (win) { win.name = JSON.stringify(rowData); }
          break;
        case 'scale':
          alert('Scale (placeholder)');
          break;
        case 'calibration':
          const tr = selectedRows()[0];
          const conn = tr?.querySelector('td:nth-child(7)')?.textContent || '';
          const match = conn.match(/CH:(\d+)/i);
          const ch = match ? match[1] : 1;
          openCenteredPopup('linker-table/calibration.html?ch=' + ch + '&unit=%C&points=8',
                            'calibration_popup', { width: 1280, height: 1080 });
          break;
        case 'delete':
          deleteSelectedRows();
          break;
        case 'export':
          alert('Export JSON (placeholder)');
          break;
        default:
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
          try { existing.focus(); } catch (_) {}
          return existing;
        }
      }

      const dualLeft = (window.screenLeft !== undefined ? window.screenLeft : window.screenX) || 0;
      const dualTop  = (window.screenTop  !== undefined ? window.screenTop : window.screenY)  || 0;
      const vw = window.innerWidth  || document.documentElement.clientWidth  || screen.width;
      const vh = window.innerHeight || document.documentElement.clientHeight || screen.height;
      const left = Math.max(0, Math.round((vw - w) / 2 + dualLeft));
      const top  = Math.max(0, Math.round((vh - h) / 2 + dualTop));

      const features = [
        `width=${w}`, `height=${h}`,
        `left=${left}`, `top=${top}`,
        `resizable=${resizable ? 'yes' : 'no'}`,
        `scrollbars=${scrollbars ? 'yes' : 'no'}`,
        'toolbar=no', 'location=no', 'status=no', 'menubar=no'
      ].join(',');

      const win = window.open(url, name || 'popup', features);
      if (win) {
        popupRegistry.set(name, win);
        try { win.focus(); } catch (_) {}
      }
      return win;
    }

    document.addEventListener('click', (e) => {
      const a = e.target.closest('a[data-popup]');
      if (!a) return;

      if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

      e.preventDefault();

      const url  = a.dataset.url || a.getAttribute('href') || '#';
      const name = a.dataset.name || a.id || 'popup';
      const w    = parseInt(a.dataset.width  || a.dataset.w || '760', 10);
      const h    = parseInt(a.dataset.height || a.dataset.h || '820', 10);
      const res  = a.dataset.resizable !== 'false';
      const scr  = a.dataset.scrollbars !== 'false';

      openCenteredPopup(url, name, { width: w, height: h, resizable: res, scrollbars: scr });
    });

    /* =======================================================================
     * 10) TOOLBAR BUTTONS
     * ======================================================================= */

    addBtn?.addEventListener('click', (e) => {
      e.preventDefault();
      openCenteredPopup('tool-bar/add_channel.html', 'add_channel_popup', { width: 880, height: 820 });
    });

    importBtn?.addEventListener('click', () => {
      window.open(
        `${ORIG_BASE_PATH}/tool-bar/import_channel.html`,
        '_blank'
      );
    });

    /* =======================================================================
     * 11) GLOBAL KEYS + INITIAL LOAD
     * ======================================================================= */

    loadIsoTable();

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Delete' && canUseGlobalDelete(e)) {
        const anySelected = selectedRows().length > 0;
        if (anySelected) {
          e.preventDefault();
          deleteSelectedRows();
        }
      }
    });

  });
})();
