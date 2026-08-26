const test = require('node:test');
const assert = require('node:assert/strict');

const requests = [];

global.window = {
  location: { origin: 'http://log.test' },
  LOG_WEB: {
    config: {
      endpoints: { channels: 'api_channels.php' },
      resolveApi: (_endpoint, params) => {
        const query = new URLSearchParams(params || {}).toString();
        return `http://log.test/backend/api_channels.php${query ? `?${query}` : ''}`;
      },
    },
  },
};

global.fetch = async (url, options) => {
  requests.push({ url, options });
  return {
    ok: true,
    json: async () => ({ ok: true }),
    text: async () => '',
  };
};

require('../../assets/api/channels.js');

const { channels } = global.window.LOG_WEB.api;

test('Local JSON restore commit sends an explicit false acknowledgement by default', async () => {
  requests.length = 0;

  await channels.restoreCommit('{"channels":[]}', 'digest-1');

  assert.equal(requests.length, 1);
  assert.match(requests[0].url, /include=restore_commit/);
  assert.deepEqual(JSON.parse(requests[0].options.body), {
    file_content: '{"channels":[]}',
    digest: 'digest-1',
    acknowledge_warnings: false,
  });
});

test('Local JSON restore commit sends acknowledgement only after explicit confirmation', async () => {
  requests.length = 0;

  await channels.restoreCommit('{"channels":[]}', 'digest-2', true);

  assert.equal(requests.length, 1);
  assert.deepEqual(JSON.parse(requests[0].options.body), {
    file_content: '{"channels":[]}',
    digest: 'digest-2',
    acknowledge_warnings: true,
  });
});
