(() => {
  document.addEventListener('DOMContentLoaded', async () => {
    const $ = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    const modeSel = $('#modeSel');
    const hostInp = $('#host');
    const maskInp = $('#mask');

    const ipOcts = $$('.ip');
    const gwOcts = $$('.gw');
    const dnsOcts = $$('.dns');
    const staticEditables = [...ipOcts, ...gwOcts, ...dnsOcts, maskInp];

    const resetBtn = $('#resetBtn');
    const commitBtn = $('#commitBtn');
    const closeBtn = $('#closeBtn');

    let PRESETS = {};
    try {
      const presetsRes = await fetch('../../net_presets.json', { cache: 'no-store' });
      if (presetsRes.ok) PRESETS = await presetsRes.json();
    } catch (_) {
      // preset buttons will be inert if the file cannot be loaded
    }

    const apiFacade = window.LOG_WEB?.api?.networkConfig || null;

    let latestState = null;
    let pageStaleAfterIpSwitch = false;

    function ensureStatusEl() {
      let el = document.getElementById('netconfStatus');
      if (el) return el;

      const cardBody = document.querySelector('.card .card-body');
      if (!cardBody) return null;

      el = document.createElement('div');
      el.id = 'netconfStatus';
      el.style.marginTop = '10px';
      el.style.padding = '10px 12px';
      el.style.border = '1px solid #d9dde3';
      el.style.borderRadius = '10px';
      el.style.background = '#f7f9fb';
      el.style.fontWeight = '700';
      el.textContent = 'Ready';
      cardBody.appendChild(el);
      return el;
    }

    function setStatus(message, type = 'info') {
      const el = ensureStatusEl();
      if (!el) {
        console.log(message);
        return;
      }

      el.textContent = message;
      if (type === 'error') {
        el.style.background = '#fff1f0';
        el.style.borderColor = '#ffccc7';
        el.style.color = '#a8071a';
      } else if (type === 'success') {
        el.style.background = '#f6ffed';
        el.style.borderColor = '#b7eb8f';
        el.style.color = '#135200';
      } else if (type === 'progress') {
        el.style.background = '#e6f4ff';
        el.style.borderColor = '#91caff';
        el.style.color = '#003a8c';
      } else {
        el.style.background = '#f7f9fb';
        el.style.borderColor = '#d9dde3';
        el.style.color = '#1f2328';
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
      setStatus(`Preset ${key} loaded. Review values before Set.`);
    }

    function applyStateToForm(state) {
      latestState = state;
      setActivePresetBtn('');

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

    function summarizeCanEnabled(state) {
      const can = state?.can || {};
      const enabled = [];
      ['can0', 'can1'].forEach((iface) => {
        const bps = Number(can?.[iface]?.bitrate || 0);
        if (bps > 0) enabled.push(iface);
      });
      return enabled.length ? enabled.join(', ') : 'none';
    }

    async function probeTargetHost(ip, timeoutMs = 3500) {
      if (!ip) return false;

      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), timeoutMs);
      const base = `${window.location.protocol}//${ip}/`;
      try {
        await fetch(base, {
          method: 'GET',
          mode: 'no-cors',
          cache: 'no-store',
          signal: controller.signal,
        });
        return true;
      } catch (_) {
        return false;
      } finally {
        clearTimeout(timer);
      }
    }

    function buildReconnectUrl(ip) {
      if (!ip) return '';
      const protocol = (window.location.protocol === 'https:' || window.location.protocol === 'http:')
        ? window.location.protocol
        : 'http:';
      const port = window.location.port ? `:${window.location.port}` : '';
      return `${protocol}//${ip}${port}/`;
    }

    function isValidIpv4(value) {
      if (typeof value !== 'string') return false;
      const parts = value.split('.');
      if (parts.length !== 4) return false;
      return parts.every((part) => {
        if (part === '') return false;
        if (!/^\d+$/.test(part)) return false;
        const n = Number(part);
        return Number.isInteger(n) && n >= 0 && n <= 255;
      });
    }

    function validateIpv4Field(label, value) {
      if (!isValidIpv4(value || '')) {
        throw new Error(`${label} must be a valid IPv4 address.`);
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
        validateIpv4Field('Static IP', payload.eth.ipv4.address);
        if (!Number.isInteger(payload.eth.ipv4.prefix) || payload.eth.ipv4.prefix < 0 || payload.eth.ipv4.prefix > 32) {
          throw new Error('Mask prefix must be in range 0-32.');
        }
        validateIpv4Field('Gateway', payload.eth.ipv4.gateway);
        const dnsVal = payload.eth.ipv4.dns?.[0] || '';
        if (dnsVal) validateIpv4Field('DNS', dnsVal);
      }

      if (payload.can.can0.bitrate <= 0 || payload.can.can1.bitrate <= 0) {
        throw new Error('CAN bitrate readback unavailable. Check can0/can1 status before applying.');
      }

      return payload;
    }

    function hasNetworkConfigChanged(payload) {
      if (!latestState) return true;

      const currentIpv4 = latestState?.eth?.ipv4 || {};
      const currentDns = Array.isArray(currentIpv4.dns) ? currentIpv4.dns[0] || '' : '';
      const currentMode = String(latestState?.eth?.mode || 'static').toLowerCase();
      const requestedDns = payload?.eth?.ipv4?.dns?.[0] || '';

      return payload.hostname !== String(latestState?.hostname || '')
        || payload.eth.mode !== currentMode
        || payload.eth.ipv4.address !== String(currentIpv4.address || '')
        || payload.eth.ipv4.prefix !== Number(currentIpv4.prefix || 24)
        || payload.eth.ipv4.gateway !== String(currentIpv4.gateway || '')
        || requestedDns !== currentDns;
    }

    function confirmNetworkConfigChange(payload) {
      if (!hasNetworkConfigChanged(payload)) return true;

      return window.confirm(
        'Changing the network configuration may disconnect this browser from the device.\n\n'
        + 'Before continuing, configure the Ethernet interface on the connected PC for the new network '
        + '(a compatible IP address and subnet mask).\n\n'
        + 'Apply the new network configuration?'
      );
    }

    function markPageStaleAfterIpSwitch() {
      pageStaleAfterIpSwitch = true;
      commitBtn.disabled = true;
      resetBtn.disabled = true;
    }

    function resetFormToLoadedState() {
      if (!latestState) {
        setStatus('No loaded network configuration available yet.', 'error');
        return;
      }

      applyStateToForm(latestState);
      setStatus('Reset form to the loaded configuration. Review values before Set.', 'success');
    }

    async function loadState() {
      if (!apiFacade?.fetchState) {
        setStatus('Network configuration API unavailable.', 'error');
        return;
      }

      setStatus('Loading current network configuration...', 'progress');
      try {
        const res = await apiFacade.fetchState();
        if (!res?.ok) throw new Error(res?.error || 'State query failed');

        applyStateToForm(res.data);
        const mode = (res.data?.eth?.mode || '').toUpperCase() || 'N/A';
        const ip = res.data?.eth?.ipv4?.address || 'n/a';
        const canEnabled = summarizeCanEnabled(res.data);
        setStatus(`Loaded current config: mode=${mode}, ip=${ip}, CAN enabled interfaces=${canEnabled}.`, 'success');
      } catch (err) {
        setStatus(`Failed to load state: ${err.message || err}`, 'error');
      }
    }

    async function applyConfig() {
      if (!apiFacade?.apply) {
        setStatus('Network configuration API unavailable.', 'error');
        return;
      }

      let payload;
      let requestedIp = '';
      try {
        payload = buildPayloadFromForm();
        requestedIp = payload?.eth?.mode === 'static' ? (payload?.eth?.ipv4?.address || '') : '';
      } catch (err) {
        setStatus(err.message || String(err), 'error');
        return;
      }

      if (!confirmNetworkConfigChange(payload)) {
        setStatus('Network configuration change canceled. No settings were applied.', 'info');
        return;
      }

      commitBtn.disabled = true;
      resetBtn.disabled = true;
      setStatus('Applying configuration: validating and writing system network settings...', 'progress');

      try {
        const prevIp = latestState?.eth?.current_ip || latestState?.eth?.ipv4?.address || '';
        const res = await apiFacade.apply(payload, { autoConfirm: true, timeoutSec: 0, requestTimeoutMs: 15000 });
        if (!res?.ok) throw new Error(res?.error || 'Apply failed');
        if (!res?.data?.state || typeof res.data.state !== 'object') {
          throw new Error('Apply response missing effective state.');
        }

        const appliedState = res?.data?.state || null;
        const newIp = appliedState?.eth?.ipv4?.address || '';
        const mode = (appliedState?.eth?.mode || 'n/a').toUpperCase();
        const warnings = Array.isArray(res?.data?.warnings) ? res.data.warnings : [];

        if (warnings.length) {
          setStatus(`Apply completed with warnings. mode=${mode}, effective ip=${newIp || 'n/a'}. ${warnings.join(' | ')}`, 'error');
        } else if (requestedIp && newIp && requestedIp !== newIp) {
          setStatus(`Apply request accepted but effective IP is still ${newIp} (requested ${requestedIp}). Please check backend logs and NM status.`, 'error');
        } else {
          setStatus(`Apply completed successfully. mode=${mode}, effective ip=${newIp || 'n/a'}.`, 'success');
        }

        const ipChanged = mode === 'STATIC'
          && typeof newIp === 'string'
          && newIp !== ''
          && prevIp
          && prevIp !== newIp;
        if (ipChanged) {
          markPageStaleAfterIpSwitch();
          setStatus(
            `Apply completed. IP changed from ${prevIp} to ${newIp}. `
            + `Current page may disconnect by design. Open ${buildReconnectUrl(newIp)}.`,
            'success'
          );
          return;
        }

        // Refresh displayed values to match effective state.
        await loadState();
      } catch (err) {
        const msg = err?.message || String(err);
        const prevIp = latestState?.eth?.current_ip || latestState?.eth?.ipv4?.address || '';
        const staticIpSwitch = payload?.eth?.mode === 'static'
          && requestedIp
          && prevIp
          && requestedIp !== prevIp;

        if (staticIpSwitch && /timeout|failed to fetch|networkerror|network error/i.test(msg)) {
          setStatus(
            `Network apply is in progress. Connection to old IP (${prevIp}) may drop by design during IP switch. `
            + `Checking new IP ${requestedIp}...`,
            'progress'
          );
          const reachable = await probeTargetHost(requestedIp);
          if (reachable) {
            markPageStaleAfterIpSwitch();
            setStatus(
              `IP switch appears successful. Device is reachable at ${requestedIp}. `
              + `Open ${buildReconnectUrl(requestedIp)}.`,
              'success'
            );
          } else {
            markPageStaleAfterIpSwitch();
            setStatus(
              `IP switch result is not confirmed yet because the old connection was interrupted. `
              + `Try ${buildReconnectUrl(requestedIp)} first. `
              + `If still unreachable, wait 20-30 seconds and try ${buildReconnectUrl(requestedIp)} again.`,
              'error'
            );
          }
        } else {
          setStatus(`Apply failed: ${msg}`, 'error');
        }
      } finally {
        commitBtn.disabled = pageStaleAfterIpSwitch;
        resetBtn.disabled = pageStaleAfterIpSwitch;
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

    resetBtn.addEventListener('click', resetFormToLoadedState);
    commitBtn.addEventListener('click', applyConfig);
    closeBtn.addEventListener('click', () => window.close());

    setStaticEnabled(true);
    wireNumericFields();
    loadState();
  });
})();
