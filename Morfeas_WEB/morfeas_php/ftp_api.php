<?php
// Debug mode
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json");

// Log file path
$logFile = "/tmp/ftp_debug.log";
file_put_contents($logFile, "=== New Request ===\n", FILE_APPEND);
$configFile ="/tmp/ftp_config.json";

// Read and decode JSON input
$data = file_get_contents("php://input");
file_put_contents($logFile, "Raw POST: $data\n", FILE_APPEND);
$json = json_decode($data);

if (!$json) {
    file_put_contents($logFile, "JSON decode failed\n", FILE_APPEND);
    http_response_code(500);
    echo json_encode(["error" => "Invalid JSON"]);
    exit;
}

try {
    if (!isset($json->action)) {
        throw new Exception("Missing 'action' parameter");
    }

    switch ($json->action) {
        case "connect":
            saveConfigAndTestConnection($json);
            break;
        case "backup":
            ftpBackup();
            break;
        case "list":
            ftpList();
            break;
        case "restore":
            ftpRestore($json->file ?? "");
            break;
        case "clearConfig":
            if (file_exists($configFile)) {
                unlink($configFile);
            }
            echo json_encode(["success" => true]);
            break;            
        default:
            throw new Exception("Unknown action: " . $json->action);
    }

} catch (Exception $e) {
    file_put_contents($logFile, "Exception: " . $e->getMessage() . "\n", FILE_APPEND);
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
    exit;
}

function saveConfigAndTestConnection($config) {
    global $configFile;

    foreach (['host', 'user', 'pass'] as $field) {
        if (empty($json->$field)) {
            throw new Exception("Missing FTP field: $field");
        }
    }

    $conn = @ftp_connect($config->host, 21, 10);
    if (!$conn) {
        echo json_encode(["success" => false, "error" => "FTP connection failed"]);
        return;
    }

    $login = @ftp_login($conn, $config->user, $config->pass);
    if (!$login) {
        ftp_close($conn);
        echo json_encode(["success" => false, "error" => "FTP login failed"]);
        return;
    }

    if (!ftp_pasv($conn, true)) { // true enables passive mode
        echo json_encode(["success" => false, "error" => "Failed to enable passive mode"]);
        ftp_close($conn);
        return;
    }

    $list = @ftp_nlist($conn, $config->dir ?: ".");
    if ($list === false) {
        ftp_close($conn);
        echo json_encode(["success" => false, "error" => "Failed to retrieve file list"]);
        return;
    }

    file_put_contents($configFile, json_encode($config));

    echo json_encode(["success" => true, "files" => $list]);
}

function ftpBackup() {
    $config = loadConfig();

    $conn = ftp_connect($config->host, 21, 10);
    if (!$conn || !ftp_login($conn, $config->user, $config->pass)) {
        throw new Exception("FTP connect/login failed");
    }
    ftp_pasv($conn, true);

    $timestamp = date("Ymd_His");
    $bundleFile = "/tmp/morfeas_$timestamp.mbi";

    // Combine files
    $ua = file_get_contents("/var/www/html/morfeas_php/OPC_UA_Config.xml");
    $morfeas = file_get_contents("/var/www/html/morfeas_php/Morfeas_config.xml");

    $bundle = json_encode([
        "OPC_UA_Config" => $ua,
        "Morfeas_Config" => $morfeas,
        "Checksum" => crc32($ua . $morfeas)
    ]);

    file_put_contents($bundleFile, gzencode($bundle));

    $remote = ($config->dir ? $config->dir . "/" : "") . basename($bundleFile);
    if (!ftp_put($conn, $remote, $bundleFile, FTP_BINARY)) {
        throw new Exception("Failed to upload backup");
    }

    ftp_close($conn);
    // Remove local bundle file after upload
    if (file_exists($bundleFile) && !unlink($bundleFile)) {
        file_put_contents("/tmp/ftp_debug.log", "Warning: Failed to delete $bundleFile\n", FILE_APPEND);
    }

    echo json_encode([
        "success" => true,
        "message" => "Backup uploaded and local file removed: " . basename($remote)
    ]);
}

function ftpList() {
    $config = loadConfig();

    $conn = ftp_connect($config->host, 21, 10);
    if (!$conn || !ftp_login($conn, $config->user, $config->pass)) {
        throw new Exception("FTP connect/login failed");
    }
    ftp_pasv($conn, true);

    $dir = $config->dir ?: ".";
    $files = ftp_nlist($conn, $dir);
    ftp_close($conn);

    if (!$files) throw new Exception("No files listed");

    $mbi = array_filter($files, fn($f) => str_ends_with($f, ".mbi") || str_ends_with($f, ".MBI"));
    echo json_encode(array_values($mbi));
}

function ftpRestore($filename) {
    if (empty($filename)) throw new Exception("Missing file to restore");

    $config = loadConfig();

    $conn = ftp_connect($config->host, 21, 10);
    if (!$conn || !ftp_login($conn, $config->user, $config->pass)) {
        throw new Exception("FTP connect/login failed");
    }
    ftp_pasv($conn, true);

    $remote = ($config->dir ? $config->dir . "/" : "") . $filename;
    $local = "/tmp/" . $filename;

    if (!ftp_get($conn, $local, $remote, FTP_BINARY)) {
        throw new Exception("Download failed: $filename");
    }

    $data = gzdecode(file_get_contents($local));
    $bundle = json_decode($data);

    file_put_contents("/var/www/html/morfeas_php/OPC_UA_Config.xml", $bundle->OPC_UA_Config ?? "");
    file_put_contents("/var/www/html/morfeas_php/Morfeas_config.xml", $bundle->Morfeas_Config ?? "");

    ftp_close($conn);
    echo json_encode(["success" => true, "message" => "Restored from: $filename"]);
}