<?php
/*
File: morfeas_SDAQnet_proxy.php PHP Script for the Morfeas_Web. Part of Morfeas_project.
Copyright (C) 12020-12021  Sam harry Tzavaras

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
require("./Supplementary.php");
ob_start("ob_gzhandler");//Enable gzip buffering
//Disable caching
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: report/text');

$requestType = $_SERVER['REQUEST_METHOD'];
if($requestType == "GET")
{
	if(array_key_exists("SDAQnet", $_GET)&&array_key_exists("SDAQaddr", $_GET))
	{
		$SDAQ_net=$_GET['SDAQnet'];
		$SDAQ_addr=$_GET['SDAQaddr'];
		exec("SDAQ_worker $SDAQ_net getinfo $SDAQ_addr -s 2>&1", $output, $retval);
		if(!$retval)
		{
			header('Content-Type: Morfeas_SDAQ_calibration_data/xml');
			echo implode("\n",$output);
		}
		else
			die("Error: $output[0]");
	}
}
else if($requestType == "POST")
{
	$RX_data = file_get_contents('php://input');
	$SDAQ_cal_data = decompress($RX_data) or die("Error: Decompressing of SDAQ\'s Calibration XML data");
	switch($_SERVER["CONTENT_TYPE"])
	{
		case 'CalibrationDate/JSON':
			$cal_date=json_decode($SDAQ_cal_data) or die("Error: Failed to decode JSON");
			
		case 'CalibrationPoint/JSON':
	}
}
http_response_code(404);
?>