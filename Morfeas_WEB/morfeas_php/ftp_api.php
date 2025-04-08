<?php
require("../Morfeas_env.php");
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
ini_set('error_log',  ERROR_LOG_FILE); // Debug Use
error_reporting(E_ALL);

header("Content-Type: application/json");

$logFile    = FTP_LOG_FILE;
$configFile = CONFIG_JSON;

// shutdown function to catch fatal errors
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error) {
        file_put_contents(ERROR_LOG_FILE, "[FATAL] " . print_r($error, true), FILE_APPEND);
    }
});

logMsg("\n=== New Request ===");

/*****************************************************************
 * Check ftp configration status for multi-user senario
 *****************************************************************/
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'config_if_updated') {
    $configPath = CONFIG_JSON;
    $pollWindow = 8; // If the config file was modified within the last 5 seconds, we consider it "updated".

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
 * Read input JSON
 *****************************************************************/
$data = file_get_contents("php://input");
logMsg("Raw POST: $data");

$json = json_decode($data);
if (!$json) {
    logMsg("JSON decode failed: $error");
    http_response_code(400);
    echo json_encode(["success" => false,
    "error" => "Invalid JSON format. Please ensure you're sending valid JSON. Error: $error"
    ]);
    exit;
}

/*****************************************************************
 * Main Router
 *****************************************************************/
try {
    if (!isset($json->action)) {
        throw new Exception("Missing 'action' parameter in request body.");
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
                throw new Exception("Missing 'file' parameter for restore action.");
            }
            ftpRestore($json->file);
            moveFTPLog();
            break;

        default:
            throw new Exception("Unknown action provided: " . $json->action);
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
 * SAVE CONFIG: host, user, pass, dir → /tmp/ftp_config.json
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
    logMsg("Config saved: " . json_encode($config));

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
        logMsg("Failed to retrieve file list.");
        echo json_encode(["success" => false, "error" => "Failed to retrieve file list"]);
        return;
    }

    logMsg("FTP test connection success.");
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

    // Ensure remote directory exists
    if (!@ftp_chdir($conn, $remoteDir)) {
        // try to create it (recursively if needed)
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

    // Upload
    if (!ftp_put($conn, $remoteFile, $localFile, FTP_BINARY)) {
        ftp_close($conn);
        throw new Exception("Failed to upload backup to $remoteFile");
    }

    logMsg("Uploaded backup to $remoteFile");

    // Enforce 100-file limit in the engine folder
    $files = ftp_nlist($conn, ".");
    $mbis = array_filter($files, function($f) {
        return str_ends_with(strtolower($f), ".mbl");
    });
    sort($mbis);

    $excess = count($mbis) - 100;
    if ($excess > 0) {
        $delete = array_slice($mbis, 0, $excess);
        foreach ($delete as $f) {
            $base = basename($f);
            $toDelete = "$remoteDir/$base";
            ftp_delete($conn, $toDelete);
            logMsg("Old backup deleted: $toDelete");
        }
    }

    ftp_close($conn);
    @unlink($localFile);

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
    logMsg("List available packages success.");
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
    $src = FTP_LOG_FILE;
    $dest = MORFEAS_LOGGER_FTP;

    if (file_exists($src)) {
        $cmd = "sudo /bin/mv " . escapeshellarg($src) . " " . escapeshellarg($dest);
        exec($cmd, $output, $retval);
        if ($retval === 0) {
            logMsg("Log moved successfully to $dest.");
        } else {
            logMsg("Failed to move log. Output: " . implode("\n", $output));
        }
    } else {
        logMsg("No ftp_debug.log found to move.");
    }
}

/*****************************************************************
 * HELPER FUNCTIONS
 *****************************************************************/
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

/**
 * LOG any message
 */
function logMsg($msg) {
    $logFile = FTP_LOG_FILE;
    $maxSize = LOG_ROTATE_MAX_SIZE;
    $time = date("Y-m-d H:i:s");

    if (is_string($msg) && strpos($msg, '"pass"') !== false) {
        $msg = preg_replace('/("pass"\s*:\s*")[^"]+("?)/', '$1*****$2', $msg);
    }

    @file_put_contents($logFile, "[$time] $msg\n", FILE_APPEND | LOCK_EX);

    // Trim from top if file exceeds size
    if (file_exists($logFile) && filesize($logFile) > $maxSize) {
        $contents = file($logFile); // read as array of lines
        $total = count($contents);

        while (strlen(implode('', $contents)) > $maxSize && $total > 1) {
            array_shift($contents);
            $total--;
        }

        @file_put_contents($logFile, implode('', $contents), LOCK_EX);
    }
}
?>