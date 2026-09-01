(() => {
  const root = window.LOG_WEB || (window.LOG_WEB = {});

  const prettyLabels = {
    BUS_Utilization: 'Bus Utilization',
    BUS_Error_Rate: 'Bus Error Rate',
    Detected_SDAQs: 'Detected SDAQs',
    Incomplete_SDAQs: 'Incomplete SDAQs',
  };

  const formatPercent = (value, digits = 2) => {
    if (typeof value !== 'number' || Number.isNaN(value)) return value;
    const abs = Math.abs(value);
    const dp = abs >= 100 ? 0 : abs >= 10 ? Math.min(1, digits) : digits;
    return `${value.toFixed(dp)}%`;
  };

  const formatShuntTempC = (value) => {
    if (typeof value !== 'number' || Number.isNaN(value)) return value;
    return `${value.toFixed(1)}°C`;
  };

  const formatCpuTempC = (value) => {
    if (typeof value !== 'number' || Number.isNaN(value)) return value;
    return `${value.toFixed(1)}°C`;
  };

  const formatDuration = (seconds) => {
    const sec = Math.max(0, Math.floor(Number(seconds) || 0));
    const h = Math.floor(sec / 3600);
    const m = Math.floor((sec % 3600) / 60);
    const s = sec % 60;
    return `${h}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
  };

  const formatValue = (row) => {
    if (!row) return '';

    if (row.name?.includes('last_calibration_UNIX')) {
      return row.value ? new Date(row.value * 1000).toLocaleDateString() : 'UnCalibrated';
    }

    if (row.name?.includes('last_clock_step_UNIX')) {
      return row.value ? new Date(row.value * 1000).toLocaleString() : '—';
    }

    if (row.name?.includes('last_clock_step_delta_sec')) {
      const seconds = Number(row.value);
      return Number.isFinite(seconds) ? `${seconds >= 0 ? '+' : ''}${seconds}s` : '—';
    }

    if (row.name === 'BUS_Utilization') {
      return formatPercent(row.value, 1);
    }

    if (/_Util$/i.test(row.name || '') && typeof row.value === 'number') {
      return `${row.value.toFixed(1)}%`;
    }

    if (row.name === 'BUS_Error_Rate') {
      const percent = typeof row.value === 'number' ? row.value * 100 : row.value;
      return formatPercent(percent, 4);
    }

    if (row.name === 'CPU_temp') {
      return formatCpuTempC(row.value);
    }

    if (/^SDAQnet_\(.+\)_ShuntTemp$/i.test(row.name || '')) {
      return formatShuntTempC(row.value);
    }

    if (row.name === 'Up_time' && typeof row.value === 'number') {
      return formatDuration(row.value);
    }

    if (row.name === 'Detected_SDAQs' || row.name === 'Incomplete_SDAQs') {
      const value = typeof row.value === 'number' ? Math.round(row.value) : row.value;
      return `${value}`;
    }

    if (typeof row.value === 'number') {
      const abs = Math.abs(row.value);
      const digits = abs >= 100 ? 0 : abs >= 10 ? 1 : 2;
      return `${row.value.toFixed(digits)}${row.unit || ''}`;
    }

    return `${row.value}${row.unit || ''}`;
  };

  const formatTickerValue = (name, value, unit = '') => {
    if (value === null || value === undefined || value === '') {
      return '—';
    }

    const numeric = typeof value === 'number' ? value : Number(value);
    const hasNumber = Number.isFinite(numeric);

    if (name === 'CPU_temp' && hasNumber) {
      return `${numeric.toFixed(1)}°C`;
    }
    if (name === 'CPU_Util' && hasNumber) {
      return `${numeric.toFixed(2)}%`;
    }
    if (name === 'RAM_Util' && hasNumber) {
      return `${numeric.toFixed(2)}%`;
    }
    if (name === 'Disk_Util' && hasNumber) {
      return `${numeric.toFixed(1)}%`;
    }
    if (name === 'Up_time' && hasNumber) {
      return formatDuration(numeric);
    }

    if (hasNumber) {
      const abs = Math.abs(numeric);
      const digits = abs >= 100 ? 0 : abs >= 10 ? 1 : 2;
      return `${numeric.toFixed(digits)}${unit || ''}`;
    }

    return `${value}${unit || ''}`;
  };

  const formatLabel = (row) => {
    if (!row?.name) return '';
    if (prettyLabels[row.name]) return prettyLabels[row.name];
    if (/^SDAQnet_\(.+\)_outVoltage$/i.test(row.name)) return 'Bus Voltage';
    if (/^SDAQnet_\(.+\)_outAmperage$/i.test(row.name)) return 'Bus Amperage';
    if (/^SDAQnet_\(.+\)_ShuntTemp$/i.test(row.name)) return 'Shunt Temperature';
    if (/^SDAQnet_\(.+\)_last_calibration_UNIX$/i.test(row.name)) return 'Last SDAQ Net Power Calibration';
    if (/^SDAQnet_\(.+\)_last_clock_step_UNIX$/i.test(row.name)) return 'Last Clock Correction';
    if (/^SDAQnet_\(.+\)_last_clock_step_delta_sec$/i.test(row.name)) return 'Clock Correction Delta';
    return row.name.replace('_UNIX', '');
  };

  const formatRow = (row) => ({
    label: formatLabel(row),
    value: formatValue(row),
  });

  root.ui = root.ui || {};
  root.ui.systemStatusFormatter = {
    formatRow,
    formatValue,
    formatLabel,
    formatDuration,
    formatTickerValue,
  };
})();
