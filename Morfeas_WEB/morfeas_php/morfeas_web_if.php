<?php
/*
File: Morfeas_Web_if.php PHP Script for the Morfeas_Web. Part of Morfeas_project.
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
require("./Supplementary.php");
define("usr_comp","COMMAND");
$ramdisk_path="/mnt/ramdisk/";

libxml_use_internal_errors(true);
ob_start("ob_gzhandler");//Enable gzip buffering
//Disable caching
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: report/text');

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
					$logstats_combined->Build_time = time();
					$logstats_combined->OPCUA_Config_xml_mod = ($logstats_combined->Build_time - filemtime($opc_ua_config_dir."OPC_UA_Config.xml"))<5;
					$i = 0;
					foreach($logstats as $logstat)
						if(preg_match("/^logstat_.+\.json$/i", $logstat))//Read only Morfeas JSON logstat files
						{
							$logstats_combined->logstats_names[$i] = $logstats[$i];
							$cnt=0;
							do{
								if(!($file_content = file_get_contents($ramdisk_path . '/' . $logstat)))
								{
									usleep(100);
									$cnt++;
								}
							}while(!$file_content && $cnt<10);
							if($cnt>=10)
								die("Read_Error@".$logstats[$i]);
							$logstats_combined->logstat_contents[$i] = json_decode($file_content);
							$i++;
						}
					header('Content-Type: application/json');
					echo json_encode($logstats_combined);
				}
				return;
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
				return;
			case "loggers":
				if($loggers = array_diff(scandir($ramdisk_path . "Morfeas_Loggers"), array('..', '.')))
				{
						$loggers = array_values($loggers);// restore array order
						$loggers_names = new stdClass();
						$loggers_names->Logger_names = $loggers;
						header('Content-Type: application/json');
						echo json_encode($loggers_names);
				}
				return;
			case "opcua_config":
				$OPCUA_Config_xml = simplexml_load_file($opc_ua_config_dir."OPC_UA_Config.xml") or die("{}");
				$OPCUA_Config_xml_to_client = array();
				$i=0;
				foreach($OPCUA_Config_xml->children() as $channel)
				{
					$OPCUA_Config_xml_to_client[$i] = $channel;
					$i++;
				}
				header('Content-Type: application/json');
				echo json_encode($OPCUA_Config_xml_to_client);
				return;
		}
	}
}
else if($requestType == "POST")
{
	$RX_data = file_get_contents('php://input');
	$Channels_json = decompress($RX_data) or die("Error: Decompressing of ISOChannels failed");
	$Channels = json_decode($Channels_json) or die("Error: JSON Decode of ISOChannels failed");
	//Check Properties.
	if(!property_exists($Channels, 'COMMAND'))
		die("Error: \"COMMAND\" property Missing!!!");
	if(!property_exists($Channels, 'DATA'))
		die("Error: \"DATA\" property Missing!!!");
	if(!sizeof($Channels->DATA))
		die("Error: \"DATA\" is Empty!!!");
	if(!sizeof($Channels->COMMAND))
		die("Error: \"COMMAND\" is Empty!!!");
	$c=0;
	foreach($Channels->DATA as $Channel)
	{
		if(!(property_exists($Channel, 'ISOChannel')&&
			 property_exists($Channel, 'IF_type')&&
			 property_exists($Channel, 'Anchor')&&
			 property_exists($Channel, 'Description')&&
			 property_exists($Channel, 'Min')&&
			 property_exists($Channel, 'Max'))
		  )
			die("DATA[$c] have missing properties");
		if(!(strlen($Channel->ISOChannel)&&
			 strlen($Channel->IF_type)&&
			 strlen($Channel->Anchor)&&
			 strlen($Channel->Min)&&
			 strlen($Channel->Max)&&
			 strlen($Channel->Description))
		  )
			die("DATA[$c] have empty properties");
		$c++;
	}
	$OPC_UA_Config_str = readfile($opc_ua_config_dir."OPC_UA_Config.xml") or die("Error: OPC_UA_Config.xml does't found!!!");
	if(!($OPC_UA_Config = simplexml_load_string(xml_str)))
	{
		echo "Error at XML Parsing: ";
		foreach(libxml_get_errors() as $error)
			echo "<br>", $error->message;
		return;
	}
	switch($Channels->COMMAND)
	{
		case 'ADD':
			foreach($Channels->DATA as $Channel)
			{

			}
			if(($ret = add_channels($Channels))
				die("Error: ADD command failed with $ret");
			break;
		case 'DEL':
			if(($ret = mod_channels($Channels))
				die("Error: DEL command failed with $ret");
			break;
		case 'MOD':
			if(($ret = del_channels($Channels))
				die("Error: MOD command failed with $ret");
			break;
		default:
			die("Error: Unknown Command!!!");
	}
	OPC_UA_Config->asXML($opc_ua_config_dir."OPC_UA_Config.xml") or die("Error: Unable to write OPC_UA_Config.xml file!!!");
	header('Content-Type: application/json');
	die("{\"success\":true}");
	/*
	$imp = new DOMImplementation;
	$dtd = $imp->createDocumentType('NODESet', '', 'Morfeas.dtd');
	$dom = $imp->createDocument('', '', $dtd);
	$dom->encoding = 'UTF-8';
	$dom->xmlVersion = '1.0';
	$dom->formatOutput = true;

	$root = $dom->createElement('NODESet');
	if(sizeof($Channels->DATA))//If there is data
	{
		$data_num=0;
		foreach($Channels->DATA as $Channel)
		{
			//Validate entry
			if(!(property_exists($Channel, 'ISOChannel')&&
				 property_exists($Channel, 'IF_type')&&
				 property_exists($Channel, 'Anchor')&&
				 property_exists($Channel, 'Description')&&
				 property_exists($Channel, 'Min')&&
				 property_exists($Channel, 'Max')))
				die("Entry (".$data_num.") have missing properties");
			if(!(strlen($Channel->ISOChannel)&&
				 strlen($Channel->IF_type)&&
				 strlen($Channel->Anchor)&&
				 strlen($Channel->Min)&&
				 strlen($Channel->Max)&&
				 strlen($Channel->Description)))
				die("Entry (".$data_num.") have empty properties");
			//Load entry data from Channel to root
			$channel_node = $dom->createElement('CHANNEL');
			$channel_node_child = $dom->createElement('ISO_CHANNEL', $Channel->ISOChannel);
			$channel_node->appendChild($channel_node_child);
			$channel_node_child = $dom->createElement('INTERFACE_TYPE', $Channel->IF_type);
			$channel_node->appendChild($channel_node_child);
			$channel_node_child = $dom->createElement('ANCHOR', $Channel->Anchor);
			$channel_node->appendChild($channel_node_child);
			$chswitchannel_node_child = $dom->createElement('DESCRIPTION', $Channel->Description);
			$channel_node->appendChild($channel_node_child);
			$channel_node_child = $dom->createElement('MIN', $Channel->Min);
			$channel_node->appendChild($channel_node_child);
			$channel_node_child = $dom->createElement('MAX', $Channel->Max);
			$channel_node->appendChild($channel_node_child);
			if(property_exists($Channel, 'Unit') && strlen($Channel->Unit))
			{
				$channel_node_child = $dom->createElement('UNIT', $Channel->Unit);
				$channel_node->appendChild($channel_node_child);
			}
			$root->appendChild($channel_node);
			$data_num++;
		}
	}
	$dom->appendChild($root);
	//print($dom->saveXML());
	$dom->save($opc_ua_config_dir."OPC_UA_Config.xml") or die("Error on OPC_UA_Config.xml write");
	header('Content-Type: application/json');
	die("{\"success\":true}");
	*/
}
http_response_code(404);

function add_channels($Channels)
{
}
function mod_channels($Channels)
{
}
function del_channels($Channels)
{
}
?>
