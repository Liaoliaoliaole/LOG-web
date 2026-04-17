(() => {
  const root = window.LOG_WEB || (window.LOG_WEB = {});
  const config = root.config;

  if (!config) {
    console.warn('LOG_WEB config missing; network config API may not resolve correctly.');
  }
  const applyHeaders = (headers = {}) => root.session?.applyHeaders ? root.session.applyHeaders(headers) : headers;

  const endpoint = config?.endpoints?.networkConfig || 'api_network_config.php';
  const buildUrl = (params) => config?.resolveApi?.(endpoint, params)
    || new URL(`/backend/${endpoint}`, window.location.origin).toString();

  const fetchJson = async (params, options = {}) => {
    const {
      timeoutMs = 20000,
      ...fetchOptions
    } = options || {};

    const controller = new AbortController();
    const timeout = Number.isFinite(timeoutMs) && timeoutMs > 0
      ? setTimeout(() => controller.abort(), Number(timeoutMs))
      : null;

    let res;
    try {
      res = await fetch(buildUrl(params), {
        cache: 'no-store',
        signal: controller.signal,
        ...fetchOptions,
        headers: applyHeaders(fetchOptions.headers || {}),
      });
    } catch (err) {
      if (err?.name === 'AbortError') {
        throw new Error('Request timeout');
      }
      throw err;
    } finally {
      if (timeout) clearTimeout(timeout);
    }

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

  const postAction = (action, payload = {}, requestOptions = {}) => fetchJson(undefined, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ action, ...payload }),
    ...requestOptions,
  });

  const networkConfigApi = {
    buildUrl,
    fetchState: () => fetchJson(undefined, {
      headers: { Accept: 'application/json' },
    }),
    apply: (payload, options = {}) => {
      let timeoutSec = 90;
      let autoConfirm = true;
      let requestTimeoutMs = 20000;

      if (typeof options === 'number') {
        timeoutSec = options;
        autoConfirm = false;
      } else if (options && typeof options === 'object') {
        if (Number.isFinite(options.timeoutSec)) {
          timeoutSec = Number(options.timeoutSec);
        }
        if (typeof options.autoConfirm === 'boolean') {
          autoConfirm = options.autoConfirm;
        }
        if (Number.isFinite(options.requestTimeoutMs)) {
          requestTimeoutMs = Number(options.requestTimeoutMs);
        }
      }

      return postAction('apply', {
        payload,
        timeout_sec: timeoutSec,
        auto_confirm: autoConfirm,
      }, {
        timeoutMs: requestTimeoutMs,
      });
    },
    confirm: (pendingId) => postAction('confirm', { pending_id: pendingId }),
    rollback: (pendingId) => postAction('rollback', pendingId ? { pending_id: pendingId } : {}),
  };

  root.api = root.api || {};
  root.api.networkConfig = networkConfigApi;
})();
