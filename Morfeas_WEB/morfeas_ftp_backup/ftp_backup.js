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

function disconnectFTP() {
  postData({ action: "clearConfig" }, "ftp-status", "Disconnecting...", (resp) => {
    const statusEl = document.getElementById("ftp-status");

    if (resp.success) {
      showSuccess("ftp-status", "Disconnected. Configuration removed. Automatic backups disabled.");
      ["ftp-engine-number", "backup-status", "restore-status"].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = el.textContent = "";
      });
      document.getElementById("backup-list").innerHTML = "";
    } else {
      showError("ftp-status", `Disconnect failed: ${resp.error}`);
    }
  });
}

function listBackups() {
  console.log("listBackups() triggered due to config change");
  postData({ action: "list" }, "restore-status", "Loading backups...", (resp) => {
    const list = document.getElementById("backup-list");
    list.innerHTML = "";

    if (resp.success === false) {
      showError("restore-status", resp.error);
      return;
    }

    (resp || []).forEach(file => {
      const opt = document.createElement("option");
      opt.value = file;
      opt.text = file;
      list.appendChild(opt);
    });

    const dir = document.getElementById("ftp-engine-number").value.trim();

    if ((resp || []).length === 0) {
      showSuccess("restore-status", `No backup files found in engine number directory: "${dir}".`);
    } else {
      showSuccess("restore-status", `Found ${resp.length} backup file(s) in engine number directory: "${dir}".`);
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
      if (!data.connected) {
        // Disconnected
        console.warn(data.message || "Config not found — disconnected.");
        lastKnownDir = null;
        document.getElementById("backup-list").innerHTML = "";
        showError("ftp-status", "Disconnected (no config file found).");
        return;
      }

      // Connected but check if updated
      if (data.updated && data.config) {
        const newDir = data.config.dir || "";
        if (newDir && newDir !== lastKnownDir) {
          lastKnownDir = newDir;
          showSuccess("ftp-status", `Config updated (engine: ${newDir}), reloading...`);
          document.getElementById("ftp-engine-number").value = newDir;
          listBackups();
        }
      }
    })
    .catch(err => {
      console.error("Polling config failed", err);
    });
}

setInterval(checkFTPConfigUpdated, 5000);
console.log("Polling FTP config update...");


