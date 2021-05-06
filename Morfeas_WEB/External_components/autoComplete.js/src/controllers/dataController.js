import searchEngine from "../services/search";

/**
 * Find matches to "query"
 *
 * @param {Object} config - Search engine configurations
 * @param {String} query - User's search query string
 *
 * @return {Array} - Matches
 */
export default (config, query) => {
  const { data, searchEngine: customSearchEngine } = config;

  const results = [];

  // Find matches from data source
  data.store.forEach((record, index) => {
    const search = (key) => {
      const recordValue = (key ? record[key] : record).toString();

      const match =
        typeof customSearchEngine === "function"
          ? customSearchEngine(query, recordValue)
          : searchEngine(query, recordValue, config);

      if (!match) return;

      let result = {
        index,
        match,
        value: record,
      };

      if (key) result.key = key;

      results.push(result);
    };

    if (data.key) {
      for (const key of data.key) {
        search(key);
      }
    } else {
      search();
    }
  });

  return results;
};
