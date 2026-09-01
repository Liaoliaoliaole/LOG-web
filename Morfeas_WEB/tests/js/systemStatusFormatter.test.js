const test = require('node:test');
const assert = require('node:assert/strict');

global.window = { LOG_WEB: {} };
require('../../assets/ui/systemStatusFormatter.js');

const formatter = global.window.LOG_WEB.ui.systemStatusFormatter;

test('formats a detected SDAQ clock correction for System Status', () => {
  const timeRow = {
    name: 'SDAQnet_(can1)_last_clock_step_UNIX',
    value: 1700000000,
  };
  const deltaRow = {
    name: 'SDAQnet_(can1)_last_clock_step_delta_sec',
    value: -7200,
  };

  assert.equal(formatter.formatLabel(timeRow), 'Last Clock Correction');
  assert.equal(formatter.formatLabel(deltaRow), 'Clock Correction Delta');
  assert.equal(formatter.formatValue(deltaRow), '-7200s');
});
