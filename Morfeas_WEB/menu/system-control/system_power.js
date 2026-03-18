(() => {
  const pageAction = document.body?.dataset?.action;
  if (!pageAction) return;

  const api = window.LOG_WEB?.api?.systemPower;
  const runBtn = document.getElementById('runBtn');
  const statusBox = document.getElementById('statusBox');

  if (!runBtn || !statusBox) return;

  const isReboot = pageAction === 'reboot';
  const actionName = isReboot ? 'reboot' : 'shutdown';

  const setStatus = (msg, tone = 'info') => {
    statusBox.textContent = msg;
    statusBox.className = `status ${tone}`;
  };

  const getReconnectUrl = () => {
    const host = window.location.hostname || 'device-ip';
    return `https://${host}/`;
  };

  const successMessage = () => {
    const url = getReconnectUrl();
    if (isReboot) {
      return [
        'Reboot command accepted.',
        `Wait about 60-90 seconds, then open ${url}.`,
        'If the page looks stale after reconnect, refresh browser (Ctrl+F5).',
      ].join(' ');
    }
    return [
      'Shutdown command accepted.',
      'Device will go offline. Power it on manually when needed.',
      `After power-on, open ${url} and refresh browser (Ctrl+F5).`,
    ].join(' ');
  };

  const confirmText = isReboot
    ? 'Confirm system reboot now? Existing web/SSH sessions will disconnect.'
    : 'Confirm system shutdown now? Device will go offline until manually powered on.';

  runBtn.addEventListener('click', async () => {
    if (!window.confirm(confirmText)) {
      setStatus('Canceled. No reboot/shutdown command was sent.', 'info');
      return;
    }

    runBtn.disabled = true;
    setStatus(`Sending ${actionName} command...`, 'progress');

    try {
      if (!api) throw new Error('System power API unavailable');
      if (isReboot) await api.reboot();
      else await api.shutdown();
      setStatus(successMessage(), 'success');
    } catch (err) {
      setStatus(`Failed to ${actionName}: ${err?.message || err}`, 'error');
      runBtn.disabled = false;
      return;
    }
  });
})();
