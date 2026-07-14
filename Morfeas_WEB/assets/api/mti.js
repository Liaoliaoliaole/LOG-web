(() => {
  const root = window.LOG_WEB || (window.LOG_WEB = {});
  const config = root.config;

  if (!config) {
    console.warn('LOG_WEB config missing; MTI API may not resolve correctly.');
  }

  const endpoint = config?.endpoints?.mti || 'api_mti.php';
  const applyHeaders = (headers = {}) => root.session?.applyHeaders ? root.session.applyHeaders(headers) : headers;
  const buildUrl = (params) => config?.resolveApi?.(endpoint, params)
    || new URL(`/backend/${endpoint}`, window.location.origin).toString();

  const fetchJson = async (params, options = {}) => {
    const res = await fetch(buildUrl(params), {
      cache: 'no-store',
      ...options,
      headers: applyHeaders(options.headers || {}),
    });
    if (!res.ok) {
      let details = '';
      try {
        const body = await res.json();
        details = body?.error ? `: ${body.error}` : '';
      } catch (_) {
        // ignore parse failures
      }
      throw new Error(`HTTP ${res.status}${details}`);
    }
    return res.json();
  };

  root.api = root.api || {};
  root.api.mti = {
    fetchDevices: () => fetchJson(undefined, {
      headers: { Accept: 'application/json' },
    }),
    fetchState: (name) => fetchJson({ name }, {
      headers: { Accept: 'application/json' },
    }),
    setConfig: (name, payload) => fetchJson(undefined, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ action: 'config', name, ...payload }),
    }),
    setGlobalPower: (name, enabled) => fetchJson(undefined, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ action: 'global_power', name, enabled }),
    }),
    setTeleSwitch: (name, payload) => fetchJson(undefined, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ action: 'tele_switch', name, ...payload }),
    }),
    setPwmConfig: (name, pwmGensConfig) => fetchJson(undefined, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ action: 'pwm_config', name, pwm_gens_config: pwmGensConfig }),
    }),
  };
})();
