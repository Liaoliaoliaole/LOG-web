(() => {
  const root = window.LOG_WEB || (window.LOG_WEB = {});
  const config = root.config;

  if (!config) {
    console.warn('LOG_WEB config missing; devices API may not resolve correctly.');
  }

  const endpoint = config?.endpoints?.devices || 'api_devices.php';
  const buildUrl = (params) => config?.resolveApi?.(endpoint, params)
    || new URL(`/backend/${endpoint}`, window.location.origin).toString();
  const applyHeaders = (headers = {}) => root.session?.applyHeaders ? root.session.applyHeaders(headers) : headers;

  const fetchJson = async (params, options = {}) => {
    const res = await fetch(buildUrl(params), {
      cache: 'no-store',
      ...options,
      headers: applyHeaders(options.headers || {}),
    });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return res.json();
  };

  const devicesApi = {
    buildUrl,
    fetchDevices: () => fetchJson(undefined, {
      headers: { Accept: 'application/json' },
    }),
    updateDevices: (payload, method = 'POST') => fetchJson(undefined, {
      method,
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(payload),
    }),
  };

  root.api = root.api || {};
  root.api.devices = devicesApi;
})();
