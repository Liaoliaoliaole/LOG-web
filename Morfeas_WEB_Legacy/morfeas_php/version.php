<?php
/*
File: Version.php PHP Script for the Morfeas_Web. Part of Morfeas_project.
Copyright (C) 12019-12021  Sam harry Tzavaras

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
require("../Morfeas_env.php");
define("usr_comp","Morfeas_Ver");

$GIT_OUTPUT_FORMAT = '\'{"commit_hash_abbreviated":"%h","author":"%aN","date_unix":"%at"}\'';

ob_start("ob_gzhandler");//Enable gzip buffering
//Disable caching
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$requestType = $_SERVER['REQUEST_METHOD'];

if($requestType == "GET")
{
	if(array_key_exists(usr_comp, $_GET))
	{
		switch($_GET[usr_comp])
		{
			case "All":
				$Morfeas_ver_combined = new stdClass;
				exec("git log -1 --oneline --pretty=format:$GIT_OUTPUT_FORMAT", $output_morfeas_web);
				$output_morfeas_web = implode('', $output_morfeas_web);
				$Morfeas_ver_combined->Morfeas_web_ver = json_decode($output_morfeas_web);
				if(isset($Morfeas_core_install_dir))
				{
					exec("git -C $Morfeas_core_install_dir log -1 --oneline --pretty=format:$GIT_OUTPUT_FORMAT", $output_morfeas_core, $ret);
					if(!$ret)
					{
						$output_morfeas_core = implode('', $output_morfeas_core);
						$Morfeas_ver_combined->Morfeas_core_ver = json_decode($output_morfeas_core);
					}
					else
						$Morfeas_ver_combined->Morfeas_core_ver = json_decode("{\"Report\":\"Error: git return $ret\"}");
				}
				else
					$Morfeas_ver_combined->Morfeas_core_ver = 'Error: "Morfeas_core_install_dir" env variable not defined!!!';
				header('Content-Type: application/json');
				die(json_encode($Morfeas_ver_combined));
			case "Morfeas_web":
				exec("git log -1 --oneline --pretty=format:$GIT_OUTPUT_FORMAT", $output);
				$output = implode('', $output);
				header('Content-Type: application/json');
				die($output);
			case "Morfeas_core":
				header('Content-Type: application/json');
				if(isset($Morfeas_core_install_dir))
				{
					exec("git -C $Morfeas_core_install_dir log -1 --oneline --pretty=format:$GIT_OUTPUT_FORMAT", $output, $ret);
					if(!$ret)
					{
						$output = implode('', $output);
						die($output);
					}
					die("{\"Report\":\"Error: git return $ret\"}");
				}
				die('{"Report":"Error: \"Morfeas_core_install_dir\" env variable not defined!!!"}');
		}
	}
}
http_response_code(404);
?>
