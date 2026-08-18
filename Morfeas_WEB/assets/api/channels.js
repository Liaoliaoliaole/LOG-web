(() => {
  const root = window.LOG_WEB || (window.LOG_WEB = {});
  const config = root.config;

  if (!config) {
    console.warn('LOG_WEB config missing; channels API may not resolve correctly.');
  }

  const endpoint = config?.endpoints?.channels || 'api_channels.php';
  const buildUrl = (params) => config?.resolveApi?.(endpoint, params)
    || new URL(`/backend/${endpoint}`, window.location.origin).toString();
  const applyHeaders = (headers = {}) => root.session?.applyHeaders ? root.session.applyHeaders(headers) : headers;

  const readJsonSafe = async (res) => {
    try {
      return await res.json();
    } catch (_) {
      return null;
    }
  };

  const fetchJson = async (params, options = {}) => {
    const res = await fetch(buildUrl(params), {
      cache: 'no-store',
      ...options,
      headers: applyHeaders(options.headers || {}),
    });

    const payload = await readJsonSafe(res);
    if (!res.ok) {
      const err = new Error((payload && payload.error) || `HTTP ${res.status}`);
      err.status = res.status;
      err.payload = payload;
      throw err;
    }

    return payload;
  };

  const fetchText = async (params, options = {}) => {
    const res = await fetch(buildUrl(params), {
      cache: 'no-store',
      ...options,
      headers: applyHeaders(options.headers || {}),
    });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return res.text();
  };

  const channelsApi = {
    buildUrl,
    fetchChannels: (options = {}) => fetchJson(undefined, {
      headers: { Accept: 'application/json' },
      ...options,
    }),
    fetchChannel: (iso, options = {}) => fetchJson({ iso }, {
      headers: { Accept: 'application/json' },
      ...options,
    }),
    createChannel: (payload) => fetchJson(undefined, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(payload),
    }),
    createChannelsBatch: (items) => fetchJson({ include: 'range_add' }, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ items }),
    }),
    updateChannel: (iso, payload) => fetchJson({ iso }, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(payload),
    }),
    deleteChannel: (iso) => fetchJson({ iso }, {
      method: 'DELETE',
      headers: { Accept: 'application/json' },
    }),
    fetchIsoStandardList: () => fetchJson({ include: 'iso_standard_list' }),
    fetchIsoStandard: (fileId) => fetchText({
      include: 'iso_standard',
      ...(fileId ? { file: fileId } : {}),
    }),
    fetchSearchPool: () => fetchJson({ include: 'pool' }, {
      headers: { Accept: 'application/json' },
    }),
    fetchMachineInfo: () => fetchJson({ include: 'machine_info' }),
    uploadIsoStandard: (file) => {
      const formData = new FormData();
      formData.append('file', file);
      return fetchJson({ include: 'iso_standard_upload' }, {
        method: 'POST',
        body: formData,
      });
    },
    fetchTc16Candidates: (sourceIso) => fetchJson({ include: 'tc16_candidates', source_iso: sourceIso }, {
      headers: { Accept: 'application/json' },
    }),
    applyTc16Replace: (payload) => fetchJson({ include: 'tc16_replace' }, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(payload),
    }),
  };

  root.api = root.api || {};
  root.api.channels = channelsApi;
})();
