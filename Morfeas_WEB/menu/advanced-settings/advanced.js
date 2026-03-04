// Advanced Settings – works over HTTP (Pi) + file:// fallback (desktop preview)
(() => {
  const $ = (s, r = document) => r.querySelector(s);
  const byId = (id) => document.getElementById(id);

  const channelsApi = window.LOG_WEB?.api?.channels;
  const isoCatalogService = window.LOG_WEB?.services?.isoCatalog;

  const macText = byId('macText');
  const can0El = byId('can0');
  const can1El = byId('can1');
  macText.textContent = '—';
  can0El.textContent = '—';
  can1El.textContent = '—';

  async function loadMachineInfo() {
    if (!channelsApi) throw new Error('Channels API unavailable');
    const data = await channelsApi.fetchMachineInfo();

    if (data.mac) macText.textContent = data.mac;
    const can = data.can || {};
    can0El.textContent = can.can0 || '—';
    can1El.textContent = can.can1 || '—';
  }

  loadMachineInfo().catch(() => {
    macText.textContent = macText.textContent || '—';
    can0El.textContent = can0El.textContent || '—';
    can1El.textContent = can1El.textContent || '—';
  });

  /* ----------------------------------------------------
   *  B) ISO XML loader
   *     - On Pi (http://…): loads via same-origin fetch.
   *     - On desktop (file://): shows a file picker fallback.
   *     - Later,  override via backend config or local file picker fallback.
   * -------------------------------------------------- */

  // 1) Legacy source (now server-provided in ISO list):
  const ISO_STORAGE_KEY = 'iso_standard_selected_id';
  const ISO_LEGACY_HINT = 'Server-provided path';

  // 3) (Optional) Local fallback if you want to override manually:
  // (use the local file picker below when no backend is available)

  byId('isoPath').textContent = ISO_LEGACY_HINT;
  const isoSelect = byId('isoSelect');
  const isoUploadBtn = byId('isoUploadBtn');
  const isoUploadInput = byId('isoUploadInput');
  const isoState = {
    options: [],
    ready: false,
  };

  const isoStorage = {
    get() {
      try {
        return localStorage.getItem(ISO_STORAGE_KEY) || '';
      } catch (_) {
        return '';
      }
    },
    set(val) {
      try {
        if (val) localStorage.setItem(ISO_STORAGE_KEY, val);
        else localStorage.removeItem(ISO_STORAGE_KEY);
      } catch (_) { }
    },
  };

  const isoTbody = $('#isoTable tbody');
  const isoEmpty = byId('isoEmpty');

  function renderISO(xmlText) {
    const doc = new DOMParser().parseFromString(xmlText, 'application/xml');
    const points = doc.querySelector('points');
    isoTbody.innerHTML = '';
    isoEmpty.style.display = 'none';
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

  async function loadISOOverHTTP(fileId) {
    if (!channelsApi) throw new Error('Channels API unavailable');
    renderISO(await channelsApi.fetchIsoStandard(fileId));
  }

  async function loadISOList() {
    if (!channelsApi) throw new Error('Channels API unavailable');
    return channelsApi.fetchIsoStandardList();
  }

  async function uploadISO(file) {
    if (!channelsApi) throw new Error('Channels API unavailable');
    const payload = await channelsApi.uploadIsoStandard(file);
    if (!payload.ok) throw new Error(payload.error || 'Upload failed');
    return payload;
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
    isoSelect.disabled = true;
  }

  (async () => {
    try {
      // If opened as file:// most browsers block fetch of local files.
      if (location.protocol === 'file:') throw new Error('file://');

      const list = await loadISOList();
      const files = list?.files ?? [];
      if (!files.length) throw new Error('No ISOstandard files');

      isoSelect.innerHTML = '';
      files.forEach((f) => {
        const opt = document.createElement('option');
        opt.value = f.id;
        opt.textContent = `${f.name} (${f.source})`;
        opt.dataset.path = f.path;
        isoSelect.appendChild(opt);
      });

      const savedId = isoCatalogService?.storage?.get?.() || isoStorage.get();
      const selected = files.find((f) => f.id === savedId)
        ?? files.find((f) => f.is_default)
        ?? files[0];
      if (selected) {
        isoSelect.value = selected.id;
        byId('isoPath').textContent = selected.path ?? ISO_LEGACY_HINT;
        await loadISOOverHTTP(selected.id);
        isoState.options = files;
        isoState.ready = true;
        isoCatalogService?.storage?.set?.(selected.id) || isoStorage.set(selected.id);
      }

      isoSelect.addEventListener('change', () => {
        if (!isoState.ready) return;
        const current = isoState.options.find((f) => f.id === isoSelect.value);
        byId('isoPath').textContent = current?.path ?? ISO_LEGACY_HINT;
        isoCatalogService?.storage?.set?.(current?.id || '') || isoStorage.set(current?.id || '');
        loadISOOverHTTP(isoSelect.value).catch(() => {
          toast('Failed to load ISO XML over HTTP, falling back to local file');
          attachLocalFilePicker();
        });
      });
    } catch {
      toast('No ISOstandard files found via HTTP; choose a local XML instead');
      attachLocalFilePicker();   // desktop preview
    }
  })();

  if (location.protocol === 'file:' && isoUploadBtn) {
    isoUploadBtn.disabled = true;
    isoUploadBtn.title = 'Upload requires a running backend';
  }

  isoUploadBtn?.addEventListener('click', () => {
    isoUploadInput?.click();
  });

  isoUploadInput?.addEventListener('change', async () => {
    const file = isoUploadInput.files?.[0];
    if (!file) return;
    try {
      const uploadResult = await uploadISO(file);
      const uploadedPath = uploadResult?.path || '';
      const list = await loadISOList();
      const files = list?.files ?? [];
      isoSelect.innerHTML = '';
      files.forEach((f) => {
        const opt = document.createElement('option');
        opt.value = f.id;
        opt.textContent = `${f.name} (${f.source})`;
        opt.dataset.path = f.path;
        isoSelect.appendChild(opt);
      });
      const uploaded = files.find((f) => f.path === uploadedPath) || files.find((f) => f.name === file.name) || files[files.length - 1];
      if (uploaded) {
        isoSelect.value = uploaded.id;
        isoStorage.set(uploaded.id);
        byId('isoPath').textContent = uploaded.path ?? ISO_LEGACY_HINT;
        await loadISOOverHTTP(uploaded.id);
      }
      if (uploadResult?.renamed) {
        toast(`ISOstandard uploaded with rename: ${uploadResult.original_name} -> ${uploadResult.name}`);
      } else {
        toast(`ISOstandard uploaded: ${uploadResult?.name || file.name}`);
      }
    } catch (err) {
      toast(`Upload failed: ${err.message || err}`);
    } finally {
      isoUploadInput.value = '';
    }
  });

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
