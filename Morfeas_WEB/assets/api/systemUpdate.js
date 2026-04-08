(() => {
  const root = window.LOG_WEB || (window.LOG_WEB = {});
  const config = root.config;

  const endpoint = config?.endpoints?.systemUpdate || 'api_system_update.php';
  const buildUrl = (params) => config?.resolveApi?.(endpoint, params)
    || new URL(`/backend/${endpoint}`, window.location.origin).toString();

  const requestJson = async (method, body = null, params = null, timeoutMs = 15000) => {
    const controller = new AbortController();
    const timer = Number.isFinite(timeoutMs) && timeoutMs > 0
      ? setTimeout(() => controller.abort(), timeoutMs)
      : null;

    let res;
    try {
      res = await fetch(buildUrl(params), {
        method,
        cache: 'no-store',
        signal: controller.signal,
        headers: {
          Accept: 'application/json',
          ...(body ? { 'Content-Type': 'application/json' } : {}),
        },
        body: body ? JSON.stringify(body) : undefined,
      });
    } catch (err) {
      if (err?.name === 'AbortError') {
        throw new Error('Request timeout');
      }
      throw err;
    } finally {
      if (timer) clearTimeout(timer);
    }

    const payload = await res.json().catch(() => ({}));
    if (!res.ok || payload?.ok === false) {
      const error = new Error(payload?.error || payload?.message || `HTTP ${res.status}`);
      error.payload = payload;
      error.status = res.status;
      throw error;
    }
    return payload;
  };

  const systemUpdateApi = {
    buildUrl,
    status: (timeoutMs = 8000) => requestJson('GET', null, { action: 'status' }, timeoutMs),
    check: (timeoutMs = 120000) => requestJson('POST', { action: 'check' }, null, timeoutMs),
    update: (timeoutMs = 900000) => requestJson('POST', { action: 'update' }, null, timeoutMs),
  };

  root.api = root.api || {};
  root.api.systemUpdate = systemUpdateApi;
})();
