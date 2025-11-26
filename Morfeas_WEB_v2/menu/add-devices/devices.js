/* ============================================================================
 * Add Devices (popup)
 * ----------------------------------------------------------------------------
 * - “Add…” reveals the inline form; Save appends a device to the table.
 * - Type rules:
 *     SDAQ / NOX  → Name & IP are disabled (greyed out).
 *     IO-BOX / MDAQ / MTI → CAN Interface is disabled.
 * - Table features: master checkbox, row click-to-toggle, Remove selected,
 *   Delete key to remove selected, Refresh re-renders (placeholder).
 * ========================================================================== */

(function () {
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

  // --------------------------------------------------------------------------
  // 1) TABLE ELEMENTS
  // --------------------------------------------------------------------------
  const devBody = $('#devBody');
  const mchk = $('#mchk');
  const refreshBtn = $('#refreshBtn');
  const removeBtn = $('#removeBtn');

  // --------------------------------------------------------------------------
  // 2) FORM ELEMENTS
  // --------------------------------------------------------------------------
  const propCard = $('#propCard');
  const addBtn = $('#addBtn');
  const cancelBtn = $('#cancelBtn');
  const saveBtn = $('#saveBtn');

  const devType = $('#devType');
  const canIf = $('#canIf');
  const devName = $('#devName');
  const devIp = $('#devIp');

  // --------------------------------------------------------------------------
  // 3) IN-MEMORY MODEL (mock)
  // --------------------------------------------------------------------------
  let devices = [];

  // --------------------------------------------------------------------------
  // 4) RENDERING
  // --------------------------------------------------------------------------
  function render() {
    devBody.innerHTML = '';
    devices.forEach((d, i) => {
      const tr = document.createElement('tr');
      tr.className = 'row';
      tr.dataset.index = i;
      tr.innerHTML = `
        <td><input type="checkbox" aria-label="Select row ${i + 1}" /></td>
        <td>${i + 1}</td>
        <td>${d.bus || '-'}</td>
        <td>${d.type}</td>
        <td>${d.ip || '-'}</td>
        <td><span class="dot st-${d.status || 'Okay'}"></span>${d.status || 'Okay'}</td>
      `;
      devBody.appendChild(tr);
    });
    syncState();
  }

  function syncState() {
    const rows = $$('#devBody tr');
    rows.forEach(r =>
      r.classList.toggle('selected', r.querySelector('input[type="checkbox"]').checked)
    );

    const checked = rows.filter(r => r.querySelector('input[type="checkbox"]').checked).length;
    removeBtn.disabled = checked === 0;

    const total = rows.length;
    mchk.checked = total > 0 && checked === total;
    mchk.indeterminate = checked > 0 && checked < total;
  }

  // --------------------------------------------------------------------------
  // 5) FORM RULES (enable/disable by type)
  // --------------------------------------------------------------------------
  function setDisabled(el, disabled) {
    el.disabled = disabled;
    el.style.background = disabled ? 'var(--bg-weak)' : '';
  }

  function applyTypeRules() {
    const t = devType.value;
    const sdaqLike = (t === 'SDAQ' || t === 'NOX');

    // SDAQ / NOX → disable Name & IP; others → disable CAN
    setDisabled(devName, sdaqLike);
    setDisabled(devIp, sdaqLike);
    setDisabled(canIf, !sdaqLike);

    if (sdaqLike) {
      devName.value = '';
      devIp.value = '';
    }
  }
  devType.addEventListener('change', applyTypeRules);

  // --------------------------------------------------------------------------
  // 6) FORM INTERACTIONS
  // --------------------------------------------------------------------------
  addBtn.addEventListener('click', () => {
    propCard.style.display = 'block';

    // Defaults
    devType.value = 'SDAQ';
    canIf.value = 'can0';
    devName.value = '';
    devIp.value = '';

    applyTypeRules();
    devType.focus();
    propCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  saveBtn.addEventListener('click', () => {
    const t = devType.value;
    const sdaqLike = (t === 'SDAQ' || t === 'NOX');

    // Simple validation for non-SDAQ/NOX
    if (!sdaqLike) {
      if (!devName.value.trim()) {
        alert('Please fill Device Name.');
        devName.focus(); return;
      }
      const ip = devIp.value.trim();
      if (!/^\d{1,3}(\.\d{1,3}){3}$/.test(ip)) {
        alert('Please fill a valid IPv4 Address.');
        devIp.focus(); return;
      }
    }

    const item = {
      type: t,
      bus: sdaqLike ? canIf.value : '-',
      ip: sdaqLike ? '' : devIp.value.trim(),
      status: 'Okay'
    };
    devices.push(item);
    render();

    // Collapse form
    propCard.style.display = 'none';
    devName.value = '';
    devIp.value = '';
  });

  cancelBtn.addEventListener('click', () => {
    propCard.style.display = 'none';
  });

  // --------------------------------------------------------------------------
  // 7) TABLE INTERACTIONS
  // --------------------------------------------------------------------------
  mchk.addEventListener('change', () => {
    $$('#devBody input[type="checkbox"]').forEach(cb => (cb.checked = mchk.checked));
    syncState();
  });

  devBody.addEventListener('click', (e) => {
    const tr = e.target.closest('tr');
    if (!tr) return;
    if (!e.target.closest('input[type="checkbox"]')) {
      const cb = tr.querySelector('input[type="checkbox"]');
      cb.checked = !cb.checked;
    }
    syncState();
  });

  removeBtn.addEventListener('click', () => {
    const rows = $$('#devBody tr').filter(r => r.querySelector('input[type="checkbox"]').checked);
    const idxs = rows.map(r => parseInt(r.dataset.index, 10)).sort((a, b) => b - a);
    idxs.forEach(i => devices.splice(i, 1));
    render();
  });

  // Delete key: remove selected rows (ignores focused inputs)
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Delete') return;

    const el = document.activeElement;
    const isFormField =
      el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT' || el.isContentEditable);
    if (isFormField) return;

    const rows = $$('#devBody tr').filter(r => r.querySelector('input[type="checkbox"]').checked);
    if (!rows.length) return;

    if (!confirm(`Delete ${rows.length} selected row(s)?`)) return;

    const idxs = rows.map(r => parseInt(r.dataset.index, 10)).sort((a, b) => b - a);
    idxs.forEach(i => devices.splice(i, 1));
    render();
  });

  // --------------------------------------------------------------------------
  // 8) REFRESH (placeholder)
  // --------------------------------------------------------------------------
  refreshBtn.addEventListener('click', () => {
    render();
  });

  // Initial paint
  render();
})();
