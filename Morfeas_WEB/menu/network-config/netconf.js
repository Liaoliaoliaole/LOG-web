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
        const {
          timeoutMs = 20000,
          ...fetchOpts
        } = opts || {};

        const controller = new AbortController();
        const timer = Number.isFinite(timeoutMs) && timeoutMs > 0
          ? setTimeout(() => controller.abort(), Number(timeoutMs))
          : null;

        let res;
        try {
          res = await fetch(endpoint, {
            cache: 'no-store',
            signal: controller.signal,
            ...fetchOpts,
          });
        } catch (err) {
          if (err?.name === 'AbortError') {
            throw new Error('Request timeout');
          }
          throw err;
        } finally {
          if (timer) clearTimeout(timer);
        }

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
          apply: (payload, options = { autoConfirm: true, timeoutSec: 0 }) => wrapped.apply(payload, options),
        };
      }

      return {
        fetchState: () => fetchJson({ method: 'GET', headers: { Accept: 'application/json' } }),
        apply: (payload, options = { autoConfirm: true, timeoutSec: 0 }) => fetchJson({
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          timeoutMs: Number.isFinite(options?.requestTimeoutMs) ? Number(options.requestTimeoutMs) : 15000,
          body: JSON.stringify({
            action: 'apply',
            payload,
            timeout_sec: Number.isFinite(options?.timeoutSec) ? Number(options.timeoutSec) : 0,
            auto_confirm: options?.autoConfirm !== false,
          }),
        }),
      };
    })();

    let latestState = null;

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
        setStatus('No previously saved payload in this browser.', 'error');
        return;
      }

      let payload;
      try {
        payload = JSON.parse(raw);
      } catch (_) {
        setStatus('Saved payload JSON is invalid.', 'error');
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
      setStatus('Loaded last saved payload.');
    }

    async function loadState() {
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
      let payload;
      let requestedIp = '';
      try {
        payload = buildPayloadFromForm();
        requestedIp = payload?.eth?.mode === 'static' ? (payload?.eth?.ipv4?.address || '') : '';
      } catch (err) {
        setStatus(err.message || String(err), 'error');
        return;
      }

      commitBtn.disabled = true;
      setStatus('Applying configuration: validating and writing system network settings...', 'progress');

      try {
        const prevIp = latestState?.eth?.current_ip || latestState?.eth?.ipv4?.address || '';
        const res = await apiFacade.apply(payload, { autoConfirm: true, timeoutSec: 0, requestTimeoutMs: 15000 });
        if (!res?.ok) throw new Error(res?.error || 'Apply failed');
        if (!res?.data?.state || typeof res.data.state !== 'object') {
          throw new Error('Apply response missing effective state.');
        }

        localStorage.setItem(STORAGE_LAST, JSON.stringify(payload));

        const appliedState = res?.data?.state || null;
        const newIp = appliedState?.eth?.ipv4?.address || '';
        const mode = (appliedState?.eth?.mode || 'n/a').toUpperCase();
        const warnings = Array.isArray(res?.data?.warnings) ? res.data.warnings : [];
        const requestedIp = payload?.eth?.mode === 'static' ? (payload?.eth?.ipv4?.address || '') : '';

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
          setStatus(`Apply completed. IP changed from ${prevIp} to ${newIp}. Reconnect web at https://${newIp}/`, 'success');
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
          setStatus(`Apply request channel interrupted. Checking new IP ${requestedIp}...`, 'progress');
          const reachable = await probeTargetHost(requestedIp);
          if (reachable) {
            setStatus(`Network likely applied. Device is reachable at ${requestedIp}. Reconnect web at https://${requestedIp}/`, 'success');
          } else {
            setStatus(`Apply response interrupted during IP switch. Try https://${requestedIp}/ first; if unreachable, reconnect old IP ${prevIp} and retry.`, 'error');
          }
        } else {
          setStatus(`Apply failed: ${msg}`, 'error');
        }
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
