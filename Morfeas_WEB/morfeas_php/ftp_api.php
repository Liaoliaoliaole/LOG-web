<?php
/**
 * str_ends_with fallback for PHP < 8
 */
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        return $needle !== '' && substr($haystack, -strlen($needle)) === $needle;
    }
}

/*****************************************************************
 * Debug & Logging
 *****************************************************************/
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/php_errors.log'); // Debug Use
error_reporting(E_ALL);

header("Content-Type: application/json");

$logFile    = "/tmp/ftp_debug.log";
$configFile = "/tmp/ftp_config.json";

// shutdown function to catch fatal errors
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error) {
        file_put_contents('/tmp/php_errors.log', "[FATAL] " . print_r($error, true), FILE_APPEND);
    }
});

logMsg("\n=== New Request ===");

/*****************************************************************
 * Read input JSON
 *****************************************************************/
$data = file_get_contents("php://input");
logMsg("Raw POST: $data");

$json = json_decode($data);
if (!$json) {
    logMsg("JSON decode failed");
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Invalid JSON"]);
    exit;
}

/*****************************************************************
 * Main Router
 *****************************************************************/
try {
    if (!isset($json->action)) {
        throw new Exception("Missing 'action' parameter");
    }

    switch ($json->action) {
        case "saveConfig":
            saveConfig($json);
            break;

        case "testConnect":
            testFtpConnection();
            break;

        case "clearConfig":
            clearConfig();
            moveFTPLog();
            break;

        case "backup":
            ftpBackup();
            moveFTPLog();
            break;

        case "list":
            ftpList();
            break;

        case "restore":
            if (empty($json->file)) {
                throw new Exception("Missing file to restore");
            }
            ftpRestore($json->file);
            moveFTPLog();
            break;

        default:
            throw new Exception("Unknown action: " . $json->action);
    }

} catch (Exception $e) {
    logMsg("Exception: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
    exit;
}

/*****************************************************************
 * ACTION FUNCTIONS
 *****************************************************************/

/**
 * 1) SAVE CONFIG: host, user, pass, dir → /tmp/ftp_config.json
 */
function saveConfig($data) {
    global $configFile;

    if (empty($data->host) || empty($data->dir)) {
        throw new Exception("Missing host or engine number");
    }

    // Sanitize engine number (allow letters, numbers, underscores, dashes)
    $engineNumber = preg_replace('/[^a-zA-Z0-9_-]/', '', $data->dir);
    if (empty($engineNumber)) {
        throw new Exception("Invalid engine number after sanitization.");
    }

    // Load secure credentials
    $credFile = "/home/morfeas/configuration/LOG_ftp_backup.conf";
    if (!file_exists($credFile)) {
        throw new Exception("FTP credential file missing.");
    }
    $creds = parse_ini_file($credFile);
    logMsg("CREDS loaded: " . print_r($creds, true));
    if (empty($creds['FTP_USER']) || empty($creds['FTP_PASS'])) {
        throw new Exception("Invalid credentials in config file.");
    }

    // Get log name from system hostname
    $logName = trim(file_get_contents("/etc/hostname"));

    $config = [
        "host"   => $data->host,
        "user"   => $creds['FTP_USER'],
        "pass"   => $creds['FTP_PASS'],
        "dir"    => $engineNumber,
        "log"    => $logName
    ];

    file_put_contents($configFile, json_encode($config));
    logMsg("Config saved: " . json_encode($config));

    echo json_encode(["success" => true, "message" => "Config saved"]);
}

/**
 * 2) TEST FTP CONNECTION
 *    - Reads config
 *    - Connect, login, passive
 *    - List '.' to confirm
 */
function testFtpConnection() {
    $config = loadConfig();
    $conn   = openFtp($config);

    $dir  = $config->dir;
    $list = @ftp_nlist($conn, $dir);

    ftp_close($conn);

    if ($list === false) {
        logMsg("Failed to retrieve file list");
        echo json_encode(["success" => false, "error" => "Failed to retrieve file list"]);
        return;
    }

    logMsg("FTP test connection success");
    echo json_encode(["success" => true, "files" => $list]);
    return;
}

/**
 * 3) BACKUP
 *    - Connect
 *    - Create .mbl from local config XMLs
 *    - Upload
 *    - Remove local .mbl
 */
function ftpBackup() {
    $config = loadConfig();
    $conn   = openFtp($config);

    $timestamp = date("Ymd_His");
    $logName   = $config->log;
    $engineNum = $config->dir;

    $filename = "{$engineNum}_{$logName}_{$timestamp}.mbl";
    $localFile = "/tmp/$filename";

    $ua      = file_get_contents("/home/morfeas/configuration/OPC_UA_Config.xml");
    $morfeas = file_get_contents("/home/morfeas/configuration/Morfeas_config.xml");

    $bundle = [
        "OPC_UA_Config" => $ua,
        "Morfeas_Config" => $morfeas,
        "Checksum" => crc32($ua.$morfeas)
    ];

    file_put_contents($localFile, gzencode(json_encode($bundle)));

    // Upload: Change to engine directory or create it if needed
    if (!@ftp_chdir($conn, $engineNum)) {
        ftp_mkdir($conn, $engineNum);
        ftp_chdir($conn, $engineNum);
    } else {
        ftp_chdir($conn, $engineNum);
    }

    if (!ftp_put($conn, $filename, $localFile, FTP_BINARY)) {
        ftp_close($conn);
        throw new Exception("Failed to upload backup");
    }

    logMsg("Uploaded backup to /$engineNum/$filename");

    // Enforce 100 file limit
    $files = ftp_nlist($conn, ".");
    $mbis = array_filter($files, function($f) {
        return str_ends_with(strtolower($f), ".mbl");
    });
    sort($mbis);

    $excess = count($mbis) - 100;
    if ($excess > 0) {
        $delete = array_slice($mbis, 0, $excess);
        foreach ($delete as $f) {
            ftp_delete($conn, $f);
            logMsg("Old backup deleted: $f");
        }
    }

    ftp_close($conn);
    @unlink($localFile);

    echo json_encode(["success" => true, "message" => "Backup uploaded: $filename"]);
}

/**
 * 4) LIST .mbl FILES
 */
function ftpList() {
    $config = loadConfig();
    $conn   = openFtp($config);

    // Change to the engine number directory (stored in $config->dir)
    if (!@ftp_chdir($conn, $config->dir)) {
        ftp_close($conn);
        throw new Exception("Failed to change directory to " . $config->dir);
    }

    // List files in the current directory (engine directory)
    $files = ftp_nlist($conn, ".");
    ftp_close($conn);
    if (!$files) {
        $files = [];
    }

    // Filter to only .mbl files and return just the basename
    $mbiFiles = [];
    foreach ($files as $file) {
        if (str_ends_with(strtolower($file), ".mbl")) {
            $mbiFiles[] = basename($file);
        }
    }

    echo json_encode(array_values($mbiFiles));
    logMsg("List available packages success.");
    return;
}

/**
 * 5) RESTORE
 */
function ftpRestore($filename) {
    $config = loadConfig();
    $conn   = openFtp($config);

    // Change to the engine directory
    if (!@ftp_chdir($conn, $config->dir)) {
        ftp_close($conn);
        throw new Exception("Failed to change directory to " . $config->dir);
    }

    $remote = $filename;
    $local  = "/tmp/" . $filename;

    if (!ftp_get($conn, $local, $remote, FTP_BINARY)) {
        ftp_close($conn);
        throw new Exception("Download failed: $filename");
    }
    ftp_close($conn);

    $raw    = file_get_contents($local);
    $data   = gzdecode($raw);
    $bundle = json_decode($data);

    if (!$bundle) {
        throw new Exception("Invalid backup file content: " . $filename);
    }

    if (isset($bundle->OPC_UA_Config)) {
        file_put_contents("/home/morfeas/configuration/OPC_UA_Config.xml", $bundle->OPC_UA_Config);
    }
    if (isset($bundle->Morfeas_Config)) {
        file_put_contents("/home/morfeas/configuration/Morfeas_config.xml", $bundle->Morfeas_Config);
    }

    // Remove local copy
    @unlink($local);

    logMsg("Restore from $filename completed.");
    echo json_encode(["success" => true, "message" => "Restored from: $filename"]);
    return;
}

/**
 * 6) CLEAR TMP FILE
 */
function clearConfig() {
    global $configFile;
    if (file_exists($configFile)) {
        unlink($configFile);
        logMsg("Config cleared");
    } else {
        logMsg("No config file to clear");
    }

    echo json_encode(["success" => true, "message" => "Config cleared"]);
    return;
}

function moveFTPLog() {
    $src = "/tmp/ftp_debug.log";
    $dest = "/mnt/ramdisk/Morfeas_Loggers/LOG_ftp_backup.log";

    if (file_exists($src)) {
        $cmd = "sudo /bin/mv " . escapeshellarg($src) . " " . escapeshellarg($dest);
        exec($cmd, $output, $retval);
        if ($retval === 0) {
            logMsg("Log moved successfully to $dest using sudo mv.");
        } else {
            logMsg("Failed to move log using sudo mv. Output: " . implode("\n", $output));
        }
    } else {
        logMsg("No ftp_debug.log found to move.");
    }
}

/*****************************************************************
 * HELPER FUNCTIONS
 *****************************************************************/

/**
 * LOG any message
 */
function logMsg($msg) {
    global $logFile;
    $maxSize = 100 * 1024; // 100 KB
    $time = date("Y-m-d H:i:s");

    if (is_string($msg) && strpos($msg, '"pass"') !== false) {
        $msg = preg_replace('/("pass"\s*:\s*")[^"]+(")/', '$1*****$2', $msg);
    }

    if (file_exists($logFile) && filesize($logFile) > $maxSize) {
        file_put_contents($logFile, "[$time] === Log truncated due to size ===\n");
    }

    file_put_contents($logFile, "[$time] $msg\n", FILE_APPEND);
}

/**
 * Load config from /tmp/ftp_config.json
 */
function loadConfig() {
    global $configFile;
    if (!file_exists($configFile)) {
        throw new Exception("No config file found! Please connect first.");
    }

    $raw = file_get_contents($configFile);
    $config = json_decode($raw);
    if (
        !$config || empty($config->host) || empty($config->user)
        || empty($config->pass) || empty($config->dir) || empty($config->log)
    ) {
        throw new Exception("Incomplete config data");
    }

    return $config;
}

/**
 * Reusable function to open FTP, login, passive
 */
function openFtp($config) {
    $conn = @ftp_connect($config->host, 21, 10);
    if (!$conn) {
        throw new Exception("FTP connect failed");
    }
    if (!@ftp_login($conn, $config->user, $config->pass)) {
        ftp_close($conn);
        throw new Exception("FTP login failed");
    }
    if (!ftp_pasv($conn, true)) {
        ftp_close($conn);
        throw new Exception("Failed to enable passive mode");
    }
    return $conn;
}
?>
