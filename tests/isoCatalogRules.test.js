const test = require('node:test');
const assert = require('node:assert/strict');

global.window = {
  LOG_WEB: {
    api: { channels: {} },
  },
};

require('../Morfeas_WEB/assets/services/isoCatalog.js');

const { rules } = global.window.LOG_WEB.services.isoCatalog;

function entry(code, description, overrides = {}) {
  return {
    code: `_${code}`,
    description,
    unit: 'C',
    min: '0',
    max: '1000',
    alarmHigh: 'no',
    alarmHighVal: '1000',
    alarmLow: 'no',
    alarmLowVal: '0',
    ...overrides,
  };
}

function catalog(...entries) {
  return Object.fromEntries(entries.map((item) => [item.code, item]));
}

test('lookupEntry accepts an optional leading underscore and remains case-sensitive', () => {
  const isoCatalog = catalog(entry('TEMP1', 'Temperature 1'));

  assert.equal(rules.lookupEntry(isoCatalog, 'TEMP1'), isoCatalog._TEMP1);
  assert.equal(rules.lookupEntry(isoCatalog, '_TEMP1'), isoCatalog._TEMP1);
  assert.equal(rules.lookupEntry(isoCatalog, 'temp1'), null);
});

test('resolves every base ISO before any CYL postfix is applied', () => {
  const isoCatalog = catalog(
    entry('TEMP1', 'Temperature 1'),
    entry('TEMP2', 'Temperature 2'),
    entry('TEMP3', 'Temperature 3')
  );

  const result = rules.resolveSequentialEntries(isoCatalog, 'TEMP1', 3);

  assert.equal(result.ok, true);
  assert.deepEqual(result.items.map((item) => item.code), ['TEMP1', 'TEMP2', 'TEMP3']);
  assert.equal(result.items.some((item) => item.code.endsWith('_5')), false);
});

test('preserves minimum numeric width without adding a zero after expansion', () => {
  const isoCatalog = catalog(
    entry('TEMP099', 'Temperature 99'),
    entry('TEMP100', 'Temperature 100'),
    entry('TEMP101', 'Temperature 101')
  );

  const result = rules.resolveSequentialEntries(isoCatalog, 'TEMP099', 3);

  assert.equal(result.ok, true);
  assert.deepEqual(result.items.map((item) => item.code), ['TEMP099', 'TEMP100', 'TEMP101']);
});

test('rejects a batch code without a trailing number', () => {
  const result = rules.resolveSequentialEntries({}, 'TE1041A', 2);

  assert.equal(result.ok, false);
  assert.equal(result.error.code, 'iso_sequence_requires_digits');
  assert.equal(
    result.error.message,
    'Batch range requires an ISO code ending with digits. Codes like TE1041A are not supported for Range > 1.'
  );
});

test('reports the first missing ISO code', () => {
  const isoCatalog = catalog(
    entry('TE70216', 'Liner Temp 16'),
    entry('TE70218', 'Liner Temp 18')
  );

  const result = rules.resolveSequentialEntries(isoCatalog, 'TE70216', 3);

  assert.equal(result.ok, false);
  assert.equal(result.error.code, 'iso_sequence_missing_code');
  assert.equal(result.error.message, 'Missing ISO code: TE70217. No channels were created.');
});

test('rejects an empty ISO description because category cannot be determined', () => {
  const isoCatalog = catalog(
    entry('TEMP1', ''),
    entry('TEMP2', 'Temperature 2')
  );

  const result = rules.resolveSequentialEntries(isoCatalog, 'TEMP1', 2);

  assert.equal(result.ok, false);
  assert.equal(result.error.code, 'iso_sequence_category_unknown');
  assert.equal(
    result.error.message,
    'Cannot determine measurement category because ISO description is empty. No channels were created.'
  );
});

test('allows a numeric code boundary while the measurement category stays the same', () => {
  const isoCatalog = catalog(
    entry('TE70299', 'Liner Temp 99'),
    entry('TE70300', 'Liner Temp 100')
  );

  const result = rules.resolveSequentialEntries(isoCatalog, 'TE70299', 2);

  assert.equal(result.ok, true);
  assert.deepEqual(result.items.map((item) => item.category.label), ['Liner Temp', 'Liner Temp']);
});

test('rejects a range at the first measurement category change', () => {
  const isoCatalog = catalog(
    entry('TE70299', 'Liner Temp 99'),
    entry('TE70300', 'Liner Temp 100'),
    entry('TE70301', 'Cylinder Head Temp 1')
  );

  const result = rules.resolveSequentialEntries(isoCatalog, 'TE70299', 3);

  assert.equal(result.ok, false);
  assert.equal(result.error.code, 'iso_sequence_category_crossed');
  assert.equal(
    result.error.message,
    'ISO range crosses measurement category: TE70300 is "Liner Temp", but TE70301 is "Cylinder Head Temp". No channels were created.'
  );
});
