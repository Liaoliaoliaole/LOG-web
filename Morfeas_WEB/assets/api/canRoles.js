(() => {
  const root = window.LOG_WEB || (window.LOG_WEB = {});
  const config = root.config;

  if (!config) {
    console.warn('LOG_WEB config missing; CAN roles API may not resolve correctly.');
  }

  const rolesEndpoint = config?.endpoints?.canRoles || 'api_can_roles.php';
  const transitionEndpoint = config?.endpoints?.canRoleTransition || 'api_can_role_transition.php';
  const applyHeaders = (headers = {}) => root.session?.applyHeaders ? root.session.applyHeaders(headers) : headers;

  const buildUrl = (endpoint, params) => config?.resolveApi?.(endpoint, params)
    || new URL(`/backend/${endpoint}`, window.location.origin).toString();

  const fetchJson = async (endpoint, params, options = {}) => {
    const res = await fetch(buildUrl(endpoint, params), {
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

  const canRolesApi = {
    fetchRoles: () => fetchJson(rolesEndpoint, undefined, {
      headers: { Accept: 'application/json' },
    }),
    transition: (action, bus) => fetchJson(transitionEndpoint, undefined, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ action, bus }),
    }),
  };

  root.api = root.api || {};
  root.api.canRoles = canRolesApi;
})();
