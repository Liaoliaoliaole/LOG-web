const test = require('node:test');
const assert = require('node:assert/strict');

const requests = [];

global.window = {
  location: { origin: 'http://log.test' },
  LOG_WEB: {
    config: {
      endpoints: { ftpBackup: 'api_ftp_backup.php' },
      resolveApi: (_endpoint, params) => {
        const query = new URLSearchParams(params || {}).toString();
        return `http://log.test/backend/api_ftp_backup.php${query ? `?${query}` : ''}`;
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

require('../../assets/api/ftpBackup.js');

const { ftpBackup } = global.window.LOG_WEB.api;

test('FTP restore preflight sends the filename', async () => {
  requests.length = 0;

  await ftpBackup.restorePreflight('backup-1.mbl');

  assert.equal(requests.length, 1);
  assert.deepEqual(JSON.parse(requests[0].options.body), {
    action: 'restore_preflight',
    file: 'backup-1.mbl',
  });
});

test('FTP restore commit sends both digests and an explicit false acknowledgement by default', async () => {
  requests.length = 0;

  await ftpBackup.restoreCommit('backup-1.mbl', 'remote-digest-1', 'local-digest-1');

  assert.equal(requests.length, 1);
  assert.deepEqual(JSON.parse(requests[0].options.body), {
    action: 'restore_commit',
    file: 'backup-1.mbl',
    digest: 'remote-digest-1',
    local_config_digest: 'local-digest-1',
    acknowledge_warnings: false,
  });
});

test('FTP restore commit sends acknowledgement only after explicit confirmation', async () => {
  requests.length = 0;

  await ftpBackup.restoreCommit('backup-1.mbl', 'remote-digest-2', 'local-digest-2', true);

  assert.equal(requests.length, 1);
  assert.deepEqual(JSON.parse(requests[0].options.body), {
    action: 'restore_commit',
    file: 'backup-1.mbl',
    digest: 'remote-digest-2',
    local_config_digest: 'local-digest-2',
    acknowledge_warnings: true,
  });
});
