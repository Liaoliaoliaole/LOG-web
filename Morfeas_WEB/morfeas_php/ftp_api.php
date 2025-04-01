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
ini_set('display_errors', 0);
ini_set('log_errors', 1);
// ini_set('error_log', '/tmp/php_errors.log'); // Debug Use

header("Content-Type: application/json");

$logFile    = "/tmp/ftp_debug.log";
$configFile = "/tmp/ftp_config.json";

logMsg("=== New Request ===\n");

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

        case "backup":
            ftpBackup();
            clearConfig();
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
            clearConfig();
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
    foreach (["host", "user", "pass"] as $key) {
        if (empty($data->$key)) {
            throw new Exception("Missing field: $key");
        }
    }

    file_put_contents($configFile, json_encode($data));
    logMsg("Config saved to $configFile");

    echo json_encode(["success" => true, "message" => "Config saved"]);
    return;
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

    $dir  = $config->dir ?? ".";
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
 *    - Create .mbi from local config XMLs
 *    - Upload
 *    - Remove local .mbi
 */
function ftpBackup() {
    $config = loadConfig();
    $conn   = openFtp($config);

    $timestamp  = date("Ymd_His");
    $bundleFile = "/tmp/morfeas_$timestamp.mbi";

    // read local config
    $ua      = file_get_contents("/home/morfeas/configuration/OPC_UA_Config.xml");
    $morfeas = file_get_contents("/home/morfeas/configuration/Morfeas_config.xml");

    $bundle = [
        "OPC_UA_Config" => $ua,
        "Morfeas_Config" => $morfeas,
        "Checksum"      => crc32($ua.$morfeas)
    ];

    file_put_contents($bundleFile, gzencode(json_encode($bundle)));

    $remoteName = ($config->dir ? $config->dir."/" : "") . basename($bundleFile);
    if (!ftp_put($conn, $remoteName, $bundleFile, FTP_BINARY)) {
        ftp_close($conn);
        throw new Exception("Failed to upload backup");
    }

    logMsg("Backup uploaded to $remoteName");

    // Enforce max 10 .mbi files
    $dir   = $config->dir ?: ".";
    $files = @ftp_nlist($conn, $dir);
    if ($files && is_array($files)) {
        $mbiFiles = array_filter($files, function($f) {
            return str_ends_with(strtolower($f), ".mbi");
        });

        sort($mbiFiles);

        $excess = count($mbiFiles) - 10;
        if ($excess > 0) {
            $toDelete = array_slice($mbiFiles, 0, $excess);
            foreach ($toDelete as $file) {
                $basename = basename($file);
                $deletePath = ($config->dir ? $config->dir."/" : "") . $basename;
            
                if (!ftp_delete($conn, $deletePath)) {
                    logMsg("Failed to delete old backup: $deletePath");
                } else {
                    logMsg("Deleted old backup: $deletePath");
                }
            }
        }
    }

    ftp_close($conn);

    // remove local file
    if (file_exists($bundleFile)) {
        unlink($bundleFile);
    }

    logMsg("Backup uploaded to $remoteName, local file removed.");
    echo json_encode(["success" => true, "message" => "Backup uploaded: " . basename($remoteName)]);
    return;
}

/**
 * 4) LIST .mbi FILES
 */
function ftpList() {
    $config = loadConfig();
    $conn   = openFtp($config);

    $dir   = $config->dir ?: ".";
    $files = @ftp_nlist($conn, $dir);

    ftp_close($conn);

    if (!$files || !is_array($files)) {
        // Just return empty array
        echo json_encode([]);
        return;
    }

    // filter to .mbi
    $mbi = array_filter($files, function($f) {
        $lower = strtolower($f);
        return str_ends_with($lower, ".mbi");
    });

    echo json_encode(array_values($mbi));
    logMsg("List avaliable packages success.");
    return;
}

/**
 * 5) RESTORE
 */
function ftpRestore($filename) {
    $config = loadConfig();
    $conn   = openFtp($config);

    $remote = ($config->dir ? $config->dir."/" : "").$filename;
    $local  = "/tmp/".$filename;

    if (!ftp_get($conn, $local, $remote, FTP_BINARY)) {
        ftp_close($conn);
        throw new Exception("Download failed: $filename");
    }
    ftp_close($conn);

    // parse .mbi
    $raw    = file_get_contents($local);
    $data   = gzdecode($raw);
    $bundle = json_decode($data);

    // overwrite local config
    if (isset($bundle->OPC_UA_Config)) {
        file_put_contents("/home/morfeas/configuration/OPC_UA_Config.xml", $bundle->OPC_UA_Config);
    }
    if (isset($bundle->Morfeas_Config)) {
        file_put_contents("/home/morfeas/configuration/Morfeas_config.xml", $bundle->Morfeas_Config);
    }

    // remove local copy
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
    }
    return;
}

function moveFTPLog() {
    global $logFile;
    if (file_exists($logFile)) {
        $cmd = "sudo cp /tmp/ftp_debug.log /mnt/ramdisk/Morfeas_Loggers/LOG_ftp_backup.log && sudo rm -f /tmp/ftp_debug.log";
        $output = shell_exec($cmd . " 2>&1");
        logMsg("Executed: $cmd");
        logMsg("Output: $output");
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

    if (file_exists($logFile) && filesize($logFile) > $maxSize) {
        file_put_contents($logFile, "[$time] === Log truncated due to size ===\n");
    }

    $time = date("Y-m-d H:i:s");
    file_put_contents($logFile, "[$time] $msg\n", FILE_APPEND);
}


/**
 * Load config from /tmp/ftp_config.json
 */
function loadConfig() {
    global $configFile;
    if (!file_exists($configFile)) {
        throw new Exception("No config file found! Did you call 'saveConfig' first?");
    }
    $raw    = file_get_contents($configFile);
    $config = json_decode($raw);
    if (!$config || empty($config->host) || empty($config->user) || empty($config->pass)) {
        throw new Exception("Invalid config data");
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


