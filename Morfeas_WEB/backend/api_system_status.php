<?php
// backend/api_system_status.php
// Provides System Status (Details) from live ramdisk logstat data.

require __DIR__ . '/core/paths.php';
require __DIR__ . '/services/system_status_service.php';

header('Content-Type: application/json');

$ramdisk = backend_ramdisk_dir();

$action = $_GET['action'] ?? 'details';
if ($action !== 'details') {
    echo json_encode(['ok' => false, 'error' => 'Unknown action'], JSON_PRETTY_PRINT);
    exit;
}

$entries = system_status_entries($ramdisk);

echo json_encode(['ok' => true, 'entries' => $entries], JSON_PRETTY_PRINT);
