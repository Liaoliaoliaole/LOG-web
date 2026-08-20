(() => {
  const root = window.LOG_WEB || (window.LOG_WEB = {});
  const config = root.config;
  const applyHeaders = (headers = {}) => root.session?.applyHeaders ? root.session.applyHeaders(headers) : headers;

  const endpoint = config?.endpoints?.ftpBackup || 'api_ftp_backup.php';
  const buildUrl = (params) => config?.resolveApi?.(endpoint, params)
    || new URL(`/backend/${endpoint}`, window.location.origin).toString();

  const requestJson = async (method, body = null, params = null, timeoutMs = 20000) => {
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
        headers: applyHeaders({
          Accept: 'application/json',
          ...(body ? { 'Content-Type': 'application/json' } : {}),
        }),
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

  const ftpBackupApi = {
    buildUrl,
    configIfUpdated: (timeoutMs = 10000) =>
      requestJson('GET', null, { action: 'config_if_updated' }, timeoutMs),
    saveConfig: (host, dir, timeoutMs = 15000) =>
      requestJson('POST', { action: 'saveConfig', host, dir }, null, timeoutMs),
    testConnect: (timeoutMs = 15000) =>
      requestJson('POST', { action: 'testConnect' }, null, timeoutMs),
    clearConfig: (timeoutMs = 15000) =>
      requestJson('POST', { action: 'clearConfig' }, null, timeoutMs),
    list: (timeoutMs = 30000) =>
      requestJson('POST', { action: 'list' }, null, timeoutMs),
    listDirs: (host, path = '/', timeoutMs = 15000) =>
      requestJson('POST', { action: 'listDirs', host, path }, null, timeoutMs),
    backup: (timeoutMs = 120000) =>
      requestJson('POST', { action: 'backup' }, null, timeoutMs),
    restorePreflight: (file, timeoutMs = 120000) =>
      requestJson('POST', { action: 'restore_preflight', file }, null, timeoutMs),
    restoreCommit: (file, digest, acknowledgeWarnings = false, timeoutMs = 120000) =>
      requestJson('POST', { action: 'restore_commit', file, digest, acknowledge_warnings: acknowledgeWarnings }, null, timeoutMs),
    uploadLog: (timeoutMs = 60000) =>
      requestJson('POST', { action: 'uploadLog' }, null, timeoutMs),
  };

  root.api = root.api || {};
  root.api.ftpBackup = ftpBackupApi;
})();
