/* ===========================================================================
 * Morfeas WEB – App bootstrap
 * ========================================================================== */

(function () {
  document.addEventListener('DOMContentLoaded', () => {

    /* =======================================================================
     * 0) CONSTANTS & LIGHT HELPERS
     * =======================================================================
     */

    /** Base path for linking back to original pages (unchanged) */
    const ORIG_BASE_PATH = window.ORIG_BASE_PATH || '../LOG_WEB_v2';

    /** Query helpers kept minimal to avoid dependencies */
    const $  = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    /* =======================================================================
     * 1) DOM REFERENCES
     * =======================================================================
     */

    // Menu elements
    const menuBtn  = $('#menuBtn');
    const dropdown = $('#mainMenu');

    // Ticker elements
    const ticker = $('.ticker');
    const track  = ticker ? ticker.querySelector('.track') : null;

    // Search box
    const searchInput = $('#searchInput');
    const searchBtn   = $('#searchBtn');

    // Table selection
    const master = $('#masterCheck');
    const tbody  = $('tbody');

    // Context menu
    const ctx = $('#ctx');

    // Toolbar buttons
    const addBtn    = $('#addBtn');
    const importBtn = $('#importBtn');

    /* =======================================================================
     * 2) GENERIC UTILITIES (SELECTION, POPUPS, ETC.)
     * =======================================================================
     */

    /** Return all visible row checkboxes (only visible rows) */
    const rowChecks = () =>
      $$('tbody input[type="checkbox"]').filter(
        (c) => c.closest('tr').style.display !== 'none'
      );

    /** Get selected row <tr> elements */
    const selectedRows = () =>
      rowChecks()
        .map((c) => c.closest('tr'))
        .filter((tr) => tr && tr.querySelector('input[type="checkbox"]').checked);

    /** Toggle .selected class for rows based on their checkbox state */
    function syncRowSelectedClass() {
      $$('tbody tr').forEach((tr) => {
        const cb = tr.querySelector('input[type="checkbox"]');
        tr.classList.toggle('selected', !!(cb && cb.checked));
      });
    }

    /** Update the “select all” master checkbox state and row highlighting */
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

    /* ───────── Delete helpers ───────── */
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

    // Remove selected rows from the DOM (MOCK)
    function deleteSelectedRows() {
      const rows = selectedRows();
      if (!rows.length) return;

      const ok = confirm(`Delete ${rows.length} selected row(s)?`);
      if (!ok) return;

      rows.forEach((tr) => tr.parentNode && tr.parentNode.removeChild(tr));
      updateMasterCheckbox();
      hideCtx && hideCtx();
    }

    /** Visible data rows (for shift-range selection) */
    function visibleRows() {
      if (!tbody) return [];
      return $$('.row', tbody).filter((r) => r.style.display !== 'none');
    }

    /* =======================================================================
     * 3) MENU (OPEN/CLOSE)
     * =======================================================================
     */

    /* ───────── Menu ───────── */
    const openMenu = () => {
      dropdown?.classList.add('open');
      menuBtn?.setAttribute('aria-expanded', 'true');
    };

    const closeMenu = () => {
      dropdown?.classList.remove('open');
      menuBtn?.setAttribute('aria-expanded', 'false');
    };

    menuBtn?.addEventListener('click', (e) => {
      e.stopPropagation();
      if (!dropdown) return;
      dropdown.classList.contains('open') ? closeMenu() : openMenu();
    });

    document.addEventListener('click', (e) => {
      if (dropdown && !dropdown.contains(e.target) && e.target !== menuBtn) closeMenu();
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closeMenu();
    });

    /* =======================================================================
     * 4) TICKER (MOCK DATA SCROLLER)
     * =======================================================================
     */

    /* ───────── Ticker ───────── */
    // MOCK Demo items (remove when wired to backend)
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

      // Show exactly one full item
      ticker.style.height = ITEM_H + 'px';

      // Ensure chip can contain the ticker line-height
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
     * 5) SEARCH (FILTER TABLE ROWS)
     * =======================================================================
     */

    /* ───────── Search ───────── */
    /* All <tr> rows under the <tbody> tag in the page.
       The entire text content of each row (including all <td> columns).
       The search is not case-insensitive.
       If the search box is empty, all rows are shown; otherwise, only rows containing the keyword are displayed. */
    function filterTable(query) {
      const q = (query || '').trim().toLowerCase();
      $$('tbody tr').forEach((tr) => {
        const hit = q === '' ? true : tr.textContent.toLowerCase().includes(q);
        tr.style.display = hit ? '' : 'none';
      });
      updateMasterCheckbox();
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

    /* =======================================================================
     * 6) TABLE SELECTION (CLICK/SHIFT-RANGE/MASTER)
     * =======================================================================
     */

    /* ───────── Selection helpers ───────── */
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

    // Click anywhere on a row to toggle its selection (with Shift for range)
    let lastClickedIndex = null;

    tbody?.addEventListener('click', (e) => {
      const tr = e.target.closest('tr.row');
      if (!tr) return;

      // If clicking directly on a checkbox, let default behavior then sync.
      if (e.target.closest('input[type="checkbox"]')) {
        updateMasterCheckbox();
        lastClickedIndex = visibleRows().indexOf(tr);
        return;
      }

      // Ignore clicks on ".more" menu trigger or interactive elements
      if (e.target.closest('.more, a, button')) return;

      const rows = visibleRows();
      const idx  = rows.indexOf(tr);
      const cb   = tr.querySelector('input[type="checkbox"]');
      if (!cb) return;

      if (e.shiftKey && lastClickedIndex !== null && lastClickedIndex !== -1) {
        // Range select: select all between last and current
        const [start, end] =
          idx > lastClickedIndex ? [lastClickedIndex, idx] : [idx, lastClickedIndex];
        for (let i = start; i <= end; i++) {
          const c = rows[i].querySelector('input[type="checkbox"]');
          if (c) c.checked = true;
        }
      } else {
        // Toggle this row
        cb.checked = !cb.checked;
      }

      lastClickedIndex = idx;
      updateMasterCheckbox();
    });

    // Initial sync
    updateMasterCheckbox();

    /* =======================================================================
     * 7) CONTEXT MENU (RIGHT CLICK)
     * =======================================================================
     */

    /* ───────── Context menu ───────── */
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

      // If nothing selected, select the current row.
      // If some selected and clicked row is outside selection (and not Ctrl/⌘),
      // switch to single selection on the clicked row.
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
      if (count < 1) return; // do nothing when no selection

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
          alert('Edit (placeholder)');
          break;
        case 'scale':
          alert('Scale (placeholder)');
          break;
        case 'calibration':
          alert('Calibration(placeholder)');
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
     * 8) REUSABLE POPUP OPENER (CENTERED; SINGLETON BY NAME)
     * =======================================================================
     */

    /* ───────── Reusable popup opener (centered window) ───────── */
    const popupRegistry = new Map(); // name -> Window

    /**
     * openCenteredPopup(url, name, {width,height,resizable,scrollbars})
     * openCenteredPopup(url, name, width, height)
     */
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
      const dualTop  = (window.screenTop  !== undefined ? window.screenTop  : window.screenY)  || 0;
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

    /* 事件代理：任何带 data-popup 的 <a> 都用弹窗打开（复用同名窗口） */
    document.addEventListener('click', (e) => {
      const a = e.target.closest('a[data-popup]');
      if (!a) return;

      // 只拦截左键
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
     * 9) TOOLBAR BUTTONS (ADD / IMPORT)
     * =======================================================================
     */

    /* ───────── Toolbar buttons ───────── */
    // “Add Channels” popup window
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
     * 10) GLOBAL KEY HANDLERS (DELETE = REMOVE SELECTED ROWS)
     * =======================================================================
     */

    // Pressing the Delete key removes selected rows
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
