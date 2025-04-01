<?php
// Debug mode
ini_set('display_errors', 0); 
ini_set('log_errors', 1); 
ini_set('error_log', '/tmp/php_errors.log');

header("Content-Type: application/json");

$logFile = "/tmp/ftp_debug.log";
$configFile ="/tmp/ftp_config.json";

file_put_contents($logFile, "=== New Request ===\n", FILE_APPEND);

// Read and decode JSON input
$data = file_get_contents("php://input");
file_put_contents($logFile, "Raw POST: $data\n", FILE_APPEND);

$json = json_decode($data);
if (!$json) {
    file_put_contents($logFile, "JSON decode failed\n", FILE_APPEND);
    http_response_code(400);
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
            clearConfig();
            break;            
        default:
            throw new Exception("Unknown action: " . $json->action);
    }

} catch (Exception $e) {
    file_put_contents($logFile, "Exception: " . $e->getMessage() . "\n", FILE_APPEND);
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
    exit;
}

function saveConfigAndTestConnection($config) {
    global $configFile, $logFile;

    foreach (['host', 'user', 'pass'] as $field) {
        if (empty($config->$field)) {
            throw new Exception("Missing FTP field: $field");
        }
    }

    $conn = @ftp_connect($config->host, 21, 10);
    if (!$conn) {
        file_put_contents($logFile, "FTP connection failed\n", FILE_APPEND);
        echo json_encode(["success" => false, "error" => "FTP connection failed"]);
        return;
    }

    $login = @ftp_login($conn, $config->user, $config->pass);
    if (!$login) {
        ftp_close($conn);
        file_put_contents($logFile, "FTP login failed\n", FILE_APPEND);
        echo json_encode(["success" => false, "error" => "FTP login failed"]);
        return;
    }

    if (!ftp_pasv($conn, true)) {
        ftp_close($conn);
        file_put_contents($logFile, "Passive mode failed\n", FILE_APPEND);
        echo json_encode(["success" => false, "error" => "Failed to enable passive mode"]);
        return;
    }

    $dir = $config->dir ?? ".";
    $list = @ftp_nlist($conn, $dir);
    if ($list === false) {
        ftp_close($conn);
        file_put_contents($logFile, "Failed to retrieve file list\n", FILE_APPEND);
        echo json_encode(["success" => false, "error" => "Failed to retrieve file list"]);
        return;
    }

    ftp_close($conn);
    file_put_contents($configFile, json_encode($config));
    file_put_contents($logFile, "FTP connect success, saved config\n", FILE_APPEND);
    echo json_encode(["success" => true, "files" => $list]);
    return;
}

function ftpBackup() {
    global $logFile;

    $config = loadConfig();

    // Connect
    $conn = ftp_connect($config->host, 21, 10);
    if (!$conn) {
        throw new Exception("FTP connection failed");
    }
    if (!@ftp_login($conn, $config->user, $config->pass)) {
        ftp_close($conn);
        throw new Exception("FTP login failed");
    }
    ftp_pasv($conn, true);

    // Create .mbi file
    $timestamp  = date("Ymd_His");
    $bundleFile = "/tmp/morfeas_$timestamp.mbi";

    // Read local XMLs
    $ua      = file_get_contents("/home/morfeas/configuration/OPC_UA_Config.xml");
    $morfeas = file_get_contents("/home/morfeas/configuration/Morfeas_config.xml");

    $bundle = json_encode([
        "OPC_UA_Config" => $ua,
        "Morfeas_Config" => $morfeas,
        "Checksum"      => crc32($ua . $morfeas)
    ]);
    file_put_contents($bundleFile, gzencode($bundle));

    // Upload
    $remote   = ($config->dir ? $config->dir . "/" : "") . basename($bundleFile);
    if (!ftp_put($conn, $remote, $bundleFile, FTP_BINARY)) {
        ftp_close($conn);
        throw new Exception("Failed to upload backup");
    }
    ftp_close($conn);

    // Remove local .mbi file
    if (file_exists($bundleFile) && !unlink($bundleFile)) {
        file_put_contents($logFile, "Warning: Failed to remove $bundleFile\n", FILE_APPEND);
    }

    echo json_encode(["success" => true, "message" => "Backup uploaded & local file removed: " . basename($remote)]);
    return;
}

function ftpList() {
    $config = loadConfig();

    $conn = ftp_connect($config->host, 21, 10);
    if (!$conn) {
        throw new Exception("FTP connection failed");
    }
    if (!@ftp_login($conn, $config->user, $config->pass)) {
        ftp_close($conn);
        throw new Exception("FTP login failed");
    }
    ftp_pasv($conn, true);

    $dir   = $config->dir ?: ".";
    $files = ftp_nlist($conn, $dir);

    ftp_close($conn);

    if (!$files) {
        echo json_encode([]);
        return; // no .mbi found
    }

    // Filter to .mbi only
    $mbi = array_filter($files, function($f) {
        $lower = strtolower($f);
        return str_ends_with($lower, ".mbi");
    });

    // Return as array
    echo json_encode(array_values($mbi));
    return;
}

function ftpRestore($filename) {
    if (empty($filename)) {
        throw new Exception("Missing file to restore");
    }

    $config = loadConfig();

    $conn = ftp_connect($config->host, 21, 10);
    if (!$conn) {
        throw new Exception("FTP connection failed");
    }
    if (!@ftp_login($conn, $config->user, $config->pass)) {
        ftp_close($conn);
        throw new Exception("FTP login failed");
    }
    ftp_pasv($conn, true);

    $remote = ($config->dir ? $config->dir . "/" : "") . $filename;
    $local  = "/tmp/" . $filename;

    if (!ftp_get($conn, $local, $remote, FTP_BINARY)) {
        ftp_close($conn);
        throw new Exception("Download failed: $filename");
    }
    ftp_close($conn);

    // Decompress & parse
    $raw    = file_get_contents($local);
    $data   = gzdecode($raw);
    $bundle = json_decode($data);

    // Overwrite local configs
    if (isset($bundle->OPC_UA_Config)) {
        file_put_contents("/var/www/html/morfeas_php/OPC_UA_Config.xml", $bundle->OPC_UA_Config);
    }
    if (isset($bundle->Morfeas_Config)) {
        file_put_contents("/var/www/html/morfeas_php/Morfeas_config.xml", $bundle->Morfeas_Config);
    }

    // Remove local .mbi after restore, optional
    @unlink($local);

    echo json_encode(["success" => true, "message" => "Restored from: $filename"]);
    return;
}

function clearConfig() {
    global $configFile, $logFile;
    if (file_exists($configFile)) {
        unlink($configFile);
        file_put_contents($logFile, "Config cleared\n", FILE_APPEND);
    }
    echo json_encode(["success" => true]);
    return;
}

function loadConfig() {
    global $configFile;
    if (!file_exists($configFile)) {
        throw new Exception("FTP config not found. Did you connect first?");
    }
    $raw    = file_get_contents($configFile);
    $config = json_decode($raw);
    if (!$config || !isset($config->host, $config->user, $config->pass)) {
        throw new Exception("Invalid or partial config file");
    }
    return $config;
}

if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        return $needle !== '' && substr($haystack, -strlen($needle)) === $needle;
    }
}