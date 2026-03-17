(() => {
  const root = window.LOG_WEB || (window.LOG_WEB = {});
  const config = root.config;

  if (!config) {
    console.warn('LOG_WEB config missing; network config API may not resolve correctly.');
  }

  const endpoint = config?.endpoints?.networkConfig || 'api_network_config.php';
  const buildUrl = (params) => config?.resolveApi?.(endpoint, params)
    || new URL(`/backend/${endpoint}`, window.location.origin).toString();

  const fetchJson = async (params, options = {}) => {
    const res = await fetch(buildUrl(params), {
      cache: 'no-store',
      ...options,
    });
    if (!res.ok) {
      let details = '';
      try {
        const body = await res.json();
        details = body?.error ? `: ${body.error}` : '';
      } catch (_) {
        // ignore parse errors
      }
      throw new Error(`HTTP ${res.status}${details}`);
    }
    return res.json();
  };

  const postAction = (action, payload = {}) => fetchJson(undefined, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ action, ...payload }),
  });

  const networkConfigApi = {
    buildUrl,
    fetchState: () => fetchJson(undefined, {
      headers: { Accept: 'application/json' },
    }),
    apply: (payload, timeoutSec = 90) => postAction('apply', {
      payload,
      timeout_sec: timeoutSec,
    }),
    confirm: (pendingId) => postAction('confirm', { pending_id: pendingId }),
    rollback: (pendingId) => postAction('rollback', pendingId ? { pending_id: pendingId } : {}),
  };

  root.api = root.api || {};
  root.api.networkConfig = networkConfigApi;
})();
