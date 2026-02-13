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

  root.config = {
    basePath,
    apiBasePath,
    endpoints: {
      channels: 'api_channels.php',
      devices: 'api_devices.php',
      systemStatus: 'api_system_status.php',
    },
    resolveApi,
    resolvePath,
  };
})();
