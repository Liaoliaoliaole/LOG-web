// ftp_backup.js
"use strict";

const api = "../morfeas_php/ftp_api.php";

function connectFTP() {
  const data = getFormData();
  data.action = "connect";

  postData(data, "ftp-status", "Connecting...");
}

function backupToFTP() {
  const data = getFormData();
  data.action = "backup";

  postData(data, "backup-status", "Creating and uploading backup...");
}

function listBackups() {
  const data = getFormData();
  data.action = "list";

  fetch(api, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(data)
  })
  .then(res => res.json())
  .then(files => {
    const list = document.getElementById("backup-list");
    list.innerHTML = "";
    files.forEach(f => {
      const opt = document.createElement("option");
      opt.value = f;
      opt.text = f;
      list.appendChild(opt);
    });
  })
  .catch(err => {
    document.getElementById("restore-status").textContent = "Error: " + err;
  });
}

function restoreSelected() {
  const data = getFormData();
  data.action = "restore";
  data.file = document.getElementById("backup-list").value;

  if (!data.file) {
    alert("Please select a backup file.");
    return;
  }

  postData(data, "restore-status", "Restoring selected backup...");
}

function postData(data, statusId, loadingMsg) {
  const statusBox = document.getElementById(statusId);
  statusBox.textContent = loadingMsg;

  fetch(api, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(data)
  })
  .then(res => res.json())
  .then(response => {
    if (response.success) {
      statusBox.textContent = "Connection successful!";
      statusBox.style.color = "green";
    } else {
      statusBox.textContent = "Connection Error: " + response.error;
      statusBox.style.color = "red";
    }
  })
  .catch(err => {
    statusBox.textContent = "Error: " + err;
    statusBox.style.color = "red";
  });
}

function getFormData() {
  return {
    host: document.getElementById("ftp-host").value.trim(),
    user: document.getElementById("ftp-user").value.trim(),
    pass: document.getElementById("ftp-pass").value.trim(),
    dir: document.getElementById("ftp-dir").value.trim()
  };
}

window.addEventListener("beforeunload", () => {
  fetch(api, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ action: "clearConfig" })
  });
});
