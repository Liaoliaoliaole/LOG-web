(() => {
  const root = window.LOG_WEB || (window.LOG_WEB = {});
  const channelsApi = root.api?.channels;

  if (!channelsApi) {
    console.warn('Channels API missing; isoCatalog service may not resolve correctly.');
  }

  const ISO_STORAGE_KEY = 'iso_standard_selected_id';

  const normalizeLookupCode = (codeRaw) => {
    const code = String(codeRaw || '').trim();
    if (!code) return '';
    return code.startsWith('_') ? code : `_${code}`;
  };

  const displayCode = (codeRaw) => String(codeRaw || '').replace(/^_/, '');

  const lookupEntry = (catalog, codeRaw) => {
    const code = normalizeLookupCode(codeRaw);
    return code ? (catalog?.[code] || null) : null;
  };

  const measurementCategory = (entry) => {
    const description = String(entry?.description || '').trim().replace(/\s+/g, ' ');
    if (!description) return null;

    const label = description.replace(/\s*\d+$/, '').trim();
    if (!label) return null;
    return { label, key: label.toLowerCase() };
  };

  /*
   * Batch naming rules come from "Rules for how to name channels in
   * Laboratory measurement systems" (Document ID: DMTA00018532).
   * Range === 1 does not require ISOstandard membership. Range > 1 must be
   * rejected unless every generated base ISO exists and remains in the same
   * measurement category. A CYL postfix is applied only after this validation.
   */
  const resolveSequentialEntries = (catalog, baseCodeRaw, range) => {
    const baseCode = String(baseCodeRaw || '').trim();
    const count = Number(range);
    const match = baseCode.match(/^(.*?)(\d+)$/);

    if (!match) {
      return {
        ok: false,
        error: {
          code: 'iso_sequence_requires_digits',
          message: 'Batch range requires an ISO code ending with digits. Codes like TE1041A are not supported for Range > 1.',
        },
      };
    }

    if (!Number.isInteger(count) || count <= 1) {
      return {
        ok: false,
        error: {
          code: 'iso_sequence_invalid_range',
          message: 'Batch ISO validation requires Range > 1.',
        },
      };
    }

    const prefix = match[1];
    const numericWidth = match[2].length;
    const firstNumber = BigInt(match[2]);
    const items = [];
    let previous = null;

    for (let offset = 0; offset < count; offset += 1) {
      const numericPart = String(firstNumber + BigInt(offset)).padStart(numericWidth, '0');
      const code = `${prefix}${numericPart}`;
      const entry = lookupEntry(catalog, code);

      if (!entry) {
        return {
          ok: false,
          error: {
            code: 'iso_sequence_missing_code',
            message: `Missing ISO code: ${displayCode(code)}. No channels were created.`,
            isoCode: displayCode(code),
          },
        };
      }

      const category = measurementCategory(entry);
      if (!category) {
        return {
          ok: false,
          error: {
            code: 'iso_sequence_category_unknown',
            message: 'Cannot determine measurement category because ISO description is empty. No channels were created.',
            isoCode: displayCode(code),
          },
        };
      }

      const item = { code, entry, category };
      if (previous && previous.category.key !== category.key) {
        return {
          ok: false,
          error: {
            code: 'iso_sequence_category_crossed',
            message: `ISO range crosses measurement category: ${displayCode(previous.code)} is "${previous.category.label}", but ${displayCode(code)} is "${category.label}". No channels were created.`,
            previousIsoCode: displayCode(previous.code),
            isoCode: displayCode(code),
            previousCategory: previous.category.label,
            category: category.label,
          },
        };
      }

      items.push(item);
      previous = item;
    }

    return { ok: true, items };
  };

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
    rules: {
      lookupEntry,
      measurementCategory,
      resolveSequentialEntries,
    },
  };
})();
