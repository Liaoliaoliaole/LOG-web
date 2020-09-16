<?php
/*
File: config.php PHP Script for Configuration of Morfeas_system and network.
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
	require("../Morfeas_env.php");
	require("./Supplementary.php");
	function bundle_make()
	{
		global $opc_ua_config_dir;
		$bundle=new stdClass();
		$bundle->OPC_UA_config=file_get_contents($opc_ua_config_dir."OPC_UA_Config.xml");
		$bundle->Morfeas_config=file_get_contents($opc_ua_config_dir."Morfeas_config.xml");
		$bundle->Checksum=0;
		return gzencode(json_encode($bundle));
	}
	class eth_if_config
	{
		public $mode;
		public $ip;
		public $mask;
		public $gate;
		function parser($if_name)
		{
			if(!$if_name)
				die('Argument $eth_if_name is NULL!!!');
			if(!file_exists('/sys/class/net/'.$if_name))
				die("Adapter \"$if_name\" does not exist!!!");
			if(!file_exists('/etc/network/interfaces.d/'.$if_name))
			{
				$this->mode="DHCP";
				return 1;
			}
			else
				$eth_if=file_get_contents('/etc/network/interfaces.d/'.$if_name);
			$eth_if=explode("\n",$eth_if);
			foreach($eth_if as $key => $line)
			{
				$eth_if[$key]=preg_replace('/[ \t\r\n]{2,}|[ ]+$/', '', $eth_if[$key]);
				if(!strlen($line)||$line[0]==="#")
					unset($eth_if[$key]);
			}
			$eth_if = array_values($eth_if);
			if($key=array_search("iface $if_name inet static", $eth_if))
			{
				$this->mode="Static";
				if($ip_str=substr($eth_if[$key+1],strpos($eth_if[$key+1],"address")+strlen("address ")))
				{
					if(!($pos=strpos($ip_str,'/')))
					{
						$this->ip=ip2long($ip_str);
						if($netmask=substr($eth_if[$key+2],strpos($eth_if[$key+2],"netmask")+strlen("netmask ")))
						{
							$netmask=ip2long($netmask);
							$this->mask=0;
							while($netmask&(1<<31))
							{
								$this->mask++;
								$netmask<<=1;
							}
							if($gateway=substr($eth_if[$key+3],strpos($eth_if[$key+3],"gateway")+strlen("gateway ")))
							$this->gate=ip2long($gateway);
							return 1;
						}
					}
					else
					{
						$this->mask=(int)substr($ip_str,$pos+1);
						$this->ip=ip2long(substr($ip_str,0,$pos));
						if($gateway =substr($eth_if[$key+2],strpos($eth_if[$key+2],"gateway")+strlen("gateway ")))
							$this->gate=ip2long($gateway);
					}
				}
				else
					return null;
			}
			else if(array_search("iface $if_name inet dhcp", $eth_if))
				$this->mode="DHCP";
			else
				return null;
			return 1;
		}
	}
	function get_timesyncd_ntp()
	{
		if(!($timesyncd_config_file=file_get_contents("/etc/systemd/timesyncd.conf")))
			return null;
		$timesyncd_config_file=explode("\n",$timesyncd_config_file);
		foreach($timesyncd_config_file as $key=>$line)
		{
			$timesyncd_config_file[$key]=preg_replace('/[ \t\r\n]|[ ]+$/', '', $timesyncd_config_file[$key]);
			if(!strlen($line)||$line[0]==="#")
				unset($timesyncd_config_file[$key]);
		}
		$timesyncd_config_file = array_values($timesyncd_config_file);
		foreach($timesyncd_config_file as $key=>$line)
		{
			if(strpos($line,'NTP=')===0)
			{
				$ntp_ip_str=substr($line,strpos($line,"NTP=")+strlen("NTP="));
				return ip2long($ntp_ip_str);
			}
		}
		return null;
	}
	function new_hostname($new_hostname)
	{
		isset($new_hostname) or die('$new_hostname is Undefined!!!!');
		$cur_hostname = gethostname();
		if(!strlen($new_hostname)||strlen($new_hostname)>=16||
		   preg_match('/[\\/:*?"<>|. ]|^-|-$|^\d/',$new_hostname))
			die("Hostname is Invalid!!!\n".
				  "Must contain ONLY:\n".
				  "Latin letters and numbers");
		if($cur_hostname === $new_hostname)
			return;

		file_put_contents('/etc/hostname', $new_hostname)or die("/etc/hostname in Unwritable");
		if(($hosts=file_get_contents('/etc/hosts'))==False) die("/etc/hosts is Unreadable");
		$hosts=str_replace($cur_hostname, $new_hostname, $hosts);
		file_put_contents('/etc/hosts',$hosts)or die("/etc/hosts file is Unwritable");
	}
	function new_ip_conf($new_config, $eth_if_name)
	{
		isset($eth_if_name) or die('$eth_if_name is Undefined!!!!');
		isset($new_config) or die('$new_config is Undefined!!!!');
		if(!($new_config->mode==="DHCP"||$new_config->mode==="Static"))
			die('Value of $new_config->mode is Invalid!!!!');
		if($new_config->mode==="DHCP")
		{
			$if_config= "auto $eth_if_name\n".
					    "iface $eth_if_name inet dhcp\n".
						"allow-hotplug $eth_if_name\n";
		}
		else
		{
			property_exists($new_config,"ip")or die('$new_config->ip is Undefined!!!!');
			property_exists($new_config,"mask")or die('$new_config->mask is Undefined!!!!');
			property_exists($new_config,"gate")or die('$new_config->gate is Undefined!!!!');
			if($new_config->mask<4||$new_config->mask>30)
				die("Subnet mask is Invalid!!!");
			$new_mask=$new_config->mask;
			$bit_mask=0;
			while($new_mask)
			{
				$bit_mask>>=1;
				$bit_mask|=1<<31;
				$new_mask--;
			}
			if(!($new_config->ip&~$bit_mask)||($new_config->ip|$bit_mask)===0xFFFFFFFF)
				die('IP address is Invalid!!!');
			$new_ip=long2ip($new_config->ip);
			$new_mask=$new_config->mask;
			if(!$new_config->gate||!$new_config->gate === 0xFFFFFFFF)
				die('Gateway is Invalid!!!');
			$new_gate=long2ip($new_config->gate);

			$if_config= "auto $eth_if_name\n".
						"allow-hotplug $eth_if_name\n".
						"iface $eth_if_name inet static\n".
						"address $new_ip/$new_mask\n".
						"gateway $new_gate\n".
						"dns-nameservers 4.4.4.4\n".
						"dns-nameservers 8.8.8.8\n";
		}
		file_put_contents("/etc/network/interfaces.d/$eth_if_name",$if_config)or die("Can't create new Network configuration file!!!");
	}
	function new_ntp($new_ntp)
	{
		isset($new_ntp) or die('$new_ntp is Undefined!!!!');
		$new_ntp=long2ip($new_ntp);
		if(!$new_ntp||$new_ntp===0xFFFFFFFF)
			die("NTP IP address is invalid!!!");
		if(!($timesyncd_config_file=file_get_contents("/etc/systemd/timesyncd.conf")))
			die("Unable to read /etc/systemd/timesyncd.conf !!!");
		$timesyncd_config_file=explode("\n",$timesyncd_config_file);
		foreach($timesyncd_config_file as $key=>$line)
		{
			if(preg_match('/^NTP=/',$line))
			{
				$timesyncd_config_file[$key]="NTP=$new_ntp";
				break;
			}
		}
		if($key==(count($timesyncd_config_file)-1))
		{
			foreach($timesyncd_config_file as $key=>$line)
			{
				if(preg_match('/^\[Time\]/',$line))
				{
					array_splice($timesyncd_config_file, $key+1, 0, "NTP=$new_ntp");
					break;
				}
			}
		}
		$timesyncd_config_file=implode("\n",$timesyncd_config_file);
		file_put_contents('/etc/systemd/timesyncd.conf',$timesyncd_config_file)or die("Can't write timesyncd.conf!!!");
	}
	ob_start("ob_gzhandler");//Enable gzip buffering
	//Disable caching
	header('Cache-Control: no-cache, no-store, must-revalidate');
	header('Pragma: no-cache');
	header('Expires: 0');

	$requestType = $_SERVER['REQUEST_METHOD'];
	if($requestType == 'GET')
	{
		if(array_key_exists("COMMAND", $_GET))
		{
			switch($_GET["COMMAND"])
			{
				case 'getbundle':
					$bundle_content=bundle_make();
					$bundle_name=gethostname().'_'.date("Y_d_m_G_i_s");
					header('Content-Description: File Transfer');
					header('Content-Type: Mordeas_bundle');
					header("Content-Disposition: attachment; filename=\"$bundle_name.mbl\"");
					header('Content-Length: '.strlen($bundle_content));
					echo $bundle_content;
					return;
				case 'getCurConfig':
					$conf = new eth_if_config();
					$conf->parser($eth_if_name) or Die("Parsing of configuration file failed!!!");
					$currConfig = new stdClass();
					$currConfig->hostname=gethostname();
					if(($currConfig->mode=$conf->mode)==='Static')
					{
						$currConfig->ip=$conf->ip;
						$currConfig->mask=$conf->mask;
						$currConfig->gate=$conf->gate;
					}
					$currConfig->ntp=get_timesyncd_ntp();
					header('Content-Type: application/json');
					echo json_encode($currConfig);
					return;
				case 'timedatectl':
					exec("timedatectl timesync-status", $ret_str);
					echo implode("<br>",$ret_str);
					return;
				case 'getMorfeasConfig':
					$doc = new DOMDocument('1.0');
					$doc->load($opc_ua_config_dir."Morfeas_config.xml",LIBXML_NOBLANKS) or die("Fail to read Morfeas_config.xml");
					$doc->formatOutput = false;
					header('Content-Type: application/xml');
					echo $doc->saveXML();
					//print_r($doc);
					return;
			}
		}
	}
	else if($requestType == 'POST')
	{
		isset($eth_if_name) or die('$eth_if_name is Undefined!!!');
		$RX_data = file_get_contents('php://input');
		switch($_SERVER["CONTENT_TYPE"])
		{
			case "net_conf":
				$data = decompress($RX_data) or die("Error: Decompressing of ISOChannels failed");
				$new_config = json_decode($data) or die("Error: JSON Decode of ISOChannels failed");
				if(property_exists($new_config,"hostname"))
				{
					new_hostname($new_config->hostname);
					exec("sudo hostname $new_config->hostname");
					exec('sudo systemctl restart networking.service');
				}
				if(property_exists($new_config,"mode"))
				{
					new_ip_conf($new_config, $eth_if_name);
					exec('sudo systemctl restart networking.service');
				}
				if(property_exists($new_config,"ntp"))
				{
					new_ntp($new_config->ntp);
					exec('sudo systemctl restart systemd-timesyncd.service');
				}
				header('Content-Type: application/json');
				echo '{"report":"Okay"}';
				return;
			case "Morfeas_bundle":
				$data = gzdecode($RX_data) or die("Server: Decompressing of Bundle failed");
				$bundle = json_decode($data) or die("Server: JSON Decode of Bundle failed");
				if(property_exists($bundle,"OPC_UA_config")&&
				   property_exists($bundle,"Morfeas_config")&&
				   property_exists($bundle,"Checksum"))
				{
					echo $bundle->Morfeas_config;
				}
				else
					die("Server: Bundle does not have valid content");
				return;
			case "xml_comp":
				return;
		}
	}
	http_response_code(404);
?>
