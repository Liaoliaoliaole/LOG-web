<?php
echo "Hostname: " . file_get_contents("/etc/hostname") . "\n";
echo "Credentials: " . print_r(parse_ini_file("/home/morfeas/configuration/LOG_ftp_backup.conf"), true);
?>
