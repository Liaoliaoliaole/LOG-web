(() => {
  const root = window.LOG_WEB || (window.LOG_WEB = {});
  const config = root.config;

  if (!config) {
    console.warn('LOG_WEB config missing; NOX API may not resolve correctly.');
  }

  const endpoint = config?.endpoints?.nox || 'api_nox.php';
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
  root.api.nox = {
    fetchState: (bus) => fetchJson({ bus }, {
      headers: { Accept: 'application/json' },
    }),
    setHeater: (bus, address, enabled) => fetchJson(undefined, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ action: 'heater', bus, address, enabled }),
    }),
    setAutoOff: (bus, value) => fetchJson(undefined, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ action: 'auto_off', bus, value }),
    }),
  };
})();
