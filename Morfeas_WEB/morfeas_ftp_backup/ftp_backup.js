"use strict";

const api = "../morfeas_php/ftp_api.php";

function connectFTP() {
  const host = document.getElementById("ftp-host-ip").value.trim();
  const dir = document.getElementById("ftp-engine-number").value.trim();

  if (!host || !dir) {
    showError("ftp-status", "Both Host IP and Engine Number are required.");
    return;
  }

  const data = {
    host: host,
    dir: dir,
    action: "saveConfig" // backend reads FTP_USER, FTP_PASS, log_name (hostname) itself
  };

  postData({ ...data, action: "saveConfig" }, "ftp-status", "Saving config...", (saveResp) => {
    if (!saveResp.success) {
      showError("ftp-status", "Save failed: " + saveResp.error);
      return;
    }

    // Test connection
    postData({ action: "testConnect" }, "ftp-status", "Testing connection...", (testResp) => {
      if (testResp.success) {
        showSuccess("ftp-status", "Connected & config saved!");
        // List backups after successful connection
        listBackups();
      } else {
        showError("ftp-status", "Connection failed: " + testResp.error);
      }
    });
  });
}

function disconnectFTP() {
  postData({ action: "clearConfig" }, "ftp-status", "Disconnecting...", (resp) => {
    if (resp.success) {
      showSuccess("ftp-status", "Disconnected.");
      document.getElementById("ftp-engine-number").value = "";
      document.getElementById("backup-status").textContent = "";
      document.getElementById("restore-status").textContent = "";
      document.getElementById("backup-list").innerHTML = "";
    } else {
      showError("ftp-status", "Disconnect failed: " + resp.error);
    }
  });
}

function listBackups() {
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


