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
include("../Morfeas_env.php");
define("usr_comp","COMMAND");
$ramdisk_path="/mnt/ramdisk/";

ob_start("ob_gzhandler");//Enable gzip buffering
//Disable caching
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$requestType = $_SERVER['REQUEST_METHOD'];
$loggers_names = new stdClass();
$loggers = Array();

if($requestType == "GET")
{
	if(array_key_exists("COMMAND", $_GET))
	{
		switch($_GET[usr_comp])
		{
			case "logstats":
				if($logstats = array_diff(scandir($ramdisk_path), array('..', '.', 'Morfeas_Loggers')))
				{
					$logstats = array_values($logstats);//Restore array order
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
			case "logstats_names":
				if($logstats = array_diff(scandir($ramdisk_path), array('..', '.', 'Morfeas_Loggers')))
				{
					$i = 0;
					foreach($logstats as $logstat)
						if(preg_match("/^logstat_.+\.json$/i", $logstat))//Read only Morfeas JSON logstat files
						{
							$logstats_combined->logstats_names[$i] = $logstat;
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
						$loggers_names = new stdClass();
						$loggers_names->Logger_names = $loggers;
						header('Content-Type: application/json');
						echo json_encode($loggers_names);
				}
				break;
			case "opcua_config":
				$OPCUA_Config_xml = simplexml_load_file($opc_ua_config_file . "OPC_UA_Config.xml") or die("Error: Cannot read OPC_UA_config.xml");
				$OPCUA_Config_xml_to_client = array();
				$i=0;
				foreach($OPCUA_Config_xml->children() as $channel) 
				{
					$OPCUA_Config_xml_to_client[$i] = $channel;
					$i++;
				}					
				header('Content-Type: application/json');
				echo json_encode($OPCUA_Config_xml_to_client);
				break;
			case "get_iso_codes_by_unit":
				$ISOstandars_xml = simplexml_load_file($opc_ua_config_file . "ISOstandard.xml") or die("Error: Cannot read ISOstandard.xml");
				header('Content-Type: application/json');
				$ISOstandars_xml_to_client = array();
				$i=0;
				foreach($ISOstandars_xml->points->children() as $point) 
				{
					$iso_code = $point->getName();
					$attributes = $point;
					if(array_key_exists("unit", $_GET))
					{
						if($point->unit != $_GET["unit"])
							continue;
					}
					$ISOstandars_xml_to_client[$i] = new stdClass();
					$ISOstandars_xml_to_client[$i]->iso_code = $iso_code;
					$ISOstandars_xml_to_client[$i]->attributes = $attributes;
					$i++;
				}					
				echo json_encode($ISOstandars_xml_to_client);
				break;
			default:
				echo "?";
		}
		return;
	}
}
else if($requestType == "POST")
{
	$Channels_json = file_get_contents('php://input');
	$Channels = json_decode($Channels_json) or die("Error: Decode of ISOChannels failed");
	
	$imp = new DOMImplementation;
	$dtd = $imp->createDocumentType('NODESet', '', 'Morfeas.dtd');
	$dom = $imp->createDocument('', '', $dtd);
	$dom->encoding = 'UTF-8';
	$dom->xmlVersion = '1.0';
	$dom->formatOutput = true;

	$root = $dom->createElement('NODESet');
	foreach($Channels as $Channel)
	{
		//Validate entry
		if(!(property_exists($Channel, 'ISOChannel')&&
			 property_exists($Channel, 'IF_type')&&
			 property_exists($Channel, 'Anchor')&&
			 property_exists($Channel, 'Description')&&
			 property_exists($Channel, 'Min')&&
			 property_exists($Channel, 'Max')&&
			 property_exists($Channel, 'Unit')))
			die("Entry with missing properties");
		if(!(strlen($Channel->ISOChannel)&&
			 strlen($Channel->IF_type)&&
			 strlen($Channel->Anchor)&&
			 strlen($Channel->Min)&&
			 strlen($Channel->Max)&&
			 strlen($Channel->Unit)&&
			 strlen($Channel->Description)))
			die("Entry with empty properties");
		//Load entry data from Channel to root
		$channel_node = $dom->createElement('CHANNEL');
		$channel_node_child = $dom->createElement('ISO_CHANNEL', $Channel->ISOChannel);
		$channel_node->appendChild($channel_node_child);
		$channel_node_child = $dom->createElement('INTERFACE_TYPE', $Channel->IF_type);
		$channel_node->appendChild($channel_node_child);
		$channel_node_child = $dom->createElement('ANCHOR', $Channel->Anchor);
		$channel_node->appendChild($channel_node_child);
		$channel_node_child = $dom->createElement('DESCRIPTION', $Channel->Description);
		$channel_node->appendChild($channel_node_child);
		$channel_node_child = $dom->createElement('MIN', $Channel->Min);
		$channel_node->appendChild($channel_node_child);
		$channel_node_child = $dom->createElement('MAX', $Channel->Max);
		$channel_node->appendChild($channel_node_child);
		$channel_node_child = $dom->createElement('UNIT', $Channel->Unit);
		$channel_node->appendChild($channel_node_child);
		
		$root->appendChild($channel_node);
	}
	$dom->appendChild($root);
	//print($dom->saveXML());
	header('Content-Type: application/json');
	$dom->save($opc_ua_config_file . "OPC_UA_Config.xml") or die("{\"success\":false}");
	echo "{\"success\":true}";
	return;
}
http_response_code(404);
?>
