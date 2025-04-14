<?php
$data = file_get_contents("php://stdin");
echo "Raw Input Data: $data\n"; // Debug output

$json = json_decode($data);
if ($json === null) {
    echo "JSON Decode Error: " . json_last_error_msg() . "\n";
    exit;
}

echo "Successfully decoded JSON: ";
print_r($json);
?>