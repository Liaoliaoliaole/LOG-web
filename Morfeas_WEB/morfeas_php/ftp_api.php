<?php
// Debug mode
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Log file path
$logFile = "/tmp/ftp_debug.log";
file_put_contents($logFile, "=== New Request ===\n", FILE_APPEND);

header("Content-Type: application/json");

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
            connectFTP($json);
            break;

        // TODO: Add more actions like backup, list, restore later
        default:
            throw new Exception("Unknown action: " . $json->action);
    }

} catch (Exception $e) {
    file_put_contents($logFile, "Exception: " . $e->getMessage() . "\n", FILE_APPEND);
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
    exit;
}

function connectFTP($json) {
    global $logFile;

    foreach (['host', 'user', 'pass'] as $field) {
        if (empty($json->$field)) {
            throw new Exception("Missing FTP field: $field");
        }
    }

    $host = $json->host;
    $user = $json->user;
    $pass = $json->pass;

    $conn = @ftp_connect($host, 21, 10);
    if (!$conn) {
        echo json_encode(["success" => false, "error" => "FTP connection failed"]);
        return;
    }

    // Enable passive mode
    @ftp_pasv($conn, true);

    $login = @ftp_login($conn, $user, $pass);
    if (!$login) {
        ftp_close($conn);
        echo json_encode(["success" => false, "error" => "FTP login failed"]);
        return;
    }

    $list = @ftp_nlist($conn, ".");
    if ($list === false) {
        ftp_close($conn);
        echo json_encode(["success" => false, "error" => "Failed to retrieve file list"]);
        return;
    }

    ftp_close($conn);

    echo json_encode(["success" => true, "files" => $list]);
}