/* =============================================================================
 * Restore Channels (JSON) -- review UI for Local JSON Restore.
 * - Upload a file, run a read-only server-side preflight, show every row's
 *   result. Only when every row is Ready/No-change/Update-metadata can the
 *   restore be confirmed.
 * - Review state (the parsed rows and the digest) lives only in this
 *   window; closing it or navigating away discards it. The server holds no
 *   Pending/staging state -- Confirm re-sends the same file content and the
 *   digest, and the backend re-validates everything fresh before writing.
 * ========================================================================== */

(() => {
  const $ = (s, r = document) => r.querySelector(s);

  const fileInput = $('#fileInput');
  const btnRunPreflight = $('#btnRunPreflight');
  const btnConfirm = $('#btnConfirm');
  const btnDownloadReport = $('#btnDownloadReport');
  const btnCancel = $('#btnCancel');
  const statusBar = $('#status');
  const summaryRow = $('#summaryRow');
  const reportHint = $('#reportHint');
  const tableWrap = $('#tableWrap');
  const reportBody = $('#reportBody');

  const channelsApi = window.LOG_WEB?.api?.channels;

  const state = {
    fileContent: null,
    fileName: null,
    preflight: null, // { rows, summary, can_commit, digest }
  };

  function setStatus(msg, tone = 'info') {
    statusBar.textContent = msg;
    statusBar.style.color = tone === 'error' ? '#e11d48' : (tone === 'success' ? '#0f7b3f' : 'inherit');
  }

  function resultRowClass(result) {
    switch (result) {
      case 'Ready to restore': return 'result-ready';
      case 'No change': return 'result-nochange';
      case 'Update metadata': return 'result-update';
      case 'Conflict with current config': return 'result-conflict';
      default: return 'result-invalid';
    }
  }

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
  }

  function renderReport(preflight) {
    const { rows, summary, can_commit: canCommit } = preflight;

    summaryRow.classList.remove('hidden');
    reportHint.classList.remove('hidden');
    tableWrap.classList.remove('hidden');
    btnDownloadReport.classList.remove('hidden');
    btnConfirm.classList.remove('hidden');

    $('#pillReady').textContent = `Ready to restore: ${summary.ready_to_restore}`;
    $('#pillNoChange').textContent = `No change: ${summary.no_change}`;
    $('#pillUpdate').textContent = `Update metadata: ${summary.update_metadata}`;
    $('#pillInvalid').textContent = `Invalid: ${summary.invalid}`;
    $('#pillConflict').textContent = `Conflict: ${summary.conflict}`;

    reportBody.innerHTML = rows.map((r) => {
      const ignored = Array.isArray(r.ignored_fields) && r.ignored_fields.length
        ? `Ignored: ${r.ignored_fields.join(', ')} (SDAQ runtime-owned)`
        : '';
      const detail = [r.reason || '', ignored].filter(Boolean).join('; ');
      return `
      <tr class="${resultRowClass(r.result)}">
        <td>${r.row}</td>
        <td>${escapeHtml(r.iso_channel)}</td>
        <td>${escapeHtml(r.interface_type)}</td>
        <td>${escapeHtml(r.anchor)}</td>
        <td>${escapeHtml(r.canonical_anchor || '')}</td>
        <td class="result">${escapeHtml(r.result)}</td>
        <td class="reason">${escapeHtml(detail)}</td>
      </tr>
    `;
    }).join('');

    btnConfirm.disabled = !canCommit;

    if (canCommit) {
      setStatus(`Preflight passed: ${summary.ready_to_restore} to add, ${summary.update_metadata} to update, ${summary.no_change} unchanged. Review the table, then Confirm Restore.`, 'success');
    } else {
      setStatus(`Preflight found ${summary.invalid} invalid and ${summary.conflict} conflicting row(s). Fix or remove them in the source file and re-run preflight -- this file cannot be restored as-is.`, 'error');
    }
  }

  async function runPreflight() {
    if (!state.fileContent) return;
    if (!channelsApi?.restorePreflight) {
      setStatus('Channels API unavailable', 'error');
      return;
    }

    btnRunPreflight.disabled = true;
    btnConfirm.classList.add('hidden');
    setStatus('Running preflight...');

    try {
      const json = await channelsApi.restorePreflight(state.fileContent);
      if (json && json.ok === false) {
        throw new Error(json.error || 'Preflight failed');
      }
      state.preflight = json.data;
      renderReport(state.preflight);
    } catch (err) {
      console.error(err);
      setStatus(err?.payload?.error || err?.message || 'Preflight failed', 'error');
      state.preflight = null;
    } finally {
      btnRunPreflight.disabled = false;
    }
  }

  async function confirmRestore() {
    if (!state.preflight || !state.preflight.can_commit) return;
    if (!channelsApi?.restoreCommit) {
      setStatus('Channels API unavailable', 'error');
      return;
    }

    const summary = state.preflight.summary;
    const confirmed = window.confirm(
      `This will add ${summary.ready_to_restore} channel(s) and update ${summary.update_metadata} channel(s). ` +
      `This action writes to the live configuration. Continue?`
    );
    if (!confirmed) return;

    btnConfirm.disabled = true;
    btnRunPreflight.disabled = true;
    setStatus('Committing restore...');

    try {
      const json = await channelsApi.restoreCommit(state.fileContent, state.preflight.digest);
      if (json && json.ok === false) {
        throw new Error(json.error || 'Restore failed');
      }
      const { added, updated, unchanged } = json.data;
      setStatus(`Restore complete: ${added} added, ${updated} updated, ${unchanged} unchanged.`, 'success');
      if (window.opener && !window.opener.closed) {
        try {
          window.opener.postMessage({ type: 'channel-added' }, '*');
        } catch (_) { }
      }
      setTimeout(() => window.close(), 900);
    } catch (err) {
      console.error(err);
      const code = err?.payload?.code;
      if (code === 'restore_candidate_changed') {
        setStatus('The configuration changed since this file was reviewed. Re-run preflight and try again.', 'error');
      } else {
        setStatus(err?.payload?.error || err?.message || 'Restore failed', 'error');
      }
      btnRunPreflight.disabled = false;
    }
  }

  function downloadReport() {
    if (!state.preflight) return;
    const payload = JSON.stringify(state.preflight.rows, null, '\t');
    const blob = new Blob([payload], { type: 'application/json;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = (state.fileName || 'restore').replace(/\.json$/i, '') + '_report.json';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  }

  fileInput.addEventListener('change', async () => {
    const file = fileInput.files?.[0];
    summaryRow.classList.add('hidden');
    reportHint.classList.add('hidden');
    tableWrap.classList.add('hidden');
    btnDownloadReport.classList.add('hidden');
    btnConfirm.classList.add('hidden');
    state.preflight = null;

    if (!file) {
      state.fileContent = null;
      btnRunPreflight.disabled = true;
      setStatus('Select a JSON file to begin.');
      return;
    }

    try {
      state.fileContent = await file.text();
      state.fileName = file.name;
      btnRunPreflight.disabled = false;
      setStatus(`Loaded ${file.name}. Click Run Preflight.`);
    } catch (err) {
      setStatus('Failed to read file: ' + (err?.message || err), 'error');
      btnRunPreflight.disabled = true;
    }
  });

  btnRunPreflight.addEventListener('click', () => { runPreflight(); });
  btnConfirm.addEventListener('click', () => { confirmRestore(); });
  btnDownloadReport.addEventListener('click', downloadReport);
  btnCancel.addEventListener('click', () => { window.close(); });
})();
