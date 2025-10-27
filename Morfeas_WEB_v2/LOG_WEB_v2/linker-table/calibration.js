// Channel Calibration popup (static front-end; plug your backend where TODOs are)
(() => {
    const $  = (s, r=document) => r.querySelector(s);
    const $$ = (s, r=document) => Array.from(r.querySelectorAll(s));
  
    // -------- parameters --------
    const params     = new URLSearchParams(location.search);
    const maxPoints  = Math.max(1, parseInt(params.get('points') || '8', 10));
    const preCh      = Math.max(1, parseInt(params.get('ch') || '1', 10));
    const preUnit    = params.get('unit') || '';
  
    // -------- DOM refs --------
    const chSel      = $('#chSel');
    const unitBox    = $('#unitBox');
    const usedInput  = $('#usedPoints');
    const tableBody  = $('#calTable tbody');
    const statusEl   = $('#status');
  
    // Build channel selector
    for (let i = 1; i <= 64; i++) {
      const opt = document.createElement('option');
      opt.value = String(i);
      opt.textContent = `CH ${i}`;
      chSel.appendChild(opt);
    }
    chSel.value  = String(preCh);
    unitBox.value = preUnit;
  
    // Build table rows
    const rows = [];
    function buildRows() {
      tableBody.innerHTML = '';
      rows.length = 0;
      for (let i = 0; i < maxPoints; i++) {
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td><b>${i}</b></td>
          <td><input type="number" step="any" class="input input--w110" data-k="measure"></td>
          <td><input type="number" step="any" class="input input--w110" data-k="reference"></td>
          <td><input type="number" step="any" class="input input--w110" data-k="offset"></td>
          <td><input type="number" step="any" class="input input--w110" data-k="gain"></td>
          <td><input type="number" step="any" class="input input--w110" data-k="c2"></td>
          <td><input type="number" step="any" class="input input--w110" data-k="c3"></td>
          <td style="text-align:center">
            <button class="btn small" data-act="calc">Calc</button>
            <button class="btn small" data-act="zero">Zero</button>
          </td>`;
        tableBody.appendChild(tr);
        rows.push(tr);
      }
    }
    buildRows();
  
    // Enable/disable based on "Used Points"
    function applyUsed() {
      const n = Math.max(1, Math.min(maxPoints, parseInt(usedInput.value || '1', 10)));
      rows.forEach((tr, idx) => {
        const active = idx < n;
        tr.classList.toggle('row-disabled', !active);
        $$('input', tr).forEach(inp => inp.disabled = !active);
      });
    }
    usedInput.addEventListener('input', applyUsed);
    usedInput.value = String(Math.min(2, maxPoints));
    applyUsed();
  
    // Row helpers
    function getRowVals(tr) {
      const g = k => parseFloat(tr.querySelector(`input[data-k="${k}"]`).value);
      return {
        measure:  g('measure'),
        reference:g('reference'),
        offset:   g('offset'),
        gain:     g('gain'),
        c2:       g('c2'),
        c3:       g('c3')
      };
    }
    function setRowVals(tr, patch) {
      Object.entries(patch).forEach(([k, v]) => {
        const el = tr.querySelector(`input[data-k="${k}"]`);
        if (el) el.value = (v ?? '').toString();
      });
    }
  
    // 2-point fit: reference = gain * measure + offset
    function fit2pt(p0, p1) {
      const x1 = p0.measure, y1 = p0.reference;
      const x2 = p1.measure, y2 = p1.reference;
      if (![x1,x2,y1,y2].every(v => Number.isFinite(v)) || Math.abs(x2 - x1) < 1e-12) {
        throw new Error('Need two distinct valid points.');
      }
      const gain   = (y2 - y1) / (x2 - x1);
      const offset = y1 - gain * x1;
      return { gain, offset };
    }
  
    // Actions in each row
    tableBody.addEventListener('click', (e) => {
      const btn = e.target.closest('button[data-act]');
      if (!btn) return;
      const tr = e.target.closest('tr');
      const idx = rows.indexOf(tr);
      if (btn.dataset.act === 'zero') {
        // Set reference to 0 for this row; keep measure; clear poly coeffs
        setRowVals(tr, { reference: 0, c2: 0, c3: 0 });
      } else if (btn.dataset.act === 'calc') {
        try {
          // Use current row and the next active row to compute a 2-point fit
          const n = Math.max(1, parseInt(usedInput.value || '1', 10));
          const j = (idx + 1) < n ? (idx + 1) : (idx - 1);
          if (j < 0 || j >= n) throw new Error('Select two rows within used points.');
          const p0 = getRowVals(rows[idx]);
          const p1 = getRowVals(rows[j]);
          const { gain, offset } = fit2pt(p0, p1);
          setRowVals(tr, { gain, offset, c2: 0, c3: 0 });
          status('Row coefficients updated from 2-point fit.', 'ok');
        } catch (err) {
          status(err.message, 'err');
        }
      }
    });
  
    // Global “Auto-calc”: use the first two active rows
    $('#btnCalcAll').addEventListener('click', () => {
      try {
        const n = Math.max(1, parseInt(usedInput.value || '1', 10));
        if (n < 2) throw new Error('Need at least 2 points for auto-calc.');
        const p0 = getRowVals(rows[0]);
        const p1 = getRowVals(rows[1]);
        const { gain, offset } = fit2pt(p0, p1);
        for (let i = 0; i < n; i++) setRowVals(rows[i], { gain, offset, c2: 0, c3: 0 });
        status('Applied 2-point fit to all active rows.', 'ok');
      } catch (err) {
        status(err.message, 'err');
      }
    });
  
    // Save: gather payload and emit (mock)
    $('#btnSave').addEventListener('click', async () => {
      const n = Math.max(1, parseInt(usedInput.value || '1', 10));
      const payload = {
        channel: parseInt(chSel.value, 10),
        unit: unitBox.value.trim(),
        used_points: n,
        points: rows.slice(0, n).map((tr, i) => ({ idx:i, ...getRowVals(tr) }))
      };
  
      try {
        // TODO: replace with real POST to backend endpoint
        // await fetch('/api/calibration', {method:'POST', body:JSON.stringify(payload)})
        console.log('CAL/SUBMIT', payload);
        status('Saved (mock). Check console for payload.', 'ok');
      } catch (err) {
        status('Save failed.', 'err');
      }
    });
  
    $('#btnClose').addEventListener('click', () => window.close());
  
    // Optionally load existing calibration (mock)
    (function initialLoad(){
      // TODO: replace with real fetch by channel
      // Example prefill: leave cells blank by default
    })();
  
    function status(msg, type) {
      statusEl.textContent = msg || '';
      statusEl.style.color = type === 'ok' ? '#16a34a' : (type === 'err' ? '#dc2626' : 'var(--muted)');
    }
  })();
  