(() => {
  document.addEventListener('DOMContentLoaded', () => {
    const $ = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    const modeSel = $('#modeSel');
    const hostInp = $('#host');
    const maskInp = $('#mask');

    const ipOcts = $$('.ip');
    const gwOcts = $$('.gw');
    const dnsOcts = $$('.dns');
    const staticEditables = [...ipOcts, ...gwOcts, ...dnsOcts, maskInp];

    const loadLastBtn = $('#loadLastBtn');
    const commitBtn = $('#commitBtn');
    const closeBtn = $('#closeBtn');

    const STORAGE_LAST = 'netconf:lastConfirmedPayload';

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

    const apiFacade = (() => {
      const wrapped = window.LOG_WEB?.api?.networkConfig;
      const endpoint = '../../backend/api_network_config.php';

      const fetchJson = async (opts = {}) => {
        const res = await fetch(endpoint, {
          cache: 'no-store',
          ...opts,
        });

        const body = await res.json().catch(() => ({}));
        if (!res.ok) {
          throw new Error(body?.error || `HTTP ${res.status}`);
        }
        if (body?.ok === false) {
          throw new Error(body?.error || 'API returned error');
        }
        return body;
      };

      if (wrapped) {
        return {
          fetchState: () => wrapped.fetchState(),
          apply: (payload, timeoutSec = 90) => wrapped.apply(payload, timeoutSec),
          confirm: (pendingId) => wrapped.confirm(pendingId),
          rollback: (pendingId) => wrapped.rollback(pendingId),
        };
      }

      return {
        fetchState: () => fetchJson({ method: 'GET', headers: { Accept: 'application/json' } }),
        apply: (payload, timeoutSec = 90) => fetchJson({
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({ action: 'apply', payload, timeout_sec: timeoutSec }),
        }),
        confirm: (pendingId) => fetchJson({
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({ action: 'confirm', pending_id: pendingId }),
        }),
        rollback: (pendingId) => fetchJson({
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({ action: 'rollback', ...(pendingId ? { pending_id: pendingId } : {}) }),
        }),
      };
    })();

    let latestState = null;
    let promptedPendingId = '';

    function notify(msg, isError = false) {
      if (isError) {
        console.error(msg);
      } else {
        console.log(msg);
      }
    }

    function setStaticEnabled(on) {
      staticEditables.forEach((el) => {
        el.disabled = !on;
        el.style.background = on ? '' : 'var(--bg-weak)';
      });
    }

    function fillGroup(nodes, values) {
      for (let i = 0; i < nodes.length; i += 1) {
        nodes[i].value = values && values[i] != null ? String(values[i]) : '';
      }
    }

    function splitIp(ip) {
      if (!ip || typeof ip !== 'string') return [];
      const arr = ip.split('.').map((x) => Number(x));
      if (arr.length !== 4 || arr.some((n) => !Number.isFinite(n))) return [];
      return arr;
    }

    function joinIp(nodes) {
      const parts = nodes.map((n) => n.value.trim());
      if (parts.some((p) => p === '')) return '';
      return parts.join('.');
    }

    function clampOctet(el) {
      const n = Number(el.value);
      if (Number.isFinite(n)) el.value = String(Math.max(0, Math.min(255, n)));
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

    function wireNumericFields() {
      [...ipOcts, ...gwOcts, ...dnsOcts].forEach((el) => {
        el.addEventListener('blur', () => clampOctet(el));
        el.addEventListener('input', (e) => {
          clampOctet(el);
          if (ipOcts.includes(el)) autoAdvance(e, ipOcts);
          if (gwOcts.includes(el)) autoAdvance(e, gwOcts);
          if (dnsOcts.includes(el)) autoAdvance(e, dnsOcts);
        });
        el.addEventListener('wheel', (e) => e.preventDefault(), { passive: false });
      });

      maskInp.addEventListener('input', () => {
        const n = Number(maskInp.value);
        if (Number.isFinite(n)) maskInp.value = String(Math.max(0, Math.min(32, n)));
        else maskInp.value = '';
      });
    }

    function setActivePresetBtn(key) {
      $$('[data-preset]').forEach((btn) => {
        btn.classList.toggle('active', btn.dataset.preset === key);
      });
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
    }

    function applyStateToForm(state) {
      latestState = state;

      hostInp.value = state?.hostname || '';
      const mode = String(state?.eth?.mode || 'static').toLowerCase() === 'dhcp' ? 'DHCP' : 'Static';
      modeSel.value = mode;
      setStaticEnabled(mode === 'Static');

      const ipv4 = state?.eth?.ipv4 || {};
      fillGroup(ipOcts, splitIp(ipv4.address));
      fillGroup(gwOcts, splitIp(ipv4.gateway));
      fillGroup(dnsOcts, splitIp((ipv4.dns || [])[0] || ''));
      maskInp.value = Number.isFinite(Number(ipv4.prefix)) ? String(ipv4.prefix) : '24';
    }

    function validateStaticAddress(address, currentIp, operatorIp) {
      const m = /^192\.168\.137\.(\d{1,3})$/.exec(address || '');
      if (!m) throw new Error('Static IP must be in 192.168.137.0/24.');
      const host = Number(m[1]);
      if (!(host >= 2 && host <= 254) || host === 1) {
        throw new Error('Static IP host must be 2-254 and not reserved.');
      }
      if (currentIp && address === currentIp) {
        throw new Error('Static IP cannot equal current Pi IP.');
      }
      if (operatorIp && address === operatorIp) {
        throw new Error('Static IP cannot equal current operator IP.');
      }
    }

    function validateGateway(gateway) {
      const m = /^192\.168\.137\.(\d{1,3})$/.exec(gateway || '');
      if (!m) throw new Error('Gateway must be in 192.168.137.0/24.');
      const host = Number(m[1]);
      if (host === 0 || host === 255) {
        throw new Error('Gateway cannot use reserved host address.');
      }
    }

    function buildPayloadFromForm() {
      const mode = modeSel.value === 'DHCP' ? 'dhcp' : 'static';
      const hostname = hostInp.value.trim();
      if (!hostname) throw new Error('Hostname is required.');

      const payload = {
        hostname,
        eth: {
          mode,
          ipv4: {
            address: joinIp(ipOcts),
            prefix: Number(maskInp.value || 24),
            gateway: joinIp(gwOcts),
            dns: (() => {
              const dns = joinIp(dnsOcts);
              return dns ? [dns] : [];
            })(),
          },
        },
        can: {
          can0: { bitrate: Number(latestState?.can?.can0?.bitrate || 0) },
          can1: { bitrate: Number(latestState?.can?.can1?.bitrate || 0) },
        },
      };

      if (mode === 'static') {
        validateStaticAddress(
          payload.eth.ipv4.address,
          latestState?.eth?.current_ip || '',
          latestState?.eth?.operator_ip || ''
        );
        if (payload.eth.ipv4.prefix !== 24) {
          throw new Error('Only /24 prefix is allowed in this test phase.');
        }
        validateGateway(payload.eth.ipv4.gateway);
      }

      if (payload.can.can0.bitrate <= 0 || payload.can.can1.bitrate <= 0) {
        throw new Error('CAN bitrate readback unavailable. Check can0/can1 status before applying.');
      }

      return payload;
    }

    function loadLastPayload() {
      const raw = localStorage.getItem(STORAGE_LAST);
      if (!raw) {
        alert('No previously confirmed payload saved on this browser.');
        return;
      }

      let payload;
      try {
        payload = JSON.parse(raw);
      } catch (_) {
        alert('Saved payload is invalid JSON.');
        return;
      }

      hostInp.value = payload?.hostname || '';
      const mode = payload?.eth?.mode === 'dhcp' ? 'DHCP' : 'Static';
      modeSel.value = mode;
      setStaticEnabled(mode === 'Static');

      const ipv4 = payload?.eth?.ipv4 || {};
      fillGroup(ipOcts, splitIp(ipv4.address));
      fillGroup(gwOcts, splitIp(ipv4.gateway));
      fillGroup(dnsOcts, splitIp((ipv4.dns || [])[0] || ''));
      maskInp.value = Number.isFinite(Number(ipv4.prefix)) ? String(ipv4.prefix) : '24';
    }

    async function confirmPending(pendingId) {
      const res = await apiFacade.confirm(pendingId);
      if (!res?.ok) throw new Error(res?.error || 'Confirm failed');
      notify('Pending apply confirmed.');
      localStorage.setItem(STORAGE_LAST, JSON.stringify(buildPayloadFromForm()));
      return res;
    }

    async function rollbackPending(pendingId) {
      const res = await apiFacade.rollback(pendingId);
      if (!res?.ok) throw new Error(res?.error || 'Rollback failed');
      notify('Pending apply rolled back.');
      return res;
    }

    async function maybePromptPending(pending) {
      if (!pending || pending.state !== 'pending' || !pending.pending_id) return;
      if (pending.pending_id === promptedPendingId) return;

      promptedPendingId = pending.pending_id;
      const remaining = Number(pending.remaining_sec || 0);

      const wantConfirm = window.confirm(
        `Pending network apply detected (${remaining}s left).\nClick OK to confirm now.\nClick Cancel to keep pending (auto-rollback on timeout).`
      );
      if (wantConfirm) {
        try {
          await confirmPending(pending.pending_id);
          await loadState();
          return;
        } catch (err) {
          alert(`Confirm failed: ${err.message || err}`);
          return;
        }
      }

      const wantRollback = window.confirm('Do you want to rollback this pending apply now?');
      if (!wantRollback) return;

      try {
        await rollbackPending(pending.pending_id);
        await loadState();
      } catch (err) {
        alert(`Rollback failed: ${err.message || err}`);
      }
    }

    async function loadState() {
      try {
        const res = await apiFacade.fetchState();
        if (!res?.ok) throw new Error(res?.error || 'State query failed');

        applyStateToForm(res.data);
        notify(`Loaded network state: ${res.data?.eth?.mode || 'N/A'} ${res.data?.eth?.ipv4?.address || ''}`);
        await maybePromptPending(res.data?.pending || null);
      } catch (err) {
        alert(`Failed to load network state: ${err.message || err}`);
      }
    }

    async function applyConfig() {
      let payload;
      try {
        payload = buildPayloadFromForm();
      } catch (err) {
        alert(err.message || String(err));
        return;
      }

      commitBtn.disabled = true;
      try {
        const res = await apiFacade.apply(payload, 90);
        if (!res?.ok) throw new Error(res?.error || 'Apply failed');

        const pending = res?.data?.pending;
        if (pending?.state === 'pending') {
          const wantConfirm = window.confirm(
            `Apply succeeded. Confirm within ${pending.timeout_sec || 90}s to keep changes.\nClick OK to confirm now.\nClick Cancel to leave pending (auto-rollback on timeout).`
          );
          if (wantConfirm) {
            await confirmPending(pending.pending_id);
          }
        }

        await loadState();
      } catch (err) {
        alert(`Apply failed: ${err.message || err}`);
      } finally {
        commitBtn.disabled = false;
      }
    }

    modeSel.addEventListener('change', () => {
      setStaticEnabled(modeSel.value === 'Static');
    });

    $$('[data-preset]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const key = btn.dataset.preset;
        if (PRESETS[key]) applyPreset(PRESETS[key], key);
      });
    });

    loadLastBtn.addEventListener('click', loadLastPayload);
    commitBtn.addEventListener('click', applyConfig);
    closeBtn.addEventListener('click', () => window.close());

    setStaticEnabled(true);
    wireNumericFields();
    loadState();
  });
})();
