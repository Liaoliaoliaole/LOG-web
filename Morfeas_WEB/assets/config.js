(() => {
  const root = window.LOG_WEB || (window.LOG_WEB = {});

  const rawBase = window.APP_BASE_PATH || window.ORIG_BASE_PATH || '';
  const basePath = String(rawBase).replace(/\/+$/, '');
  const apiBasePath = `${basePath}/backend`.replace(/\/{2,}/g, '/');

  const normalizePath = (path) => (path.startsWith('/') ? path : `/${path}`);

  const resolveApi = (endpoint, params) => {
    const path = `${apiBasePath}${normalizePath(endpoint)}`.replace(/\/{2,}/g, '/');
    const url = new URL(path, window.location.origin);
    if (params) {
      Object.entries(params).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== '') {
          url.searchParams.set(key, value);
        }
      });
    }
    return url.toString();
  };

  const resolvePath = (path) => {
    const fullPath = `${basePath}${normalizePath(path)}`.replace(/\/{2,}/g, '/');
    return new URL(fullPath, window.location.origin).toString();
  };

  const SESSION_STORAGE_KEY = 'morfeas_web_session_id';
  let memorySessionId = '';

  const generateSessionId = () => {
    if (window.crypto?.randomUUID) {
      return window.crypto.randomUUID().replace(/-/g, '');
    }

    const bytes = new Uint8Array(16);
    if (window.crypto?.getRandomValues) {
      window.crypto.getRandomValues(bytes);
      return Array.from(bytes, (value) => value.toString(16).padStart(2, '0')).join('');
    }

    return `${Date.now().toString(16)}${Math.random().toString(16).slice(2)}${Math.random().toString(16).slice(2)}`;
  };

  const getSessionId = () => {
    try {
      const existing = window.localStorage.getItem(SESSION_STORAGE_KEY);
      if (existing) {
        memorySessionId = existing;
        return existing;
      }
    } catch (_) {
      // fall back to in-memory storage
    }

    if (!memorySessionId) {
      memorySessionId = generateSessionId();
    }

    try {
      window.localStorage.setItem(SESSION_STORAGE_KEY, memorySessionId);
    } catch (_) {
      // ignore storage failures
    }

    return memorySessionId;
  };

  const applySessionHeaders = (headers = {}) => ({
    ...headers,
    'X-Morfeas-Session': getSessionId(),
  });

  root.config = {
    basePath,
    apiBasePath,
    endpoints: {
      channels: 'api_channels.php',
      devices: 'api_devices.php',
      canRoles: 'api_can_roles.php',
      canRoleTransition: 'api_can_role_transition.php',
      nox: 'api_nox.php',
      iobox: 'api_iobox.php',
      mti: 'api_mti.php',
      systemStatus: 'api_system_status.php',
      ftpBackup: 'api_ftp_backup.php',
      networkConfig: 'api_network_config.php',
      systemPower: 'api_system_power.php',
      systemUpdate: 'api_system_update.php',
    },
    resolveApi,
    resolvePath,
  };

  root.session = {
    storageKey: SESSION_STORAGE_KEY,
    getToken: getSessionId,
    applyHeaders: applySessionHeaders,
  };
})();
