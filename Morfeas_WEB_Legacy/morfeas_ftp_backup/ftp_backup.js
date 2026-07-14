"use strict";

const api = "../morfeas_php/ftp_api.php";

function connectFTP() {
  const host = document.getElementById("ftp-host-ip").value.trim();
  const dir = document.getElementById("ftp-engine-number").value.trim();

  if (!host || !dir) {
    showError("ftp-status", "Please enter both Host IP and Engine Number.");
    return;
  }

  const data = {
    host: host,
    dir: dir,
    action: "saveConfig" // backend reads FTP_USER, FTP_PASS, log_name (hostname) itself
  };

  postData({ ...data, action: "saveConfig" }, "ftp-status", "Saving FTP configuration...", (saveResp) => {
    if (!saveResp.success) {
      showError("ftp-status", `Failed to save configuration: ${saveResp.error}`);
      return;
    }

    postData({ action: "testConnect" }, "ftp-status", "Connecting to FTP server...", (testResp) => {
      if (testResp.success) {
        lastKnownDir = dir;
        showSuccess("ftp-status", `Connected to FTP at ${host}. Configuration saved.`);
        listBackups();
      } else {
        showError("ftp-status", "Connection failed: " + testResp.error);
      }
    });
  });
}

function disconnectUI() {
  showSuccess("ftp-status", "Disconnected. Configuration removed. Automatic backups disabled.");

  ["backup-status", "restore-status"].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.textContent = "";
  });

  document.getElementById("backup-list").innerHTML = "";
}


function disconnectFTP() {
  postData({ action: "clearConfig" }, "ftp-status", "Disconnecting...", (resp) => {
    if (resp.success) {
      disconnectUI();
    } else {
      showError("ftp-status", `Disconnect failed: ${resp.error}`);
    }
  });
}

function listBackups() {
  console.log("listBackups() triggered due to config change");

  const list = document.getElementById("backup-list");
  const previouslySelected = list.value;

  postData({ action: "list" }, "restore-status", "Loading backups...", (resp) => {
    list.innerHTML = "";

    if (resp.success === false) {
      showError("restore-status", resp.error);
      return;
    }

    let selectedRestored = false;
    (resp || []).forEach(file => {
      const opt = document.createElement("option");
      opt.value = file;
      opt.text = file;
      if (file === previouslySelected) {
        opt.selected = true;
        selectedRestored = true;
      }
      list.appendChild(opt);
    });

    const dir = document.getElementById("ftp-engine-number").value.trim();

    if ((resp || []).length === 0) {
      showSuccess("restore-status", `No backup files found in engine number directory: "${dir}".`);
    } else {
      const msg = `Found ${resp.length} backup file(s) in engine number directory: "${dir}".`;
      if (!selectedRestored && previouslySelected) {
        showSuccess("restore-status", msg + ` (Previously selected backup "${previouslySelected}" no longer exists)`);
      } else {
        showSuccess("restore-status", msg);
      }
    }
  });
}

function backupToFTP() {
  postData({ action: "backup" }, "backup-status", "Creating and uploading backup...", (resp) => {
    if (resp.success) {
      showSuccess("backup-status", resp.message || "Backup complete");
      listBackups();
    } else {
      showError("backup-status", "Backup failed: " + resp.error);
    }
  });
}

function restoreSelected() {
  const file = document.getElementById("backup-list").value;
  if (!file) {
    alert("Please select a backup file.");
    return;
  }

  postData({ action: "restore", file }, "restore-status", "Restoring backup...", (resp) => {
    if (resp.success) {
      showSuccess("restore-status", resp.message || "Restored successfully");
    } else {
      showError("restore-status", "Restore failed: " + resp.error);
    }
  });
}

function postData(data, statusId, loadingMsg, callback) {
  const statusBox = document.getElementById(statusId);
  statusBox.textContent = loadingMsg;
  statusBox.style.color = "black";

  fetch(api, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(data)
  })
  .then(async res => {
    const text = await res.text();
    try {
      return JSON.parse(text);
    } catch (e) {
      showError(statusId, "Invalid JSON: " + text);
      throw new Error("Invalid JSON response");
    }
  })
  .then(callback)
  .catch(err => {
    showError(statusId, "Request error: " + err.message);
  });
}

function showError(id, msg) {
  const el = document.getElementById(id);
  el.textContent = msg;
  el.style.color = "red";
}

function showSuccess(id, msg) {
  const el = document.getElementById(id);
  el.textContent = msg;
  el.style.color = "green";
}

let lastKnownDir = null;
function checkFTPConfigUpdated() {
  fetch(api + "?action=config_if_updated")
    .then(res => res.json())
    .then(data => {
      // If no configuration exists, reset state and update UI.
      if (!data || !data.config) {
        lastKnownDir = null;
        disconnectUI();
        return;
      }

      // If the engine number has changed, update it in the UI and in our state.
      const newDir = data.config.dir || "";
      if (newDir && newDir !== lastKnownDir) {
        lastKnownDir = newDir;
        document.getElementById("ftp-engine-number").value = newDir;
        showSuccess("ftp-status", `Engine ${newDir} set in another session. Click Connect to sync.`);
        listBackups();
      }
    })
    .catch(err => {
      showError("ftp-status", "Unable to check last FTP config status.");
    });
}

checkFTPConfigUpdated();
setInterval(checkFTPConfigUpdated, 2000); // poll every 2 seconds to check updates