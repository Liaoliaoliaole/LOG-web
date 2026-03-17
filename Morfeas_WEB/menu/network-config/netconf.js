(() => {
  document.addEventListener('DOMContentLoaded', () => {
    const $ = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    const api = window.LOG_WEB?.api?.networkConfig;

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
    const confirmBtn = $('#confirmBtn');
    const rollbackBtn = $('#rollbackBtn');
    const pendingControls = $('#pendingControls');
    const pendingTimer = $('#pendingTimer');
    const statusMsg = $('#statusMsg');

    const STORAGE_LAST = 'netconf:lastConfirmedPayload';

    let latestState = null;
    let activePendingId = '';
    let countdownHandle = null;

    function setStatus(message, isError = false) {
      statusMsg.textContent = message;
      statusMsg.style.color = isError ? '#b42318' : 'var(--text)';
    }

    function clearCountdown() {
      if (countdownHandle) {
        clearInterval(countdownHandle);
        countdownHandle = null;
      }
    }

    function setStaticEnabled(on) {
      staticEditables.forEach((el) => {
        el.disabled = !on;
        el.style.background = on ? '' : 'var(--bg-weak)';
      });
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

    function showPending(pending) {
      clearCountdown();

      if (!pending || pending.state !== 'pending') {
        activePendingId = '';
        pendingControls.classList.remove('active');
        pendingTimer.textContent = 'Pending confirmation';
        return;
      }

      activePendingId = pending.pending_id || '';
      pendingControls.classList.add('active');

      const updateTimer = () => {
        const now = Math.floor(Date.now() / 1000);
        const left = Math.max(0, Number(pending.expires_at || 0) - now);
        pendingTimer.textContent = `Pending confirmation: ${left}s`;
        if (left <= 0) {
          clearCountdown();
          pendingTimer.textContent = 'Pending window expired; state will be reloaded.';
          loadState();
        }
      };

      updateTimer();
      countdownHandle = setInterval(updateTimer, 1000);
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
      if (!hostname) {
        throw new Error('Hostname is required.');
      }

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
        setStatus('No previously confirmed payload saved on this browser.');
        return;
      }

      let payload;
      try {
        payload = JSON.parse(raw);
      } catch (_) {
        setStatus('Saved payload is invalid JSON.', true);
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

      setStatus('Loaded last confirmed payload from browser storage.');
    }

    async function loadState() {
      if (!api) {
        setStatus('Network API unavailable. Make sure assets/config.js and assets/api/networkConfig.js are loaded.', true);
        return;
      }

      try {
        const res = await api.fetchState();
        if (!res?.ok) {
          throw new Error(res?.error || 'State query failed');
        }

        applyStateToForm(res.data);
        showPending(res.data?.pending || null);

        const mode = (res.data?.eth?.mode || '').toUpperCase();
        const ip = res.data?.eth?.ipv4?.address || 'n/a';
        const pendingState = res.data?.pending?.state;
        const pendingText = pendingState === 'pending' ? ' (pending confirmation)' : '';
        setStatus(`Loaded: ${mode || 'N/A'} / ${ip}${pendingText}`);
      } catch (err) {
        setStatus(`Failed to load network state: ${err.message || err}`, true);
      }
    }

    async function handleApply() {
      if (!api) {
        setStatus('Network API unavailable.', true);
        return;
      }

      let payload;
      try {
        payload = buildPayloadFromForm();
      } catch (err) {
        setStatus(err.message || String(err), true);
        return;
      }

      commitBtn.disabled = true;
      setStatus('Applying configuration... waiting for backend response.');

      try {
        const res = await api.apply(payload, 90);
        if (!res?.ok) {
          throw new Error(res?.error || 'Apply failed');
        }

        const pending = res?.data?.pending || null;
        showPending(pending);
        localStorage.setItem(STORAGE_LAST, JSON.stringify(payload));

        if (pending?.state === 'pending') {
          setStatus(`Apply succeeded. Confirm within ${pending.timeout_sec || 90}s or auto-rollback will trigger.`);
        } else {
          setStatus('Apply response received. Reloading state...');
        }

        await loadState();
      } catch (err) {
        setStatus(`Apply failed: ${err.message || err}`, true);
      } finally {
        commitBtn.disabled = false;
      }
    }

    async function handleConfirm() {
      if (!api || !activePendingId) {
        setStatus('No active pending operation to confirm.', true);
        return;
      }

      confirmBtn.disabled = true;
      rollbackBtn.disabled = true;
      setStatus('Confirming pending network apply...');

      try {
        const res = await api.confirm(activePendingId);
        if (!res?.ok) {
          throw new Error(res?.error || 'Confirm failed');
        }
        showPending(res?.data?.pending || null);
        setStatus('Pending apply confirmed.');
        await loadState();
      } catch (err) {
        setStatus(`Confirm failed: ${err.message || err}`, true);
      } finally {
        confirmBtn.disabled = false;
        rollbackBtn.disabled = false;
      }
    }

    async function handleRollback() {
      if (!api) {
        setStatus('Network API unavailable.', true);
        return;
      }

      confirmBtn.disabled = true;
      rollbackBtn.disabled = true;
      setStatus('Rolling back pending network apply...');

      try {
        const res = await api.rollback(activePendingId || undefined);
        if (!res?.ok) {
          throw new Error(res?.error || 'Rollback failed');
        }
        showPending(res?.data?.pending || null);
        setStatus('Rollback completed.');
        await loadState();
      } catch (err) {
        setStatus(`Rollback failed: ${err.message || err}`, true);
      } finally {
        confirmBtn.disabled = false;
        rollbackBtn.disabled = false;
      }
    }

    modeSel.addEventListener('change', () => {
      setStaticEnabled(modeSel.value === 'Static');
    });

    loadLastBtn.addEventListener('click', loadLastPayload);
    commitBtn.addEventListener('click', handleApply);
    confirmBtn.addEventListener('click', handleConfirm);
    rollbackBtn.addEventListener('click', handleRollback);
    closeBtn.addEventListener('click', () => window.close());

    setStaticEnabled(true);
    wireNumericFields();
    loadState();
  });
})();
