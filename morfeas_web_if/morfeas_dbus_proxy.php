<?php
/*
File: Morfeas_dbus_proxy.php PHP Script for the Morfeas_Web. Part of Morfeas_project.
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
$ans;
function shutdown()
{
    global $ans;
	if(!$ans)
		echo 'Morfeas_DBUS_proxy: Exit with Error';
}

ob_start("ob_gzhandler");//Enable gzip buffering
//Disable caching
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

register_shutdown_function('shutdown');

$requestType = $_SERVER['REQUEST_METHOD'];
if($requestType == "POST")
{
	if(!isset($_POST["arg"]))
	{
		echo "Morfeas_DBUS_proxy: No Argument";
		exit();
	} 
	$arg = json_decode($_POST["arg"], false) or die("Morfeas_DBUS_proxy: Parsing of request's argument Failed");
	if(property_exists($arg, "handler_type") && property_exists($arg, "dev_name") && property_exists($arg, "method") && property_exists($arg, "contents"))
	{
		$dbus = new Dbus(Dbus::BUS_SYSTEM, false);
		//$dbus->waitLoop(1);
		$Bus_name = "org.freedesktop.Morfeas.".$arg->handler_type .".".$arg->dev_name;
		$Interface = "Morfeas.".$arg->handler_type .".".$arg->dev_name;
		$proxy = $dbus->createProxy($Bus_name, "/", $Interface);
		$contents_str = str_replace("\"", "\\\"", json_encode($arg->contents));
		eval("\$ans= \$proxy->". $arg->method ."(\"".$contents_str."\");");
		echo $ans;
	}
	else
		echo "Argument Error!!!";
}
?>
