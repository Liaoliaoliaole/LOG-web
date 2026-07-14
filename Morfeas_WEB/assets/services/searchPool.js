(() => {
  const root = window.LOG_WEB || (window.LOG_WEB = {});
  const channelsApi = root.api?.channels;

  if (!channelsApi) {
    console.warn('Channels API missing; searchPool service may not resolve correctly.');
  }

  const loadSearchPool = async () => {
    if (!channelsApi) throw new Error('Channels API unavailable');
    const json = await channelsApi.fetchSearchPool();
    return json?.extras?.search_pool || {};
  };

  root.services = root.services || {};
  root.services.searchPool = { loadSearchPool };
})();
