const test = require('node:test');
const assert = require('node:assert/strict');

const rules = require('../../assets/services/sdaqCalibrationRules.js');

function channel(used, overrides = {}) {
  return {
    Used_Points: String(used),
    Points: {
      Point_0: {
        gain: '200',
        c2: '0',
        c3: '0',
        ...overrides,
      },
    },
  };
}

test('F-2 defines unity gain for 0-to-1 and reuses only a genuine one-point gain', () => {
  assert.deepEqual(rules.singlePointSource(channel(1)), { ok: true, gain: 200 });
  assert.equal(rules.singlePointSource(channel(1, { gain: '1.2345678' })).gain, Math.fround(1.2345678));
  assert.deepEqual(rules.singlePointSource(channel(0)), { ok: true, gain: 1 });
  assert.deepEqual(rules.singlePointSource(channel(0, { gain: '-nan', c2: '9' })), { ok: true, gain: 1 });
  assert.equal(rules.singlePointSource(channel(5)).ok, false);
});

test('F-2 rejects even a tiny non-zero polynomial term', () => {
  const result = rules.singlePointSource(channel(1, { c2: '1e-15' }));
  assert.equal(result.ok, false);
  assert.match(result.error, /polynomial/);
});

test('F-2 rejects NaN, infinity, and missing source gains', () => {
  for (const gain of ['-nan', 'NaN', 'Infinity', '']) {
    assert.equal(rules.singlePointSource(channel(1, { gain })).ok, false, gain);
  }
});

test('F-2 rejects NaN or missing polynomial coefficients in the source model', () => {
  assert.equal(rules.singlePointSource(channel(1, { c2: '-nan' })).ok, false);
  assert.equal(rules.singlePointSource(channel(1, { c3: '' })).ok, false);
});

test('F-1 treats every Scale as a calibration and warns before replacing an active table', () => {
  assert.match(rules.scaleCalibrationConfirmation('0', '1'), /new 2-point Scale table/);
  assert.match(rules.scaleCalibrationConfirmation('1', '1'), /active 1-point calibration/);
  assert.match(rules.scaleCalibrationConfirmation('8', '3'), /replace it with a new 2-point table/);
  assert.match(rules.scaleCalibrationConfirmation('8', '3'), /today's calibration date, and period 0/);
});

test('F-3 validates Point order after float32 conversion without sorting rows', () => {
  assert.deepEqual(rules.validateMeasureOrder(['-10', '0', '10']), { ok: true });
  assert.equal(rules.validateMeasureOrder(['0', '-1']).ok, false);
  assert.match(rules.validateMeasureOrder(['1', '1.00000001']).error, /after float32 conversion/);
  assert.match(rules.validateMeasureOrder(['0', '1e100']).error, /finite float32/);
});

test('F-7/F-8 validates Scale ranges using device float32 values', () => {
  assert.equal(rules.scaleRangeError('4', '20', '100', '0'), '');
  assert.match(rules.scaleRangeError('4', '4.00000001', '0', '100'), /measurement input range/);
  assert.match(rules.scaleRangeError('4', '20', '1', '1.00000001'), /engineering output range/);
  assert.match(rules.scaleRangeError('4', '20', '0', '1e100'), /finite float32/);
});

test('F-9 supplies an explicit warning for every one-point save', () => {
  assert.match(rules.singlePointWarning(), /disables.*Out-of-Calibrated-Range/i);
});

test('Web exposes no independent metadata-only editor or save action', () => {
  assert.deepEqual(rules.pointTableEditorPolicy('view'), {
    canEditDate: false,
    canEditPeriod: false,
    canSave: false,
    showSave: false,
  });
  assert.deepEqual(rules.pointTableEditorPolicy('auto-linear'), {
    canEditDate: false,
    canEditPeriod: true,
    canSave: true,
    showSave: true,
  });
});

test('metadata or formatting-only edits do not qualify as a point-table save', () => {
  const original = {
    Calibration_date: '2026/02/11',
    Calibration_Period: '1',
    Used_Points: '1',
    Unit: 'C',
    Points: {
      Point_0: {
        measure: '27.6599998',
        reference: '27.6599998',
        offset: '0',
        gain: '1',
        c2: '0',
        c3: '0',
      },
    },
  };
  const metadataOnly = JSON.parse(JSON.stringify(original));
  metadataOnly.Calibration_date = '2026/08/26';
  metadataOnly.Calibration_Period = '12';
  metadataOnly.Points.Point_0.measure = '27.66';
  assert.equal(rules.pointTableChanged(metadataOnly, original, 8), false);

  const changedPoint = JSON.parse(JSON.stringify(metadataOnly));
  changedPoint.Points.Point_0.reference = '28';
  assert.equal(rules.pointTableChanged(changedPoint, original, 8), true);

  const changedCount = JSON.parse(JSON.stringify(metadataOnly));
  changedCount.Used_Points = '0';
  assert.equal(rules.pointTableChanged(changedCount, original, 8), true);
});
