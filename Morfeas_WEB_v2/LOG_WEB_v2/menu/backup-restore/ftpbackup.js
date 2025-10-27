/* ============================================================================
 * System Backup & Restore (FTP) — Static Prototype
 * ----------------------------------------------------------------------------
 * Purpose  : Visual prototype only (no backend). Simulates connect/backup/restore.
 * Features :
 *   - Connect & Fetch: validates inputs, marks connected, populates mock files.
 *   - Backup Now     : adds a mock archive and refreshes the list.
 *   - Restore        : requires a selection; reports success.
 * Notes    : Replace mocked parts with real API calls in integration.
 * ========================================================================== */

(function () {
  /* ------------------------------------------
   * Shorthand DOM helpers
   * ------------------------------------------ */
  const $  = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

  /* ------------------------------------------
   * Elements
   * ------------------------------------------ */
  const hostInput   = $('#ftpHost');
  const engineInput = $('#engineNo');

  const connectBtn    = $('#connectBtn');
  const disconnectBtn = $('#disconnectBtn');
  const backupBtn     = $('#backupBtn');
  const restoreBtn    = $('#restoreBtn');

  const list   = $('#backupList');
  const ftpMsg = $('#ftpStatus');
  const bakMsg = $('#backupStatus');
  const resMsg = $('#restoreStatus');

  /* ------------------------------------------
   * State
   * ------------------------------------------ */
  let connected = false;

  /* ------------------------------------------
   * UI helpers
   * ------------------------------------------ */
  function setMsg(el, msg, type) {
    el.textContent = msg || '';
    el.classList.remove('ok', 'err');
    if (type) el.classList.add(type);
  }

  function setConnectedUI(on) {
    connected = !!on;

    // Enable/disable inputs & actions
    hostInput.disabled   = connected;
    engineInput.disabled = connected;

    connectBtn.disabled    = connected;
    disconnectBtn.disabled = !connected;

    backupBtn.disabled  = !connected;
    restoreBtn.disabled = !connected;

    // Visual: grey background when disabled
    [hostInput, engineInput].forEach(el => {
      el.style.background = el.disabled ? 'var(--bg-weak)' : '';
    });
  }

  /* ------------------------------------------
   * Mock data
   * ------------------------------------------ */
  function mockFiles() {
    const eng = (engineInput.value || 'engine').replace(/\s+/g, '');
    const now = new Date();
    const pad = n => String(n).padStart(2, '0');
    const stamp = `${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}-${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`;
    return [
      `${eng}-full-${stamp}.zip`,
      `${eng}-daily-20251018.zip`,
      `${eng}-weekly-20251012.zip`
    ];
  }

  function fillList(files) {
    list.innerHTML = '';
    files.forEach(f => {
      const opt = document.createElement('option');
      opt.value = f;
      opt.textContent = f;
      list.appendChild(opt);
    });
  }

  /* ------------------------------------------
   * Events
   * ------------------------------------------ */
  connectBtn.addEventListener('click', () => {
    const host = hostInput.value.trim();
    const eng  = engineInput.value.trim();

    if (!host || !eng) {
      setMsg(ftpMsg, 'Please enter both Host IP and Engine Number.', 'err');
      return;
    }

    setMsg(ftpMsg, 'Connecting to FTP server…');
    // Simulate async connect
    setTimeout(() => {
      setConnectedUI(true);
      setMsg(ftpMsg, `Connected. Engine directory: "${eng}".`, 'ok');
      fillList(mockFiles());
      setMsg(resMsg, `Found ${list.options.length} backup file(s) in "${eng}".`, 'ok');
    }, 400);
  });

  disconnectBtn.addEventListener('click', () => {
    setConnectedUI(false);
    fillList([]);
    setMsg(ftpMsg, 'Disconnected. Configuration cleared.', 'ok');
    setMsg(bakMsg, '');
    setMsg(resMsg, '');
  });

  backupBtn.addEventListener('click', () => {
    if (!connected) return;

    setMsg(bakMsg, 'Creating and uploading backup…');
    setTimeout(() => {
      // Replace list with a fresh set (top item simulates a new backup)
      fillList(mockFiles());
      setMsg(bakMsg, 'Backup complete.', 'ok');
      setMsg(resMsg, `Found ${list.options.length} backup file(s).`, 'ok');
    }, 600);
  });

  restoreBtn.addEventListener('click', () => {
    if (!connected) return;

    const file = list.value;
    if (!file) {
      alert('Please select a backup file.');
      return;
    }

    setMsg(resMsg, `Restoring "${file}"…`);
    setTimeout(() => {
      setMsg(resMsg, `Restored "${file}" successfully.`, 'ok');
    }, 600);
  });

  /* ------------------------------------------
   * Init
   * ------------------------------------------ */
  setConnectedUI(false);
})();
