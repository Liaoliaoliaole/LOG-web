/* =============================================================================
 * Network Configuration (popup)
 * -----------------------------------------------------------------------------
 * - Preset buttons LOG1…LOG10 (mock values)
 * - Static/DHCP toggle (disables IP/GW/DNS in DHCP)
 * - Small UX helpers: numeric guard, auto-advance across octets
 * -  - TODO: Actions are placeholders (no backend calls)
 * ========================================================================== */

(function () {
  document.addEventListener('DOMContentLoaded', () => {
    /* =======================================================================
     * 0) LIGHT HELPERS
     * =======================================================================
     */
    const $ = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    /* =======================================================================
     * 1) DOM REFERENCES
     * =======================================================================
     */
    const modeSel = $('#modeSel');
    const hostInp = $('#host');
    const maskInp = $('#mask');

    // Octet groups
    const ipOcts = $$('.ip');
    const gwOcts = $$('.gw');
    const dnsOcts = $$('.dns');

    // All fields toggled by DHCP/Static
    const staticEditables = [...ipOcts, ...gwOcts, ...dnsOcts, maskInp];

    const loadLastBtn = $('#loadLastBtn');
    const commitBtn = $('#commitBtn');
    const closeBtn = $('#closeBtn');

    /* =======================================================================
     * 2) MODE TOGGLE (STATIC vs DHCP)
     * =======================================================================
     */
    function setStaticEnabled(on) {
      staticEditables.forEach(el => {
        el.disabled = !on;
        el.style.background = on ? '' : 'var(--bg-weak)';
      });
    }
    // Initial state: Static
    setStaticEnabled(true);

    modeSel.addEventListener('change', () => {
      const isStatic = modeSel.value === 'Static';
      setStaticEnabled(isStatic);
    });

    /* =======================================================================
     * 3) PRESETS (MOCK DATA)
     * =======================================================================
     */
    const PRESETS = {
      LOG1: { host: 'LOG1', ip: [192, 168, 1, 10], mask: 24, gw: [192, 168, 1, 1], dns: [8, 8, 8, 8] },
      LOG2: { host: 'LOG2', ip: [192, 168, 2, 10], mask: 24, gw: [192, 168, 2, 1], dns: [1, 1, 1, 1] },
      LOG3: { host: 'LOG3', ip: [192, 168, 3, 10], mask: 24, gw: [192, 168, 3, 1], dns: [8, 8, 4, 4] },
      LOG4: { host: 'LOG4', ip: [10, 0, 4, 10], mask: 24, gw: [10, 0, 4, 1], dns: [9, 9, 9, 9] },
      LOG5: { host: 'LOG5', ip: [10, 0, 5, 10], mask: 24, gw: [10, 0, 5, 1], dns: [8, 8, 8, 8] },
      LOG6: { host: 'LOG6', ip: [10, 0, 6, 10], mask: 24, gw: [10, 0, 6, 1], dns: [1, 1, 1, 1] },
      LOG7: { host: 'LOG7', ip: [172, 16, 7, 10], mask: 24, gw: [172, 16, 7, 1], dns: [8, 8, 4, 4] },
      LOG8: { host: 'LOG8', ip: [172, 16, 8, 10], mask: 24, gw: [172, 16, 8, 1], dns: [9, 9, 9, 9] },
      LOG9: { host: 'LOG9', ip: [192, 168, 9, 10], mask: 24, gw: [192, 168, 9, 1], dns: [1, 0, 0, 1] },
      LOG10: { host: 'LOG10', ip: [192, 168, 10, 10], mask: 24, gw: [192, 168, 10, 1], dns: [8, 8, 8, 8] }
    };

    function fillGroup(nodes, values) {
      for (let i = 0; i < nodes.length && i < values.length; i++) nodes[i].value = values[i];
    }

    function setActivePresetBtn(key) {
      $$('[data-preset]').forEach(btn =>
        btn.classList.toggle('active', btn.dataset.preset === key)
      );
    }

    function applyPreset(preset, key) {
      modeSel.value = 'Static';
      setStaticEnabled(true);

      hostInp.value = preset.host || '';
      maskInp.value = preset.mask ?? '';

      fillGroup(ipOcts, preset.ip || []);
      fillGroup(gwOcts, preset.gw || []);
      fillGroup(dnsOcts, preset.dns || []);

      setActivePresetBtn(key);
      try { localStorage.setItem('netconf:lastPreset', key); } catch (_) { }
    }

    // Wire preset buttons
    $$('[data-preset]').forEach(btn => {
      btn.addEventListener('click', () => {
        const key = btn.dataset.preset;
        if (PRESETS[key]) applyPreset(PRESETS[key], key);
      });
    });

    // Restore last-used preset highlight (optional nicety)
    try {
      const last = localStorage.getItem('netconf:lastPreset');
      if (last) setActivePresetBtn(last);
    } catch (_) { }

    /* =======================================================================
     * 4) UX HELPERS (NUMERIC GUARD / AUTO-ADVANCE)
     * =======================================================================
     */
    function clampOctet(el) {
      const n = Number(el.value);
      if (Number.isFinite(n)) el.value = Math.max(0, Math.min(255, n));
      else el.value = '';
    }

    function autoAdvance(e, group) {
      const el = e.target;
      if (el.type !== 'number') return;
      if (el.value.length >= 3) {
        const list = Array.from(group);
        const i = list.indexOf(el);
        const next = list[i + 1];
        if (next) next.focus();
      }
    }

    // Guard ranges and advance inside each octet group
    [...ipOcts, ...gwOcts, ...dnsOcts].forEach(el => {
      el.addEventListener('blur', () => clampOctet(el));
      el.addEventListener('input', (e) => {
        clampOctet(el);
        if (ipOcts.includes(el)) autoAdvance(e, ipOcts);
        if (gwOcts.includes(el)) autoAdvance(e, gwOcts);
        if (dnsOcts.includes(el)) autoAdvance(e, dnsOcts);
      });
      // Prevent accidental wheel increment when focused
      el.addEventListener('wheel', (e) => e.preventDefault(), { passive: false });
    });

    // CIDR guard
    maskInp.addEventListener('input', () => {
      const n = Number(maskInp.value);
      if (Number.isFinite(n)) maskInp.value = Math.max(0, Math.min(32, n));
      else maskInp.value = '';
    });

    /* =======================================================================
     * 5) ACTIONS (PLACEHOLDERS)
     * =======================================================================
     */
    loadLastBtn.addEventListener('click', () => {
      alert('Load Last (placeholder).');
    });

    commitBtn.addEventListener('click', () => {
      // In real integration, serialize values and POST to backend.
      alert('Set (placeholder). Values are not submitted in the static prototype.');
    });

    closeBtn.addEventListener('click', () => window.close());
  });
})();
