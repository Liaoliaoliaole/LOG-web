/* =============================================================================
 * Link Creator – lightweight logic with theme-aligned dropdown highlight
 * Notes:
 * - Range only applies to SDAQ.
 * - When range >= 2 (multi), disable Min/Max/Alarm/Unit fields.
 * - Postfix dropdown stays tiny (48px) and contains "N/A" + short list.
 * - This is a static mock; wire save() to your backend later.
 * ========================================================================== */

(() => {
    // ---------- DOM helpers ----------
    const $  = (s, r = document) => r.querySelector(s);
  
    // ---------- Fields ----------
    const typeSel      = $('#type');
    const pathInput    = $('#path');
    const isoInput     = $('#iso');
    const postfixSel   = $('#postfix');
    const descInput    = $('#desc');
  
    const rangeLabel   = $('#rangeLabel');
    const rangeRow     = $('#rangeRow');
    const rangeInput   = $('#range');
  
    const minInput     = $('#min');
    const maxInput     = $('#max');
    const alarmLowVal  = $('#alarmLowVal');
    const alarmLowChk  = $('#alarmLow');
    const alarmHighVal = $('#alarmHighVal');
    const alarmHighChk = $('#alarmHigh');
    const unitInput    = $('#unit');
  
    const statusBar    = $('#status');
    const btnSave      = $('#btnSave');
    const btnCancel    = $('#btnCancel');
    const btnSearch    = $('#btnSearch');
  
    // ---------- Utilities ----------
    const setDisabled = (el, on) => {
      el.disabled = !!on;
      el.style.background = on ? 'var(--bg-weak)' : '';
    };
  
    // Enable/disable the block that should be off for multi-range (>1)
    function toggleMultiLock(isMulti) {
      [
        minInput,
        maxInput,
        alarmLowVal, alarmLowChk,
        alarmHighVal, alarmHighChk,
        unitInput
      ].forEach(el => setDisabled(el, isMulti));
    }
  
    // Range is only visible for SDAQ. For others, force 1 and hide.
    function applyTypeRules() {
      const t = typeSel.value;
      const isSdaq = (t === 'SDAQ');
  
      rangeLabel.classList.toggle('hidden', !isSdaq);
      rangeRow.classList.toggle('hidden', !isSdaq);
  
      if (!isSdaq) {
        rangeInput.value = 1;
        toggleMultiLock(false);
      } else {
        // SDAQ: re-apply multi lock based on current value
        const v = Math.max(1, parseInt(rangeInput.value || '1', 10));
        toggleMultiLock(v >= 2);
      }
  
      statusBar.textContent = t === '-' ? 'Select Type' : `Type selected: ${t}`;
    }
  
    function onRangeChange() {
      const n = Math.max(1, parseInt(rangeInput.value || '1', 10));
      rangeInput.value = n;
      toggleMultiLock(n >= 2);
    }
  
    // ---------- Postfix (tiny dropdown; keep content short) ----------
    function fillPostfix() {
      postfixSel.innerHTML = '';
      const add = (v, txt = v) => {
        const o = document.createElement('option');
        o.value = v; o.textContent = txt;
        postfixSel.appendChild(o);
      };
      add('N/A', 'N/A');  // keep tiny
      // Add a few short postfix samples (keep it short to preserve tiny width)
      add('A'); add('B'); add('C'); add('D'); add('E');
      postfixSel.value = 'N/A';
    }
  
    // ---------- Events ----------
    typeSel.addEventListener('change', applyTypeRules);
    rangeInput.addEventListener('change', onRangeChange);
    rangeInput.addEventListener('input',  onRangeChange);
  
    btnSearch.addEventListener('click', (e) => {
      e.preventDefault();
      alert('Search is a placeholder in this mock.');
    });
  
    btnSave.addEventListener('click', (e) => {
      e.preventDefault();
      // Collect payload (mock)
      const payload = {
        type: typeSel.value,
        path: pathInput.value.trim(),
        iso : isoInput.value.trim(),
        postfix: postfixSel.value,
        desc: descInput.value.trim(),
        range: parseInt(rangeInput.value || '1', 10),
        min: minInput.value,
        max: maxInput.value,
        alarmLow:  alarmLowChk.checked  ? alarmLowVal.value  : null,
        alarmHigh: alarmHighChk.checked ? alarmHighVal.value : null,
        unit: unitInput.value.trim()
      };
      console.log('SAVE payload (mock):', payload);
      alert('Saved (mock). Check console for payload.');
    });
  
    btnCancel.addEventListener('click', (e) => {
      e.preventDefault();
      window.close();
    });
  
    // ---------- Init ----------
    fillPostfix();
    applyTypeRules();
    onRangeChange();
  })();
  