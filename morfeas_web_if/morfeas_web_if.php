<?php
/*
File: Morfeas_Web_if.php PHP Script for the Morfeas_Web. Part of Morfeas_project.
Copyright (C) 12019-12020  Sam harry Tzavaras

	This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as
    published by the Free Software Foundation, either version 3 of the
    License, or any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.

    You should have received a copy of the GNU Affero General Public License
    along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/
define("usr_comp","COMMAND");
$ramdisk_path="/mnt/ramdisk/";

ob_start("ob_gzhandler");//Enable gzip buffering
//Disable caching
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$requestType = $_SERVER['REQUEST_METHOD'];
if($requestType == "GET")
{
	if(array_key_exists("COMMAND", $_GET))
	{
		switch($_GET[usr_comp])
		{
			case "logstats":
				if($logstats = array_diff(scandir($ramdisk_path), array('..', '.', 'Morfeas_Loggers')))
				{
					$logstats = array_values($logstats);// restore array order
					$i = 0;
					foreach($logstats as $logstat)
						if(preg_match("/^logstat_.+\.json$/i", $logstat))//Read only Morfeas JSON logstat files
						{
							$logstats_combined->logstats_names[$i] = $logstats[$i];
							$logstats_combined->logstat_contents[$i] = json_decode(file_get_contents($ramdisk_path . '/' . $logstat));
							if($logstats_combined->logstat_contents[$i])
								$i++;
						}
					header('Content-Type: application/json');
					echo json_encode($logstats_combined);
				}
				break;
			case "loggers": 
				if($loggers = array_diff(scandir($ramdisk_path . "Morfeas_Loggers"), array('..', '.')))
				{
					$loggers = array_values($loggers);// restore array order
					$loggers_names->Logger_names = $loggers;
					header('Content-Type: application/json');
					echo json_encode($loggers_names);
				}
				break;
		}
	}
}
?>