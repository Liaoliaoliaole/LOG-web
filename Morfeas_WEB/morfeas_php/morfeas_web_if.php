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
					$logstats_combined = new stdClass;
					$logstats_combined->Build_time = time();
					$logstats_combined->OPCUA_Config_xml_mod = ($logstats_combined->Build_time - filemtime($opc_ua_config_dir."OPC_UA_Config.xml"))<3;
					foreach($logstats as $logstat)
						if(preg_match("/^logstat_.+\.json$/i", $logstat))//Read only Morfeas JSON logstat files
						{	//Check if argument "TYPE" is set and if yes apply filtering.
							if(array_key_exists("TYPE", $_GET) && !strpos($logstat, $_GET["TYPE"]))
								continue;
							else
							{
								$logstats_combined->logstats_names[] = $logstat;
								$cnt=0;
								do{
									if(!($file_content = file_get_contents($ramdisk_path . '/' . $logstat)))
									{
										usleep(100);
										$cnt++;
									}
								}while(!$file_content && $cnt<10);
								if($cnt>=10)
									die("Read_Error@".$logstat);
								$logstats_combined->logstat_contents[] = json_decode($file_content);
							}
						}
					header('Content-Type: application/json');
					echo json_encode($logstats_combined);
				}
				return;
			case "logstats_names":
				if($logstats = array_diff(scandir($ramdisk_path), array('..', '.', 'Morfeas_Loggers')))
				{	//Read only Morfeas JSON logstat files
					foreach($logstats as $logstat)
						if(preg_match("/^logstat_.+\.json$/i", $logstat))
							$logstats_combined->logstats_names[] = $logstat;
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
				header('Content-Type: application/json');
				$OPCUA_Config_xml = simplexml_load_file($opc_ua_config_dir."OPC_UA_Config.xml") or die("{}");
				$OPCUA_Config_xml_to_client = array();
				foreach($OPCUA_Config_xml->children() as $channel)
					$OPCUA_Config_xml_to_client[] = $channel;
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
	if(empty($Channels->DATA))
		die("Error: \"DATA\" is Empty!!!");
	if(empty($Channels->COMMAND))
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
	$OPC_UA_Config_str = file_get_contents($opc_ua_config_dir."OPC_UA_Config.xml") or die("Error: OPC_UA_Config.xml does't found!!!");
	if(!($OPC_UA_Config = simplexml_load_string($OPC_UA_Config_str)))
	{
		echo "Errors at XML Parsing: ";
		foreach(libxml_get_errors() as $error)
			echo $error->message;
		return;
	}
	switch($Channels->COMMAND)
	{
		case 'ADD':
			$OPC_UA_Config_CHs = $OPC_UA_Config->CHANNEL;
			foreach($Channels->DATA as $Channel_to_be_add)
			{
				for($i=0; $i<count($OPC_UA_Config); $i++)
				{
					if($Channel_to_be_add->ISOChannel == $OPC_UA_Config_CHs[$i]->ISO_CHANNEL)
						die("ISOChannel: \"$Channel_to_be_add->ISOChannel\" Already exist!!!");
				}
				$newISOChannel = $OPC_UA_Config->addChild('CHANNEL');
				$newISOChannel->addChild('ISO_CHANNEL', $Channel_to_be_add->ISOChannel);
				$newISOChannel->addChild('INTERFACE_TYPE', $Channel_to_be_add->IF_type);
				$newISOChannel->addChild('ANCHOR', $Channel_to_be_add->Anchor);
				$newISOChannel->addChild('DESCRIPTION', $Channel_to_be_add->Description);
				$newISOChannel->addChild('MIN', $Channel_to_be_add->Min);
				$newISOChannel->addChild('MAX', $Channel_to_be_add->Max);
				if(property_exists($Channel_to_be_add, 'Unit'))
					$newISOChannel->addChild('UNIT', $Channel_to_be_add->Unit);
				if(property_exists($Channel_to_be_add, 'Cal_date'))
					$newISOChannel->addChild('CAL_DATE', $Channel_to_be_add->Cal_date);
				if(property_exists($Channel_to_be_add, 'Cal_period'))
					$newISOChannel->addChild('CAL_PERIOD', $Channel_to_be_add->Cal_period);
				if(property_exists($Channel_to_be_add, 'Build_date_UNIX') && property_exists($Channel_to_be_add, 'Mod_date_UNIX'))
				{
					$newISOChannel->addChild('BUILD_DATE', $Channel_to_be_add->Build_date_UNIX);
					$newISOChannel->addChild('MOD_DATE', $Channel_to_be_add->Mod_date_UNIX);
				}
			}
			break;
		case 'DEL':
			$OPC_UA_Config_cnt = count($OPC_UA_Config);
			$OPC_UA_Config_CHs = $OPC_UA_Config->CHANNEL;
			foreach($Channels->DATA as $Channel_to_be_deleted)
			{
				for($i=0; $i<$OPC_UA_Config_cnt; $i++)
				{
					if($Channel_to_be_deleted->ISOChannel == $OPC_UA_Config_CHs[$i]->ISO_CHANNEL
					   && $Channel_to_be_deleted->IF_type == $OPC_UA_Config_CHs[$i]->INTERFACE_TYPE
					   && $Channel_to_be_deleted->Anchor == $OPC_UA_Config_CHs[$i]->ANCHOR
					   && $Channel_to_be_deleted->Min == $OPC_UA_Config_CHs[$i]->MIN
					   && $Channel_to_be_deleted->Max == $OPC_UA_Config_CHs[$i]->MAX
					   && $Channel_to_be_deleted->Description == $OPC_UA_Config_CHs[$i]->DESCRIPTION)
					   {
							unset($OPC_UA_Config->CHANNEL[$i]);
							$OPC_UA_Config_cnt--;
					   }
				}
			}
			break;
		case 'MOD':
			$OPC_UA_Config_CHs = $OPC_UA_Config->CHANNEL;
			foreach($Channels->DATA as $Channel_to_be_mod)
			{
				for($i=0; $i<count($OPC_UA_Config); $i++)
				{
					if($Channel_to_be_mod->ISOChannel == $OPC_UA_Config_CHs[$i]->ISO_CHANNEL &&
					   $Channel_to_be_mod->IF_type == $OPC_UA_Config_CHs[$i]->INTERFACE_TYPE)
						break;
				}
				if($i<count($OPC_UA_Config))
				{
					$OPC_UA_Config_CHs[$i]->ANCHOR = $Channel_to_be_mod->Anchor;
					$OPC_UA_Config_CHs[$i]->MIN = $Channel_to_be_mod->Min;
					$OPC_UA_Config_CHs[$i]->MAX = $Channel_to_be_mod->Max;
					$OPC_UA_Config_CHs[$i]->DESCRIPTION = $Channel_to_be_mod->Description;
					if(property_exists($OPC_UA_Config_CHs[$i], 'BUILD_DATE') && property_exists($Channel_to_be_mod, 'Mod_date_UNIX'))
					{
						if(!property_exists($OPC_UA_Config_CHs[$i], 'MOD_DATE'))
							$OPC_UA_Config_CHs[$i]->addChild('MOD_DATE', $Channel_to_be_mod->Mod_date_UNIX);
						else
							$OPC_UA_Config_CHs[$i]->MOD_DATE = $Channel_to_be_mod->Mod_date_UNIX;
					}
					if($Channel_to_be_mod->IF_type !== "SDAQ")
					{
						if(property_exists($Channel_to_be_mod, 'Unit'))
							if(!property_exists($OPC_UA_Config_CHs[$i], 'UNIT'))
								$OPC_UA_Config_CHs[$i]->addChild('UNIT', $Channel_to_be_mod->Unit);
							else
								$OPC_UA_Config_CHs[$i]->UNIT = $Channel_to_be_mod->Unit;
						if(property_exists($Channel_to_be_mod, 'Cal_date'))
							if(!property_exists($OPC_UA_Config_CHs[$i], 'CAL_DATE'))
								$OPC_UA_Config_CHs[$i]->addChild('CAL_DATE', $Channel_to_be_mod->Cal_date);
							else
								$OPC_UA_Config_CHs[$i]->CAL_DATE = $Channel_to_be_mod->Cal_date;
						if(property_exists($Channel_to_be_mod, 'Cal_period'))
							if(!property_exists($OPC_UA_Config_CHs[$i], 'CAL_PERIOD'))
								$OPC_UA_Config_CHs[$i]->addChild('CAL_PERIOD', $Channel_to_be_mod->Cal_period);
							else
								$OPC_UA_Config_CHs[$i]->CAL_PERIOD = $Channel_to_be_mod->Cal_period;
					}
				}
				else
					die("ISOChannel: \"$Channel_to_be_mod->ISOChannel\" with type:\"$OPC_UA_Config_CHs[$i]->INTERFACE_TYPE\" Does not exist!!!");
			}
			break;
		default: die("Error: Unknown Command!!!");
	}
	//Save OPC_UA_Config.xml formatted.
	$dom = new DOMDocument('1.0');
	$dom->preserveWhiteSpace = false;
	$dom->formatOutput = true;
	$dom->loadXML($OPC_UA_Config->asXML());
	$dom->save($opc_ua_config_dir."OPC_UA_Config.xml") or die("Error: Unable to write OPC_UA_Config.xml file!!!");
	header('Content-Type: application/json');
	die("{\"success\":true}");
}
http_response_code(404);
?>
