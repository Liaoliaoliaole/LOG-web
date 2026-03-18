(() => {
  const root = window.LOG_WEB || (window.LOG_WEB = {});
  const config = root.config;

  const endpoint = config?.endpoints?.systemPower || 'api_system_power.php';
  const buildUrl = () => config?.resolveApi?.(endpoint)
    || new URL(`/backend/${endpoint}`, window.location.origin).toString();

  const postAction = async (action, timeoutMs = 10000) => {
    const controller = new AbortController();
    const timer = Number.isFinite(timeoutMs) && timeoutMs > 0
      ? setTimeout(() => controller.abort(), timeoutMs)
      : null;

    let res;
    try {
      res = await fetch(buildUrl(), {
        method: 'POST',
        cache: 'no-store',
        signal: controller.signal,
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify({ action }),
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
    if (!res.ok || body?.ok === false) {
      throw new Error(body?.error || `HTTP ${res.status}`);
    }
    return body;
  };

  root.api = root.api || {};
  root.api.systemPower = {
    reboot: () => postAction('reboot'),
    shutdown: () => postAction('shutdown'),
  };
})();
