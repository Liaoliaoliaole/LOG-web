/* ===========================================================================
 * Edit Link popup (theme: LOG WEB v2)
 * - Mirrors original ISO_CH_MOD.html behavior:
 *   • Type / Path / ISO are read-only.
 *   • Description / Min / Max / Alarms / Unit are editable.
 *   • Alarm “Enable” toggles enable/disable the numeric boxes.
 *   • Save builds a payload similar to legacy MOD command.
 *
 * Integration notes:
 *   1) The parent window may pass the row data via:
 *      - window.name (JSON string), or
 *      - sessionStorage["edit_channel_payload"], or
 *      - window.opener.__EDIT_LINK_DATA (object).
 *      The first one found wins. A small MOCK is used as fallback.
 *   2) Hook "doSubmit" to your backend (POST to /morfeas_php/... if you keep
 *      the legacy endpoint). The file keeps a fully isolated UI layer.
 * ========================================================================== */

(function () {
    const $ = (s, r = document) => r.querySelector(s);
  
    // --- Field refs
    const f = {
      status:   $('#status'),
      type:     $('#type'),
      path:     $('#path'),
      iso:      $('#iso'),
      desc:     $('#desc'),
      min:      $('#min'),
      max:      $('#max'),
      alarmLow: $('#alarmLow'),
      alarmLowV:$('#alarmLowVal'),
      alarmHigh:$('#alarmHigh'),
      alarmHighV:$('#alarmHighVal'),
      unit:     $('#unit'),
      save:     $('#btnSave'),
      cancel:   $('#btnCancel'),
    };
  
    // --- Get initial data from parent in a tolerant way
    function readPayload() {
      // 1) window.name as JSON
      try {
        if (window.name && window.name.trim().startsWith('{')) {
          return JSON.parse(window.name);
        }
      } catch (_) {}
  
      // 2) sessionStorage
      try {
        const s = sessionStorage.getItem('edit_channel_payload');
        if (s) return JSON.parse(s);
      } catch (_) {}
  
      // 3) global on opener
      try {
        if (window.opener && window.opener.__EDIT_LINK_DATA) {
          return window.opener.__EDIT_LINK_DATA;
        }
      } catch (_) {}
  
      // 4) Fallback (MOCK) for manual testing
      return {
        IF_type:      'SDAQ',
        Connection:   'CAN1.ADDR:01.CH:16',
        ISOChannel:   '_TE1041A',
        Description:  'Fuel Oil Temp Pump 1',
        Min:          0,
        Max:          150,
        AlarmLow:     'no',
        AlarmLowVal:  0,
        AlarmHigh:    'no',
        AlarmHighVal: 150,
        Unit:         '°C',
        Anchor:       'mock-anchor',     // kept for compatibility with legacy MOD
      };
    }
  
    // --- Alarm enabled <-> input disabled sync
    function syncAlarmInputs() {
      f.alarmLowV.disabled  = !f.alarmLow.checked;
      f.alarmHighV.disabled = !f.alarmHigh.checked;
      f.alarmLowV.style.background  = f.alarmLowV.disabled  ? 'var(--bg-weak)' : '';
      f.alarmHighV.style.background = f.alarmHighV.disabled ? 'var(--bg-weak)' : '';
    }
  
    // --- Validation similar to the original
    function validate() {
      const min = Number(f.min.value);
      const max = Number(f.max.value);
      const lo  = Number(f.alarmLowV.value);
      const hi  = Number(f.alarmHighV.value);
  
      f.status.style.color = 'inherit';
      f.status.textContent = 'Review and update fields, then Save.';
  
      if (Number.isFinite(min) && Number.isFinite(max) && min > max) {
        f.status.textContent = 'Error: Min > Max';
        f.status.style.color = '#e11d48';
        return false;
      }
      if (f.alarmLow.checked && f.alarmHigh.checked &&
          Number.isFinite(lo) && Number.isFinite(hi) && lo > hi) {
        f.status.textContent = 'Error: Alarm Low > Alarm High';
        f.status.style.color = '#e11d48';
        return false;
      }
      return true;
    }
  
    // --- Fill UI from payload
    const payload = readPayload();
    (function hydrate() {
      f.type.value     = payload.IF_type || payload.Type || '';
      f.path.value     = payload.Connection || payload.Path || '';
      f.iso.value      = payload.ISOChannel || payload.ISO || '';
      f.desc.value     = payload.Description || '';
      f.min.value      = payload.Min ?? '';
      f.max.value      = payload.Max ?? '';
      f.unit.value     = payload.Unit || '';
  
      // alarms
      f.alarmLow.checked  = (payload.AlarmLow  || 'no') === 'yes';
      f.alarmHigh.checked = (payload.AlarmHigh || 'no') === 'yes';
      f.alarmLowV.value   = payload.AlarmLowVal  ?? (f.min.value || 0);
      f.alarmHighV.value  = payload.AlarmHighVal ?? (f.max.value || 0);
      syncAlarmInputs();
    })();
  
    // --- Events
    f.alarmLow.addEventListener('change', syncAlarmInputs);
    f.alarmHigh.addEventListener('change', syncAlarmInputs);
  
    // Basic live validation like legacy “vals_check”
    ['input','change'].forEach(ev => {
      [f.desc, f.min, f.max, f.alarmLowV, f.alarmHighV, f.unit].forEach(el =>
        el.addEventListener(ev, validate)
      );
    });
  
    f.cancel.addEventListener('click', () => window.close());
  
    // --- Submit
    f.save.addEventListener('click', async () => {
      if (!validate()) return;
  
      // Build a MOD-like record (field names follow the legacy format)
      const now = Math.trunc(Date.now() / 1000);
      const record = {
        COMMAND: 'MOD',
        DATA: [{
          IF_type:      f.type.value,
          Anchor:       payload.Anchor || '',          // important for backend to identify the link
          ISOChannel:   f.iso.value,
          Description:  f.desc.value,
          Min:          f.min.value,
          Max:          f.max.value,
          AlarmLow:     f.alarmLow.checked  ? 'yes' : 'no',
          AlarmLowVal:  f.alarmLowV.value,
          AlarmHigh:    f.alarmHigh.checked ? 'yes' : 'no',
          AlarmHighVal: f.alarmHighV.value,
          Unit:         f.unit.value,
          Mod_date_UNIX: now
        }]
      };
  
      // Hook to backend here:
      // await doSubmit(record);
      console.log('[EditLink] outgoing MOD payload:', record);
  
      // Notify parent (optional). Parent can listen for this and refresh the row.
      try { window.opener && window.opener.postMessage({type:'edit-link-saved', record}, '*'); } catch(_) {}
  
      window.close();
    });
  
    // --- Backend submission stub (keep signature; wire later if needed)
    async function doSubmit(modPayload) {
      // Example (legacy endpoint):
      // const res = await fetch('/morfeas_php/morfeas_web_if.php', {
      //   method: 'POST',
      //   body: compress(JSON.stringify(modPayload))   // if you still use legacy compress()
      // });
      // if (!res.ok) throw new Error('Network error');
      // const ct = res.headers.get('Content-Type') || '';
      // if (ct.includes('report/text')) {
      //   const msg = await res.text();
      //   throw new Error(msg || 'Server reported error');
      // }
      // const json = await res.json();
      // if (!json?.success) throw new Error('Operation failed');
    }
  
    // Esc to close
    document.addEventListener('keydown', (e)=>{ if (e.key === 'Escape') window.close(); });
  })();
  