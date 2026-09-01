(function initSdaqCalibrationRules(root, factory) {
  const rules = factory();

  if (typeof module === 'object' && module.exports) {
    module.exports = rules;
  }

  if (root && root.window === root) {
    const app = root.LOG_WEB || (root.LOG_WEB = {});
    app.services = app.services || {};
    app.services.sdaqCalibrationRules = rules;
  }
}(typeof globalThis !== 'undefined' ? globalThis : this, function createSdaqCalibrationRules() {
  function finiteNumber(value) {
    const raw = String(value ?? '').trim();
    if (raw === '' || /^[+-]?nan$/i.test(raw)) return null;
    const number = Number(raw);
    return Number.isFinite(number) ? number : null;
  }

  function finiteFloat32(value) {
    const number = finiteNumber(value);
    if (number === null) return null;
    const float32 = Math.fround(number);
    return Number.isFinite(float32) ? float32 : null;
  }

  function singlePointSource(channel) {
    const usedRaw = String(channel?.Used_Points ?? '').trim();
    const used = /^\d+$/.test(usedRaw) ? Number(usedRaw) : -1;
    if (used === 0) {
      return { ok: true, gain: 1 };
    }
    if (used !== 1) {
      return {
        ok: false,
        error: `Single-point calibration cannot reduce the current ${Math.max(used, 0)}-point table to one because that would discard segments and copy an unrelated slope.`,
      };
    }

    const point = channel?.Points?.Point_0 || {};
    const c2 = finiteNumber(point.c2);
    const c3 = finiteNumber(point.c3);
    if (c2 === null || c3 === null) {
      return {
        ok: false,
        error: 'Single-point calibration requires finite zero C2/C3 values from an existing linear one-point table.',
      };
    }
    if (c2 !== 0 || c3 !== 0) {
      return {
        ok: false,
        error: 'Single-point calibration cannot inherit a polynomial source. Use at least two points.',
      };
    }

    const gain = finiteFloat32(point.gain);
    if (gain === null) {
      return {
        ok: false,
        error: 'Single-point calibration requires a finite gain from an existing one-point table.',
      };
    }

    return { ok: true, gain };
  }

  function scaleCalibrationConfirmation(usedPoints, channelNumber = '', calDate = '') {
    const raw = String(usedPoints ?? '').trim();
    const used = /^\d+$/.test(raw) ? Number(raw) : -1;
    const channel = String(channelNumber ?? '').trim();
    const date = String(calDate ?? '').trim() || 'the browser date';
    const prefix = channel === '' ? 'This channel' : `CH${channel}`;
    if (used > 0) {
      return `${prefix} already has an active ${used}-point calibration. This Scale will replace it with a new 2-point nominal range table dated ${date}; period remains 0, so this channel will not show a next calibration due date. Continue calibration?`;
    }
    return `${prefix} will receive a new 2-point nominal range table dated ${date}; period remains 0, so this channel will not show a next calibration due date. Continue calibration?`;
  }

  function validateMeasureOrder(values) {
    let previous = null;
    for (let index = 0; index < values.length; index++) {
      const current = finiteFloat32(values[index]);
      if (current === null) {
        return {
          ok: false,
          index,
          error: `Point_${index} Uncalibrated value must be representable as finite float32.`,
        };
      }
      if (previous !== null && current <= previous) {
        return {
          ok: false,
          index,
          error: `Point_${index} Uncalibrated value must be greater than Point_${index - 1} after float32 conversion.`,
        };
      }
      previous = current;
    }
    return { ok: true };
  }

  function scaleRangeError(rawLow, rawHigh, engLow, engHigh) {
    const lowRaw = finiteFloat32(rawLow);
    const highRaw = finiteFloat32(rawHigh);
    const lowEngineering = finiteFloat32(engLow);
    const highEngineering = finiteFloat32(engHigh);
    if ([lowRaw, highRaw, lowEngineering, highEngineering].some((value) => value === null)) {
      return 'Scale values must be representable as finite float32.';
    }
    if (highRaw <= lowRaw) {
      return 'Invalid measurement input range: high must be greater than low after float32 conversion.';
    }
    if (highEngineering === lowEngineering) {
      return 'Invalid engineering output range: high and low must differ after float32 conversion.';
    }
    return '';
  }

  function singlePointWarning() {
    return 'A one-point calibration disables the SDAQ Out-of-Calibrated-Range supervision because there is no bounded calibrated interval. Continue with offset-only calibration?';
  }

  function pointTableEditorPolicy(editorMode) {
    const pointTableActive = editorMode === 'auto-linear';
    return {
      canEditDate: false,
      canEditPeriod: pointTableActive,
      canSave: pointTableActive,
      showSave: pointTableActive,
    };
  }

  function pointTableChanged(current, original, maxPoints = 256) {
    const parseUsed = (channel) => {
      const raw = String(channel?.Used_Points ?? '').trim();
      if (!/^\d+$/.test(raw)) return null;
      const used = Number(raw);
      return Number.isSafeInteger(used) && used <= maxPoints ? used : null;
    };
    const currentUsed = parseUsed(current);
    const originalUsed = parseUsed(original);
    if (currentUsed === null || originalUsed === null) return true;
    if (currentUsed !== originalUsed) return true;
    if (String(current?.Unit ?? '').trim() !== String(original?.Unit ?? '').trim()) return true;

    const fields = ['measure', 'reference', 'offset', 'gain', 'c2', 'c3'];
    const float32Value = (value) => {
      const number = finiteNumber(value);
      if (number === null) return `invalid:${String(value ?? '').trim()}`;
      return Math.fround(number);
    };

    for (let i = 0; i < currentUsed; i++) {
      const currentPoint = current?.Points?.[`Point_${i}`] || {};
      const originalPoint = original?.Points?.[`Point_${i}`] || {};
      for (const field of fields) {
        if (float32Value(currentPoint[field]) !== float32Value(originalPoint[field])) {
          return true;
        }
      }
    }
    return false;
  }

  return {
    finiteNumber,
    finiteFloat32,
    singlePointSource,
    scaleCalibrationConfirmation,
    validateMeasureOrder,
    scaleRangeError,
    singlePointWarning,
    pointTableEditorPolicy,
    pointTableChanged,
  };
}));
