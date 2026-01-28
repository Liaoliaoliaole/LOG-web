(() => {
  const root = window.LOG_WEB || (window.LOG_WEB = {});
  const channelsApi = root.api?.channels;

  if (!channelsApi) {
    console.warn('Channels API missing; isoCatalog service may not resolve correctly.');
  }

  const ISO_STORAGE_KEY = 'iso_standard_selected_id';

  const isoStorage = {
    get() {
      try {
        return localStorage.getItem(ISO_STORAGE_KEY) || '';
      } catch (_) {
        return '';
      }
    },
    set(id) {
      try {
        if (id) localStorage.setItem(ISO_STORAGE_KEY, id);
        else localStorage.removeItem(ISO_STORAGE_KEY);
      } catch (_) {}
    },
  };

  const parseIsoXml = (xmlText) => {
    const parser = new DOMParser();
    const xml = parser.parseFromString(xmlText, 'application/xml');
    const points = xml.querySelector('points');
    if (!points) return null;

    const catalog = {};
    const list = [];

    points.childNodes.forEach((node) => {
      if (node.nodeType !== 1) return;
      const code = node.nodeName.trim();
      const read = (tag) => {
        const el = node.querySelector(tag);
        return el ? el.textContent.trim() : '';
      };
      const entry = {
        code,
        description: read('description'),
        unit: read('unit'),
        min: read('min'),
        max: read('max'),
        alarmHigh: read('alarmHigh'),
        alarmHighVal: read('alarmHighVal'),
        alarmLow: read('alarmLow'),
        alarmLowVal: read('alarmLowVal'),
      };
      catalog[code] = entry;
      list.push(entry);
    });

    return { catalog, list };
  };

  const chooseFile = (files) => {
    const stored = isoStorage.get();
    return files.find((f) => f.id === stored)
      || files.find((f) => f.is_default)
      || files[0];
  };

  const loadCatalog = async () => {
    if (!channelsApi) throw new Error('Channels API unavailable');

    const listPayload = await channelsApi.fetchIsoStandardList();
    const files = listPayload?.files || [];

    if (!files.length) {
      return {
        files: [],
        selectedFile: null,
        catalog: {},
        list: [],
      };
    }

    const selectedFile = chooseFile(files);
    if (selectedFile?.id && selectedFile.id !== isoStorage.get()) {
      isoStorage.set(selectedFile.id);
    }

    const xmlText = await channelsApi.fetchIsoStandard(selectedFile?.id);
    const parsed = parseIsoXml(xmlText);

    return {
      files,
      selectedFile,
      catalog: parsed?.catalog || {},
      list: parsed?.list || [],
    };
  };

  root.services = root.services || {};
  root.services.isoCatalog = {
    loadCatalog,
    parseIsoXml,
    storage: isoStorage,
  };
})();
