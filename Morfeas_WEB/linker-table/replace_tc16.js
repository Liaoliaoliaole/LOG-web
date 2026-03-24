(() => {
  const $ = (s, r = document) => r.querySelector(s);

  const statusEl = $('#status');
  const sourceSummaryEl = $('#sourceSummary');
  const sourceChannelsEl = $('#sourceChannels');
  const targetListEl = $('#targetList');

  const btnRefresh = $('#btnRefresh');
  const btnApply = $('#btnApply');
  const btnClose = $('#btnClose');

  const channelsApi = window.LOG_WEB?.api?.channels;

  const state = {
    source: null,
    targets: [],
    selectedTargetKey: '',
    loading: false,
    applying: false,
  };

  const setStatus = (msg, tone = 'info') => {
    if (!statusEl) return;
    statusEl.textContent = msg;
    statusEl.style.color = tone === 'error' ? '#e11d48' : (tone === 'ok' ? '#16a34a' : 'inherit');
  };

  function readPayload() {
    try {
      const raw = sessionStorage.getItem('replace_tc16_payload');
      if (raw) return JSON.parse(raw);
    } catch (_) {}

    try {
      if (window.opener && window.opener.__REPLACE_TC16_DATA) {
        return window.opener.__REPLACE_TC16_DATA;
      }
    } catch (_) {}

    return null;
  }

  function renderSource() {
    const source = state.source;
    if (!source) {
      sourceSummaryEl.textContent = 'No source context.';
      sourceChannelsEl.innerHTML = '';
      return;
    }

    sourceSummaryEl.textContent = `ISO ${source.iso_channel} • ${source.mode.toUpperCase()} • ${source.source_key}`;
    sourceChannelsEl.innerHTML = '';

    (source.channels || []).forEach((entry) => {
      const chip = document.createElement('div');
      chip.className = 'chip mono';
      chip.textContent = `CH${entry.ch_no} → ${entry.iso_channel}`;
      sourceChannelsEl.appendChild(chip);
    });
  }

  function renderTargets() {
    targetListEl.innerHTML = '';
    if (!state.targets.length) {
      const div = document.createElement('div');
      div.className = 'mono';
      div.textContent = 'No eligible unlinked TC16 target devices found.';
      targetListEl.appendChild(div);
      btnApply.disabled = true;
      return;
    }

    state.targets.forEach((target) => {
      const row = document.createElement('button');
      row.type = 'button';
      row.className = 'target' + (state.selectedTargetKey === target.device_key ? ' active' : '');

      const radio = document.createElement('input');
      radio.type = 'radio';
      radio.name = 'tc16_target';
      radio.checked = state.selectedTargetKey === target.device_key;
      radio.tabIndex = -1;

      const titleWrap = document.createElement('div');
      const title = document.createElement('div');
      title.className = 'mono';
      title.textContent = target.device_key;
      const meta = document.createElement('div');
      meta.className = 'meta';
      meta.textContent = `${target.sdaq_type} • serial ${target.serial || '—'} • channels ${target.number_of_channels}`;
      titleWrap.append(title, meta);

      const avail = document.createElement('div');
      avail.className = 'chip';
      avail.textContent = 'CH1..CH16 free';

      row.append(radio, titleWrap, avail);
      row.addEventListener('click', () => {
        state.selectedTargetKey = target.device_key;
        renderTargets();
        setStatus(`Selected target ${target.device_key}.`);
      });

      targetListEl.appendChild(row);
    });

    btnApply.disabled = !state.selectedTargetKey;
  }

  async function loadCandidates(manual = false) {
    if (state.loading) return;
    if (!channelsApi?.fetchTc16Candidates) {
      setStatus('Channels API unavailable.', 'error');
      return;
    }
    if (!state.source?.iso_channel) {
      setStatus('Missing source ISO context.', 'error');
      return;
    }

    state.loading = true;
    btnRefresh.disabled = true;
    btnApply.disabled = true;
    setStatus(manual ? 'Refreshing target devices...' : 'Loading target devices...');

    try {
      const payload = await channelsApi.fetchTc16Candidates(state.source.iso_channel);
      const source = payload?.data?.source;
      const targets = payload?.data?.targets || [];
      if (!source?.iso_channel) {
        throw new Error('Invalid source payload from backend.');
      }

      state.source = source;
      state.targets = Array.isArray(targets) ? targets : [];

      if (!state.targets.some((t) => t.device_key === state.selectedTargetKey)) {
        state.selectedTargetKey = '';
      }

      renderSource();
      renderTargets();
      setStatus(`Loaded ${state.targets.length} eligible TC16 target device(s).`, 'ok');
    } catch (err) {
      console.error(err);
      const msg = err?.payload?.error || err?.message || 'Failed to load TC16 candidates.';
      const code = err?.payload?.code;
      setStatus(code ? `${msg} [${code}]` : msg, 'error');
      state.targets = [];
      renderTargets();
    } finally {
      state.loading = false;
      btnRefresh.disabled = false;
      if (!state.selectedTargetKey) btnApply.disabled = true;
    }
  }

  async function applyReplace() {
    if (state.applying) return;
    if (!channelsApi?.applyTc16Replace) {
      setStatus('Channels API unavailable.', 'error');
      return;
    }
    if (!state.source?.iso_channel) {
      setStatus('Missing source ISO context.', 'error');
      return;
    }
    if (!state.selectedTargetKey) {
      setStatus('Please select a target device first.', 'error');
      return;
    }

    const confirmText = `Replace all 16 channels from ${state.source.source_key} to ${state.selectedTargetKey}?`;
    if (!window.confirm(confirmText)) {
      setStatus('Canceled. No TC16 replace command sent.');
      return;
    }

    state.applying = true;
    btnApply.disabled = true;
    btnRefresh.disabled = true;
    setStatus('Applying TC16 replace... please wait.');

    try {
      const payload = await channelsApi.applyTc16Replace({
        source_iso: state.source.iso_channel,
        target_key: state.selectedTargetKey,
      });

      const count = Number(payload?.data?.replaced_count || 0);
      if (count !== 16) {
        throw new Error(`Unexpected replaced count: ${count}`);
      }

      setStatus(`Replace TC16 complete: ${count} channels updated.`, 'ok');
      try {
        const targetOrigin = window.location.origin || '*';
        window.opener?.postMessage({ type: 'channel-updated' }, targetOrigin);
      } catch (_) {}
      setTimeout(() => window.close(), 500);
    } catch (err) {
      console.error(err);
      const msg = err?.payload?.error || err?.message || 'Failed to apply TC16 replace.';
      const code = err?.payload?.code;
      setStatus(code ? `${msg} [${code}]` : msg, 'error');
      btnApply.disabled = !state.selectedTargetKey;
    } finally {
      state.applying = false;
      btnRefresh.disabled = false;
    }
  }

  btnRefresh?.addEventListener('click', (e) => {
    e.preventDefault();
    loadCandidates(true);
  });

  btnApply?.addEventListener('click', (e) => {
    e.preventDefault();
    applyReplace();
  });

  btnClose?.addEventListener('click', (e) => {
    e.preventDefault();
    window.close();
  });

  (async function init() {
    const payload = readPayload();
    if (!payload?.source_iso) {
      setStatus('Missing Replace TC16 payload.', 'error');
      btnRefresh.disabled = true;
      btnApply.disabled = true;
      return;
    }

    state.source = {
      iso_channel: payload.source_iso,
      mode: payload.source_mode || 'unknown',
      source_key: payload.source_key || 'unknown',
      channels: Array.isArray(payload.channels) ? payload.channels : [],
    };
    renderSource();
    await loadCandidates(false);
  })();
})();
