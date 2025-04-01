"use strict";

const api = "../morfeas_php/ftp_api.php";

function connectFTP() {
  const data = getFormData();

  // Validate all required fields
  if (!data.host || !data.user || !data.pass || !data.dir) {
    showError("ftp-status", "All fields (Host, Username, Password, Engine Number) are required.");
    return;
  }

  postData({ ...data, action: "saveConfig" }, "ftp-status", "Saving config...", (saveResp) => {
    if (!saveResp.success) {
      showError("ftp-status", "Save failed: " + saveResp.error);
      return;
    }

    postData({ action: "testConnect" }, "ftp-status", "Testing connection...", (testResp) => {
      if (testResp.success) {
        showSuccess("ftp-status", "Connected & config saved!");
      } else {
        showError("ftp-status", "Connection failed: " + testResp.error);
      }
    });
  });
}


function backupToFTP() {
  postData({ action: "backup" }, "backup-status", "Creating and uploading backup...", (resp) => {
    if (resp.success) {
      showSuccess("backup-status", resp.message || "Backup complete");
    } else {
      showError("backup-status", "Backup failed: " + resp.error);
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
  .then(res => res.json())
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

function getFormData() {
  return {
    host: document.getElementById("ftp-host").value.trim(),
    user: document.getElementById("ftp-user").value.trim(),
    pass: document.getElementById("ftp-pass").value.trim(),
    dir:  document.getElementById("ftp-dir").value.trim()
  };
}

function disconnectFTP() {
  postData({ action: "clearConfig" }, "ftp-status", "Disconnecting...", (resp) => {
    if (resp.success) {
      showSuccess("ftp-status", "Disconnected.");

      document.getElementById("ftp-host").value = "";
      document.getElementById("ftp-user").value = "";
      document.getElementById("ftp-pass").value = "";
      document.getElementById("ftp-dir").value = "";

      document.getElementById("backup-status").textContent = "";
      document.getElementById("restore-status").textContent = "";

      document.getElementById("backup-list").innerHTML = "";
    } else {
      showError("ftp-status", "Disconnect failed: " + resp.error);
    }
  });
}
