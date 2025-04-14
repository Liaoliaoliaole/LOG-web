<?php
//require("../Morfeas_env.php");
$scriptDir = dirname(__FILE__);
require("$scriptDir/../Morfeas_env.php");

header("Content-Type: application/json");
$configFile = CONFIG_JSON;

/**
 * str_ends_with fallback for PHP < 8
 */
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        return $needle !== '' && substr($haystack, -strlen($needle)) === $needle;
    }
}

/*****************************************************************
 * Check if running in CLI and set the environment accordingly
 *****************************************************************/
if (php_sapi_name() == "cli") {

    $data = file_get_contents("php://stdin");  // Read from standard input (CLI input)
    echo "Raw Input Data: $data\n";

    $json = json_decode($data);
    if (!$json) {
        $errorMsg = json_last_error_msg();
        echo "JSON Decode Error: $errorMsg\n";
        echo json_encode(["success" => false, "error" => "Invalid JSON format. Please ensure you're sending valid JSON. Error: $errorMsg"]);
        exit;
    }
    echo "Successfully decoded JSON: ";
    print_r($json);
} else {
    $data = file_get_contents("php://input");
    $json = json_decode($data);
    if (!$json) {
        $errorMsg = json_last_error_msg();
        logMsg("[ERROR] JSON decode failed: $errorMsg");
        http_response_code(400);
        echo json_encode(["success" => false, "error" => "Invalid JSON format. Error: $errorMsg"]);
        exit;
    }

    $data = file_get_contents("php://input");
}

/*****************************************************************
 * Debug Settings for Development
 *****************************************************************/
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log',  ERROR_LOG_FILE);
error_reporting(E_ALL);

// shutdown function to catch fatal errors
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error) {
        file_put_contents(ERROR_LOG_FILE, "[FATAL] " . print_r($error, true), FILE_APPEND);
    }
});

/*****************************************************************
 * Check ftp configration status for multi-user senario
 *****************************************************************/
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'config_if_updated') {
    $configPath = CONFIG_JSON;
    $pollWindow = 2; // If the config file was modified within the last 2 seconds, we consider it "updated".

    if (!file_exists($configPath)) {
        echo json_encode([
            "connected" => false,
            "updated"   => false,
            "message"   => "Config not found (FTP Disconnected)."
        ]);
        exit;
    }

    $lastModified = filemtime($configPath);
    $now = time();

    $recentlyChanged = (($now - $lastModified) <= $pollWindow);
    echo json_encode([
        "connected" => true,
        "updated"   => $recentlyChanged,
        "config"    => json_decode(file_get_contents($configPath))
    ]);
    exit;
}

/*****************************************************************
 * Read and process input JSON
 *****************************************************************/
// $data = file_get_contents("php://input");
// logMsg("[INFO] Incoming JSON request:\n$data");

// $json = json_decode($data);
// if (!$json) {
//     $error = json_last_error_msg();
//     logMsg("[ERROR] JSON decode failed: $error");
//     http_response_code(400);
//     echo json_encode([
//         "success" => false,
//         "error" => "Invalid JSON format. Please ensure you're sending valid JSON. Error: $error"
//     ]);
//     exit;
// }

/*****************************************************************
 * Main Router
 *****************************************************************/
