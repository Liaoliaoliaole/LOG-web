<?php
require("../Morfeas_env.php");
require("./morfeas_ftp_backup.php"); // Reuse existing FTP logic
header("Content-Type: text/plain");

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->host, $data->user, $data->pass, $data->action)) {
    http_response_code(400);
    exit("Missing required fields.");
}

$host = $data->host;
$user = $data->user;
$pass = $data->pass;
$dir  = $data->dir ?? "";

$ftp = ftp_connect($host, 21, 10);
if (!$ftp || !ftp_login($ftp, $user, $pass)) {
    exit("FTP connection failed.");
}
if ($dir) ftp_chdir($ftp, $dir);

switch ($data->action) {
    case "connect":
        ftp_close($ftp);
        exit("Connection successful.");
    case "backup":
        $name = gethostname() . "_" . date("Y_d_m_G_i_s") . ".mbl";
        $bundle = bundle_make();
        $tmpPath = "/tmp/" . $name;
        file_put_contents($tmpPath, $bundle);
        if (ftp_put($ftp, $name, $tmpPath, FTP_BINARY)) {
            unlink($tmpPath);
            ftp_close($ftp);
            exit("Backup uploaded: $name");
        } else {
            ftp_close($ftp);
            exit("Upload failed.");
        }
    case "list":
        $files = ftp_nlist($ftp, ".");
        $backups = array_filter($files, fn($f) => str_ends_with($f, ".mbl"));
        ftp_close($ftp);
        echo json_encode(array_values($backups));
        exit;
    case "restore":
        if (!isset($data->file)) exit("No file selected.");
        $remote = $data->file;
        $local = "/tmp/" . $remote;
        if (ftp_get($ftp, $local, $remote, FTP_BINARY)) {
            $bundle = file_get_contents($local);
            $bundle = gzdecode($bundle);
            $json = json_decode($bundle);
            if (!$json) exit("Invalid bundle format.");

            file_put_contents($opc_ua_config_dir . "OPC_UA_Config.xml", $json->OPC_UA_config);
            file_put_contents($opc_ua_config_dir . "Morfeas_config.xml", $json->Morfeas_config);
            exec("sudo systemctl restart Morfeas_system.service");
            exit("Restore complete.");
        } else {
            exit("Download failed.");
        }
}

ftp_close($ftp);
exit("Unknown action.");
?>
