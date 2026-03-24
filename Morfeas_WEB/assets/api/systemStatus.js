(() => {
  const root = window.LOG_WEB || (window.LOG_WEB = {});
  const config = root.config;

  if (!config) {
    console.warn('LOG_WEB config missing; system status API may not resolve correctly.');
  }

  const endpoint = config?.endpoints?.systemStatus || 'api_system_status.php';
  const buildUrl = (params) => config?.resolveApi?.(endpoint, params)
    || new URL(`/backend/${endpoint}`, window.location.origin).toString();

  const fetchJson = async (params, options = {}) => {
    const res = await fetch(buildUrl(params), {
      cache: 'no-store',
      ...options,
    });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return res.json();
  };

  const buildLoggersExportUrl = (names = []) => {
    const base = new URL(buildUrl({ action: 'loggers_export' }), window.location.origin);
    names.forEach((name) => {
      const n = String(name || '').trim();
      if (n) base.searchParams.append('name', n);
    });
    return base.toString();
  };

  const systemStatusApi = {
    buildUrl,
    buildLoggersExportUrl,
    // Backward-compatible alias for older callers.
    buildLoggersZipUrl: buildLoggersExportUrl,
    fetchDetails: () => fetchJson({ action: 'details' }),
    fetchLoggers: () => fetchJson({ action: 'loggers' }),
    fetchLogger: (name, options = {}) => {
      const params = { action: 'logger', name };
      if (options.ifUpdated) params.if_updated = '1';
      if (Number.isFinite(options.mtime)) params.mtime = String(options.mtime);
      return fetchJson(params);
    },
    fetchJournal: (options = {}) => {
      const params = { action: 'journal' };
      if (Number.isFinite(options.lines)) params.lines = String(options.lines);
      if (typeof options.units === 'string' && options.units.trim() !== '') {
        params.units = options.units.trim();
      }
      return fetchJson(params);
    },
  };

  root.api = root.api || {};
  root.api.systemStatus = systemStatusApi;
})();
