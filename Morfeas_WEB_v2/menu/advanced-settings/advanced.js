// Advanced Settings – works over HTTP (Pi) + file:// fallback (desktop preview)
(() => {
  const $ = (s, r = document) => r.querySelector(s);
  const byId = (id) => document.getElementById(id);

  /* -------------------------------
   *  A) Mocked bits (safe defaults)
   * ----------------------------- */
  const MOCK = {
    mac: 'E4:5F:01:A1:1C:B8',
    canBitrates: { can0: '500 kbps', can1: '500 kbps' },
  };
  byId('macText').textContent = MOCK.mac;
  byId('can0').value = MOCK.canBitrates.can0;
  byId('can1').value = MOCK.canBitrates.can1;

  byId('loadLast').addEventListener('click', () => {
    byId('can0').value = MOCK.canBitrates.can0;
    byId('can1').value = MOCK.canBitrates.can1;
    toast('Loaded last configuration (mock).');
  });

  byId('apply').addEventListener('click', () => {
    toast(`Applied: CAN0 ${byId('can0').value}, CAN1 ${byId('can1').value} (mock).`);
  });

  /* ----------------------------------------------------
   *  B) ISO XML loader
   *     - On Pi (http://…): loads via same-origin fetch.
   *     - On desktop (file://): shows a file picker fallback.
   *     - Later, change ISO_URL to any served path (e.g. /home alias).
   * -------------------------------------------------- */

  // 1) Current location (same folder as this HTML):
  let ISO_URL = 'ISOstandard.xml';

  // 2) If relocate on the Pi, change ONLY this line, e.g.:
  // ISO_URL = '/morfeas/iso/ISOstandard.xml';  // (served by the web server)

  byId('isoPath').textContent = ISO_URL;

  const isoTbody = $('#isoTable tbody');
  const isoEmpty = byId('isoEmpty');

  function renderISO(xmlText) {
    const doc = new DOMParser().parseFromString(xmlText, 'application/xml');
    const points = doc.querySelector('points');
    isoTbody.innerHTML = '';
    if (!points) { isoEmpty.style.display = 'block'; return; }

    let i = 1;
    [...points.children].forEach((node) => {
      if (node.nodeType !== 1) return;
      const name = node.nodeName;
      const desc = node.querySelector('description')?.textContent ?? '-';
      const unit = node.querySelector('unit')?.textContent ?? '-';
      const max = node.querySelector('max')?.textContent ?? '-';
      const min = node.querySelector('min')?.textContent ?? '-';

      const tr = document.createElement('tr');
      tr.innerHTML =
        `<td><b>${i++}</b></td><td>${name}</td><td>${desc}</td><td>${unit}</td><td>${max}</td><td>${min}</td>`;
      isoTbody.appendChild(tr);
    });

    if (!isoTbody.children.length) isoEmpty.style.display = 'block';
  }

  async function loadISOOverHTTP() {
    const res = await fetch(ISO_URL, { cache: 'no-store' });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    renderISO(await res.text());
  }

  function attachLocalFilePicker() {
    // Simple fallback for file:// preview (no server).
    // If you prefer the original sanitizer, call isoSTD_xml_file_val(input) instead.
    const input = Object.assign(document.createElement('input'), {
      type: 'file', accept: '.xml', style: 'margin-top:8px'
    });
    input.addEventListener('change', async () => {
      const file = input.files?.[0];
      if (!file) return;
      renderISO(await file.text());
    });
    byId('isoPath').textContent = 'Choose local XML…';
    byId('isoPath').insertAdjacentElement('afterend', input);
  }

  (async () => {
    try {
      // If opened as file:// most browsers block fetch of local files.
      if (location.protocol === 'file:') throw new Error('file://');
      await loadISOOverHTTP();   // RPi path (matches Morfeas)
    } catch {
      attachLocalFilePicker();   // desktop preview
    }
  })();

  /* -------------------------------
   *  Small toast helper
   * ----------------------------- */
  function toast(text) {
    const el = document.createElement('div');
    el.textContent = text;
    Object.assign(el.style, {
      position: 'fixed', left: '50%', bottom: '24px', transform: 'translateX(-50%)',
      background: '#111827', color: '#fff', padding: '10px 14px', borderRadius: '10px',
      boxShadow: '0 10px 30px rgba(0,0,0,.2)', zIndex: 9999, fontWeight: '700'
    });
    document.body.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity .3s'; }, 1400);
    setTimeout(() => el.remove(), 1750);
  }
})();
