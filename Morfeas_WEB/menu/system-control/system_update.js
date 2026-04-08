(() => {
  const api = window.LOG_WEB?.api?.systemUpdate;
  const menuBtn = document.getElementById('menuBtn');
  const mainMenu = document.getElementById('mainMenu');

  const checkBtn = document.getElementById('checkBtn');
  const updateNowBtn = document.getElementById('updateNowBtn');
  const updateLaterBtn = document.getElementById('updateLaterBtn');
  const reloadBtn = document.getElementById('reloadBtn');
  const statusBox = document.getElementById('statusBox');
  const progressWrap = document.getElementById('progressWrap');
  const progressFill = document.getElementById('progressFill');
  const progressMeta = document.getElementById('progressMeta');

  if (!api || !checkBtn || !updateNowBtn || !updateLaterBtn || !reloadBtn || !statusBox) {
    return;
  }

  menuBtn?.addEventListener('click', (e) => {
    e.stopPropagation();
    if (!mainMenu) return;
    mainMenu.classList.toggle('open');
  });

  document.addEventListener('click', (e) => {
    if (!mainMenu || !menuBtn) return;
    if (!mainMenu.contains(e.target) && e.target !== menuBtn) {
      mainMenu.classList.remove('open');
    }
  });

  const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

  let progressTimer = null;
  let progressStartAt = 0;
  let progressPercent = 0;
  let progressStageIndex = 0;
  const progressStages = [
    { atSec: 0, label: 'Submitting update command to backend...' },
    { atSec: 8, label: 'Checking repositories and preparing update...' },
    { atSec: 25, label: 'Applying updates and running post-deploy steps...' },
    { atSec: 60, label: 'Building core / restarting services / health checks...' },
  ];

  const setStatus = (message, tone = 'info') => {
    statusBox.textContent = message;
    statusBox.className = `status ${tone}`;
  };

  const updateProgressUI = (percent, elapsedSec, label) => {
    if (!progressWrap || !progressFill || !progressMeta) return;
    progressWrap.hidden = false;
    progressFill.style.width = `${Math.max(0, Math.min(100, percent))}%`;
    progressMeta.textContent = `Elapsed: ${elapsedSec}s · ${label}`;
  };

  const stopProgress = (finalPercent = null, label = null) => {
    if (progressTimer) {
      clearInterval(progressTimer);
      progressTimer = null;
    }
    if (!progressWrap || !progressFill || !progressMeta) return;
    if (Number.isFinite(finalPercent)) {
      progressFill.style.width = `${Math.max(0, Math.min(100, finalPercent))}%`;
    } else {
      progressWrap.hidden = true;
      progressFill.style.width = '0%';
    }
    if (label) {
      const elapsedSec = Math.max(0, Math.floor((Date.now() - progressStartAt) / 1000));
      progressMeta.textContent = `Elapsed: ${elapsedSec}s · ${label}`;
    }
  };

  const startProgress = () => {
    stopProgress();
    progressStartAt = Date.now();
    progressPercent = 4;
    progressStageIndex = 0;
    updateProgressUI(progressPercent, 0, progressStages[0].label);
    progressTimer = setInterval(() => {
      const elapsedSec = Math.max(0, Math.floor((Date.now() - progressStartAt) / 1000));
      const nextStage = progressStages[progressStageIndex + 1];
      if (nextStage && elapsedSec >= nextStage.atSec) {
        progressStageIndex += 1;
        setStatus(`${progressStages[progressStageIndex].label}\nPlease keep this window open.`, 'progress');
      }
      const target = Math.min(95, 5 + Math.floor(elapsedSec * 0.35));
      if (target > progressPercent) progressPercent = target;
      updateProgressUI(progressPercent, elapsedSec, progressStages[progressStageIndex].label);
    }, 1000);
  };

  const setBusy = (busy) => {
    checkBtn.disabled = busy;
    updateNowBtn.disabled = busy;
    updateLaterBtn.disabled = busy;
    reloadBtn.disabled = busy;
  };

  const setUpdateActionsVisible = (visible) => {
    updateNowBtn.hidden = !visible;
    updateLaterBtn.hidden = !visible;
  };

  const setReloadVisible = (visible) => {
    reloadBtn.hidden = !visible;
  };

  const extractErrorMessage = (err, fallback) => {
    const fromPayload = err?.payload?.error || err?.payload?.message;
    if (fromPayload) return String(fromPayload);
    if (err?.message) return String(err.message);
    return fallback;
  };

  const isExpectedReconnectDrop = (err) => {
    const msg = String(err?.message || '').toLowerCase();
    return msg.includes('failed to fetch')
      || msg.includes('networkerror')
      || msg.includes('network request failed')
      || msg.includes('request timeout')
      || msg.includes('load failed');
  };

  const probeRecovery = async () => {
    const deadline = Date.now() + 120000;
    while (Date.now() < deadline) {
      await sleep(2000);
      try {
        await api.status(5000);
        return true;
      } catch (_) {
        // keep waiting
      }
    }
    return false;
  };

  const notifyShellUpdateStatusChanged = () => {
    try {
      if (window.opener && !window.opener.closed) {
        window.opener.postMessage({ type: 'system-update-status-changed' }, '*');
      }
    } catch (_) {
      // ignore cross-window notification failures
    }
  };

  const applyCheckResult = (payload) => {
    const result = payload?.data?.result;
    const updateNeeded = Boolean(payload?.data?.update_needed);

    if (result === 'update_available' || updateNeeded) {
      setStatus('Update available.\nClick "Update Now" to apply, or "Update Later" to postpone.', 'warning');
      setUpdateActionsVisible(true);
      return;
    }

    setStatus('System is up to date.', 'success');
    setUpdateActionsVisible(false);
  };

  const runCheck = async () => {
    setBusy(true);
    stopProgress();
    setReloadVisible(false);
    setUpdateActionsVisible(false);
    setStatus('Checking for updates...', 'progress');

    try {
      const payload = await api.check(120000);
      applyCheckResult(payload);
      notifyShellUpdateStatusChanged();
    } catch (err) {
      setStatus(`Update check failed: ${extractErrorMessage(err, 'Unknown error')}`, 'error');
    } finally {
      setBusy(false);
    }
  };

  const runUpdate = async () => {
    const ok = window.confirm('Apply system update now? Browser connection may drop while services restart.');
    if (!ok) {
      setStatus('Canceled. No update command was sent.', 'info');
      return;
    }

    setBusy(true);
    startProgress();
    setReloadVisible(false);
    setUpdateActionsVisible(false);
    setStatus('Applying update. Do not close this window.\nWaiting for update script response...', 'progress');

    try {
      const payload = await api.update(900000);
      const result = payload?.data?.result;
      if (result === 'network_unreachable' || result === 'update_failed' || payload?.ok === false) {
        stopProgress(100, 'Update failed');
        setStatus(`System update failed: ${payload?.error || payload?.message || 'Unknown failure'}`, 'error');
        setBusy(false);
        return;
      }
    } catch (err) {
      if (!isExpectedReconnectDrop(err)) {
        stopProgress(100, 'Update failed');
        setStatus(`System update failed: ${extractErrorMessage(err, 'Unknown error')}`, 'error');
        setBusy(false);
        return;
      }
    }

    updateProgressUI(97, Math.max(0, Math.floor((Date.now() - progressStartAt) / 1000)), 'Waiting for services to reconnect...');
    setStatus('Update command sent.\nServices may restart now; waiting for reconnect...', 'progress');

    const recovered = await probeRecovery();
    if (!recovered) {
      stopProgress(100, 'Reconnect timeout');
      setStatus(
        'Update was triggered, but service did not recover within 120 seconds.\nOpen device web again and refresh (Ctrl+F5).',
        'warning'
      );
      setReloadVisible(true);
      setBusy(false);
      return;
    }

    try {
      const statusPayload = await api.status(10000);
      const updateNeeded = Boolean(statusPayload?.data?.update_needed);
      notifyShellUpdateStatusChanged();
      if (updateNeeded) {
        stopProgress(100, 'Completed with pending update flag');
        setStatus(
          'Device is reachable again, but update flag is still present.\nPlease run "Check Again" before final confirmation.',
          'warning'
        );
        setUpdateActionsVisible(true);
      } else {
        stopProgress(100, 'Update completed');
        setStatus(
          'Update flow completed and web service is reachable.\nRefresh page (Ctrl+F5) to load latest files.',
          'success'
        );
      }
    } catch (_) {
      stopProgress(100, 'Device reachable');
      setStatus('Device is reachable again.\nRefresh page (Ctrl+F5) to load latest files.', 'success');
    }

    setReloadVisible(true);
    setBusy(false);
  };

  checkBtn.addEventListener('click', runCheck);
  updateNowBtn.addEventListener('click', runUpdate);
  updateLaterBtn.addEventListener('click', () => {
    setStatus('Update deferred. No changes were applied.', 'info');
    setUpdateActionsVisible(false);
    setTimeout(() => {
      if (window.opener && !window.opener.closed) {
        window.close();
      }
    }, 300);
  });
  reloadBtn.addEventListener('click', () => {
    window.location.reload();
  });

  runCheck();
})();