try {
    if (!isset($json->action)) {
        logMsg("[ERROR] JSON missing 'action' field.");
        throw new Exception("Missing 'action' parameter in request body.");
    }
    logMsg("[INFO] Handling action: {$json->action}");

    switch ($json->action) {
        case "saveConfig":
            saveConfig($json);
            break;

        case "testConnect":
            testFtpConnection();
            break;

        case "clearConfig":
            clearConfig();
            break;

        case "backup":
            ftpBackup();
            break;

        case "list":
            ftpList();
            break;

        case "restore":
            if (empty($json->file)) {
                throw new Exception("Missing 'file' parameter for restore action.");
            }
            ftpRestore($json->file);
            break;

        default:
            throw new Exception("Unknown action provided: " . $json->action);
    }

} catch (Exception $e) {
    logMsg("[ERROR] Exception: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
    exit;
}

/*****************************************************************
 * ACTION FUNCTIONS
 *****************************************************************/

/**
 * SAVE CONFIG: host, user, pass, dir → /home/morfeas/configuration/ftp_config.json
 */
function saveConfig($data) {
    global $configFile;

    if (empty($data->host) || empty($data->dir)) {
        throw new Exception("Missing host IP or engine number");
    }

    $engineNumber = $data->dir;

    // Enforce directory name rule: only A-Z, a-z, 0-9, -, _, .
    if (!preg_match('/^[A-Za-z0-9_.-]+$/', $engineNumber)) {
        throw new Exception("Invalid engine number:
                            Allowed characters: letters (A-Z, a-z), digits (0-9), hyphens (-), underscores (_), and periods (.).
                            No spaces or other characters allowed.");
    }

    $credFile = CREDENTIAL_FILE;
    if (!file_exists($credFile)) {
        throw new Exception("FTP credential file missing.");
    }
    $creds = parse_ini_file($credFile);
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
    logMsg("[INFO] Config saved:\n" . json_encode($config, JSON_PRETTY_PRINT));

    echo json_encode(["success" => true, "message" => "Config saved"]);
}

/**
 * TEST FTP CONNECTION
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
        logMsg("[ERROR] Failed to retrieve file list.");
        echo json_encode(["success" => false, "error" => "Failed to retrieve file list"]);
        return;
    }

    logMsg("[INFO] FTP test connection success.");
    echo json_encode(["success" => true, "files" => $list]);
    return;
}

/**
 *  BACKUP
 *    - Connect
 *    - Create .mbl from local config XMLs
 *    - Upload
 *    - Remove local .mbl
 */
function ftpBackup() {
    $config = loadConfig();
    $conn   = openFtp($config);

    $timestamp  = date("Ymd_His");
    $logName    = $config->log;
    $engineNum  = $config->dir;

    $filename   = "{$engineNum}_{$logName}_{$timestamp}.mbl";
    $localFile  = "/tmp/$filename";
    $remoteDir  = "/{$engineNum}";
    $remoteFile = "{$remoteDir}/{$filename}";

    $ua      = file_get_contents(OPC_UA_XML);
    $morfeas = file_get_contents(MORFEAS_XML);

    $bundle = [
        "OPC_UA_Config"   => $ua,
        "Morfeas_Config"  => $morfeas,
        "Checksum"        => crc32($ua . $morfeas)
    ];

    file_put_contents($localFile, gzencode(json_encode($bundle)));

    if (!@ftp_chdir($conn, $remoteDir)) {
        $parts = explode("/", trim($remoteDir, "/"));
        $path = "";
        foreach ($parts as $part) {
            $path .= "/" . $part;
            if (!@ftp_chdir($conn, $path)) {
                if (!@ftp_mkdir($conn, $path)) {
                    ftp_close($conn);
                    logMsg("[ERROR]Failed to create FTP directory, $path.");
                    throw new Exception("Failed to create FTP directory: $path");
                }
            }
        }
    }

    if (!ftp_put($conn, $remoteFile, $localFile, FTP_BINARY)) {
        ftp_close($conn);
        throw new Exception("Failed to upload backup to $remoteFile");
    }

    logMsg("[INFO] Uploaded backup to $remoteFile");

    // Enforce 50-file limit in the engine folder
    $files = ftp_nlist($conn, ".");
    $mbis = array_filter($files, function($f) {
        return str_ends_with(strtolower($f), ".mbl");
    });
    sort($mbis);

    $excess = count($mbis) - 50;
    if ($excess > 0) {
        $delete = array_slice($mbis, 0, $excess);
        foreach ($delete as $f) {
            $base = basename($f);
            $toDelete = "$remoteDir/$base";
            ftp_delete($conn, $toDelete);
            logMsg("[INFO] Deleted old backup: $toDelete");
        }
    }

    ftp_close($conn);
    @unlink($localFile);

    if (file_exists(CONFIG_JSON)) {
        @touch(CONFIG_JSON);
    }

    echo json_encode(["success" => true, "message" => "Backup uploaded: $filename"]);
}

/**
 * LIST .mbl FILES
 */
function ftpList() {
    $config = loadConfig();
    $conn   = openFtp($config);

    $remoteDir = '/' . $config->dir;
    if (!@ftp_chdir($conn, $remoteDir)) {
        $parts = explode("/", trim($remoteDir, "/"));
        $path = "";
        foreach ($parts as $part) {
            $path .= "/" . $part;
            if (!@ftp_chdir($conn, $path)) {
                if (!@ftp_mkdir($conn, $path)) {
                    ftp_close($conn);
                    throw new Exception("Failed to create FTP directory: $path");
                }
            }
        }
    }

    $files = ftp_nlist($conn, ".");
    ftp_close($conn);
    if (!$files) {
        $files = [];
    }

    $mbiFiles = [];
    foreach ($files as $file) {
        if (str_ends_with(strtolower($file), ".mbl")) {
            $mbiFiles[] = basename($file);
        }
    }

    echo json_encode(array_values($mbiFiles));
    logMsg("[INFO] Listed " . count($mbiFiles) . " backup files.");
    return;
}

/**
 * RESTORE
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
        file_put_contents(OPC_UA_XML, $bundle->OPC_UA_Config);
    }
    if (isset($bundle->Morfeas_Config)) {
        file_put_contents(MORFEAS_XML, $bundle->Morfeas_Config);
    }

    @unlink($local);

    if (file_exists(CONFIG_JSON)) {
        @touch(CONFIG_JSON);
    }

    logMsg("[INFO] Restored from: $filename");
    echo json_encode(["success" => true, "message" => "Restored from: $filename"]);
    return;
}

/**
 * 6) CLEAR CONGIG FILE
 */
function clearConfig() {
    global $configFile;
    if (file_exists($configFile)) {
        unlink($configFile);
        logMsg("[INFO] Config cleared.");
    } else {
        logMsg("[INFO] No config file to clear");
    }

    echo json_encode(["success" => true, "message" => "Config cleared."]);
    return;
}

/*****************************************************************
 * HELPER FUNCTIONS
 *****************************************************************/
/**
 * Load config file
 */
function loadConfig() {
    global $configFile;
    if (!file_exists($configFile)) {
        logMsg("[ERROR] Config file not found at $configFile.");
        throw new Exception("No config file found! Please connect first.");
    }

    $raw = file_get_contents($configFile);
    $config = json_decode($raw);

    if (!$config || empty($config->host) || empty($config->user) || empty($config->pass) || empty($config->dir) || empty($config->log)) {
        logMsg("[ERROR] Incomplete or invalid config data: $raw");
        throw new Exception("Incomplete config data");
    }

    logMsg("[INFO] Config loaded for engine: {$config->dir}");
    return $config;
}

/**
 * Open FTP, login, passive
 */
function openFtp($config) {
    $conn = @ftp_connect($config->host, 21, 10);
    if (!$conn) {
        logMsg("[ERROR] FTP connect failed to {$config->host}");
        throw new Exception("FTP connect failed");
    }

    if (!@ftp_login($conn, $config->user, $config->pass)) {
        ftp_close($conn);
        logMsg("[ERROR] FTP login failed for user {$config->user} on {$config->host}");
        throw new Exception("FTP login failed");
    }

    if (!ftp_pasv($conn, true)) {
        ftp_close($conn);
        logMsg("[ERROR] Failed to enable passive mode on {$config->host}");
        throw new Exception("Failed to enable passive mode");
    }

    logMsg("[INFO] FTP connection established and passive mode enabled on {$config->host}");
    return $conn;
}

/**
 * LOG any message
 */
function logMsg($msg) {
    //$logFile = MORFEAS_LOGGER_FTP;
    $logFile = FTP_LOG_FILE;
    $maxSize = LOG_ROTATE_MAX_SIZE;
    $time = date("Y-m-d H:i:s");

    if (is_string($msg) && strpos($msg, '"pass"') !== false) {
        $msg = preg_replace('/("pass"\s*:\s*")[^"]+("?)/', '$1*****$2', $msg);
    }

    @file_put_contents($logFile, "[$time] $msg\n", FILE_APPEND | LOCK_EX);

    // Log rotation
    if (file_exists($logFile) && filesize($logFile) > $maxSize) {
        $contents = file($logFile);
        while (strlen(implode('', $contents)) > $maxSize && count($contents) > 1) {
            array_shift($contents);
        }
        @file_put_contents($logFile, implode('', $contents), LOCK_EX);
    }
}
?>