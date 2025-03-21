<?php
/*
File: config.php PHP Script for Configuration of Morfeas_system and network.
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
	require("./morfeas_ftp_backup.php");
	function _ip2long($ip_str)
	{
		$ip=ip2long($ip_str) or die("Server: ip2long() failed!!!");
		$ip=(PHP_INT_SIZE==8&&$ip>0x7FFFFFFF)? $ip-0x100000000 : $ip;//check and convert to signed
		return $ip;
	}
	function bundle_make()
	{
		global $opc_ua_config_dir;
		$bundle=new stdClass();
		$bundle->OPC_UA_config=file_get_contents($opc_ua_config_dir."OPC_UA_Config.xml") or Die("Server: Unable to read OPC_UA_Config.xml");
		$bundle->Morfeas_config=file_get_contents($opc_ua_config_dir."Morfeas_config.xml") or Die('Server: Unable to read Morfeas_config.xml');
		$bundle->Checksum=crc32($bundle->OPC_UA_config.$bundle->Morfeas_config);
		return gzencode(json_encode($bundle));
	}
	class eth_if_config
	{
		public $mode;
		public $ip;
		public $mask;
		public $gate;
		public $dns;
		private function get_dhcp_ip($if_name)
		{	//Get configured IP
			if(exec("ip -j addr show $if_name", $output))
			{
				if(($ip_output = json_decode($output[0])) == NULL)
					return;
				$c = count($ip_output);
				for($i=0; !property_exists($ip_output[$i], 'addr_info') && $i<$c; $i++);
				if($i<$c)
				{
					$this->dhcp_ip_conf = $ip_output[$i]->addr_info[0]->local;
					$this->dhcp_prefix_conf = $ip_output[$i]->addr_info[0]->prefixlen;
				}
			}
			//Get configured Gateway
			unset($output);
			if(exec("ip -j route", $output))
			{
				if(($ip_output = json_decode($output[0])) == NULL)
					return;
				$c = count($ip_output);
				for($i=0; !property_exists($ip_output[$i], 'gateway') && $i<$c; $i++);
				if($i<$c)
					$this->dhcp_gate_conf = $ip_output[$i]->gateway;
			}
		}
		function parser($if_name)
		{
			if(!$if_name)
				die('Server: Argument $eth_if_name is NULL!!!');
			if(!file_exists('/sys/class/net/'.$if_name))
				die("Server: Adapter \"$if_name\" does not exist!!!");
			if(!file_exists('/etc/network/interfaces.d/'.$if_name))
			{
				$this->mode="DHCP";
				$this->get_dhcp_ip($if_name);
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
						$this->ip=_ip2long($ip_str);
						if($netmask=substr($eth_if[$key+2],strpos($eth_if[$key+2],"netmask")+strlen("netmask ")))
						{
							$netmask=_ip2long($netmask);
							$this->mask=0;
							while($netmask&(1<<31))
							{
								$this->mask++;
								$netmask<<=1;
							}
							if($gateway=substr($eth_if[$key+3],strpos($eth_if[$key+3],"gateway")+strlen("gateway ")))
								$this->gate=_ip2long($gateway);
							if($dns_addrs =substr($eth_if[$key+4],strpos($eth_if[$key+4],"dns-nameservers")+strlen("dns-nameservers ")))
								$this->dns=_ip2long(explode(' ', $dns_addrs)[0]);
							return 1;
						}
					}
					else
					{
						$this->mask=(int)substr($ip_str,$pos+1);
						$this->ip=_ip2long(substr($ip_str,0,$pos));
						if($gateway =substr($eth_if[$key+2],strpos($eth_if[$key+2],"gateway")+strlen("gateway ")))
							$this->gate=_ip2long($gateway);
						if($dns_addrs =substr($eth_if[$key+3],strpos($eth_if[$key+3],"dns-nameservers")+strlen("dns-nameservers ")))
							$this->dns=_ip2long(explode(' ', $dns_addrs)[0]);
					}
				}
				else
					return null;
			}
			else if(array_search("iface $if_name inet dhcp", $eth_if))
			{
				$this->mode="DHCP";
				$this->get_dhcp_ip($if_name);
			}
			else
				return null;
			return 1;
		}
	}
	function getMACaddr($if_name)
	{
		if(exec("ip -j link show $if_name", $output))
		{
			if(($ip_output = json_decode($output[0])) == NULL)
				return NULL;
			$c = count($ip_output);
			for($i=0; !property_exists($ip_output[$i], 'address') && $i<$c; $i++);
			if($i<$c)
				return $ip_output[$i]->address;
		}
		return NULL;
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
				return _ip2long($ntp_ip_str);
			}
		}
		return null;
	}
	function getCANifs()
	{
		$ret = Array();
		exec("SDAQ_worker -l", $names);
		if(($lim=count($names)))
		{
			for($i=0;$i<$lim;$i++)
			{
				$ret[$i]=new stdClass();
				$ret[$i]->if_Name=$names[$i];
				$if_details=Array();
				if(!exec("ip -det link show ".$names[$i], $if_details))
					continue;
				$if_details = preg_replace('/\s{2,}/', '', $if_details);
				if(explode(' ', $if_details[2])[0]==="can")
					$ret[$i]->bitrate=(int)explode(' ', $if_details[3])[1];
				else
					unset($ret[$i]);
			}
			return array_values($ret);
		}
		else
			return NULL;
	}
	function new_hostname($new_hostname)
	{
		isset($new_hostname) or die('Server: $new_hostname is Undefined!!!!');
		$cur_hostname = gethostname();
		if(!strlen($new_hostname)||strlen($new_hostname)>=16||
		   preg_match('/[\\/:*?"<>|. ]|^-|-$|^\d/',$new_hostname))
			die("Hostname is Invalid!!!\n".
				  "Must contain ONLY:\n".
				  "Latin letters and numbers");
		if($cur_hostname === $new_hostname)
			return;

		file_put_contents('/etc/hostname', $new_hostname)or die("Server: /etc/hostname in Unwritable");
		if(($hosts=file_get_contents('/etc/hosts'))==False) die("Server: /etc/hosts is Unreadable");
		$hosts=str_replace($cur_hostname, $new_hostname, $hosts);
		file_put_contents('/etc/hosts',$hosts)or die("Server: /etc/hosts file is Unwritable");
	}
	function new_ip_conf($new_config, $eth_if_name)
	{
		isset($eth_if_name) or die('Server: $eth_if_name is Undefined!!!!');
		isset($new_config) or die('Server: $new_config is Undefined!!!!');
		if(!($new_config->mode==="DHCP"||$new_config->mode==="Static"))
			die('Server: Value of $new_config->mode is Invalid!!!!');
		if($new_config->mode==="DHCP")
		{
			//unlink("/etc/network/interfaces.d/$eth_if_name") or die('Server: Unable to configure network!!!');
			//return;
			$if_config= "auto $eth_if_name\n".
						"allow-hotplug $eth_if_name\n".
					    "iface $eth_if_name inet dhcp\n";
		}
		else
		{
			property_exists($new_config,"ip")or die('$new_config->ip is Undefined!!!!');
			property_exists($new_config,"mask")or die('$new_config->mask is Undefined!!!!');
			property_exists($new_config,"gate")or die('$new_config->gate is Undefined!!!!');
			if($new_config->mask<4||$new_config->mask>30)
				die("Server: Subnet mask is Invalid!!!");
			$new_mask=$new_config->mask;
			$bit_mask=0;
			while($new_mask)
			{
				$bit_mask>>=1;
				$bit_mask|=1<<31;
				$new_mask--;
			}
			if(!($new_config->ip&~$bit_mask)||($new_config->ip|$bit_mask)===0xFFFFFFFF)
				die('Server: IP address is Invalid!!!');
			$new_ip=long2ip($new_config->ip)or die("Server: Failure at IP conversion!!!");
			$new_mask=$new_config->mask;
			if(!$new_config->gate||!$new_config->gate === 0xFFFFFFFF ||
			   !($new_config->gate&~$bit_mask)||($new_config->gate|$bit_mask)===0xFFFFFFFF)
				die('Server: Gateway is Invalid!!!');
			$new_gate=long2ip($new_config->gate)or die("Server: Failure at IP conversion!!!");
			$new_dns=$new_gate;
			if(property_exists($new_config,"dns"))
				$new_dns=long2ip($new_config->dns)or die("Server: Failure at IP conversion!!!");

			$if_config= "auto $eth_if_name\n".
						"allow-hotplug $eth_if_name\n".
						"iface $eth_if_name inet static\n".
						"address $new_ip/$new_mask\n".
						"gateway $new_gate\n".
						"dns-nameservers $new_dns 127.0.0.1 8.8.8.8\n";
		}
		file_put_contents("/etc/network/interfaces.d/$eth_if_name",$if_config)or die("Server: Can't create new Network configuration file!!!");
	}
	function new_ntp($new_ntp)
	{
		isset($new_ntp) or die('Server: $new_ntp is Undefined!!!!');
		if(!$new_ntp||$new_ntp===0xFFFFFFFF)
			die("Server: NTP IP address is invalid!!!");
		$new_ntp=long2ip($new_ntp) or die("Server: Failure at IP conversion!!!");
		if(!($timesyncd_config_file=file_get_contents("/etc/systemd/timesyncd.conf")))
			die("Server: Unable to read /etc/systemd/timesyncd.conf !!!");
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
		file_put_contents('/etc/systemd/timesyncd.conf',$timesyncd_config_file)or die("Server: Can't write timesyncd.conf!!!");
	}
	function new_CANif_config($new_CAN_if)
	{
		$CAN_if_config_file = file_get_contents('/etc/network/interfaces.d/'.strtolower($new_CAN_if->if_Name))or die("Server: Can't read configuration file for ".$new_CAN_if->if_Name);
		$CAN_if_config=explode("\n",$CAN_if_config_file);
		$pre_up_line_comp=explode(" ",$CAN_if_config[2]);
		$bitrate_key=array_search("bitrate", $pre_up_line_comp)or die("Server: Error Unable to find bitrate_key!!!");
		$pre_up_line_comp[$bitrate_key+1]=$new_CAN_if->bitrate;
		$CAN_if_config[2]=implode(" ",$pre_up_line_comp);
		$CAN_if_config_file=implode("\n",$CAN_if_config);
		file_put_contents('/etc/network/interfaces.d/'.strtolower($new_CAN_if->if_Name), $CAN_if_config_file)or die("Server: Can't write configuration file for ".$new_CAN_if->if_Name);
	}
	function new_morfeas_config_val($new_morfeas_config)
	{
		$opc_ua_server_det=false; $comp_ord=0; $pre_comp_ord=0;
		$new_morfeas_config=simplexml_import_dom($new_morfeas_config);
		if($new_morfeas_config->getName()!=="COMPONENTS")
			return false;
		foreach ($new_morfeas_config->children() as $comp)
		{
			if(!$comp->count())
				die("Server: Component\"".$comp->getName()."\" have no children nodes");
			switch($comp->getName())
			{
				case "OPC_UA_SERVER":
					$comp_ord=1;
					$opc_ua_server_det=true;
					if($comp->children()[0]->getName()!=='APP_NAME')
						die("Server: OPC_UA_SERVER->APP_NAME missing");
					if(!strlen($comp->children()[0]))
						die("Server: OPC_UA_SERVER->APP_NAME is empty");
					break;
				case "SDAQ_HANDLER":
				case "NOX_HANDLER":
					switch($comp->getName())
					{
						case "SDAQ_HANDLER": $comp_ord=2; break;
						case "NOX_HANDLER": $comp_ord=6; break;
					}
					if($comp->children()[0]->getName()!=='CANBUS_IF')
						die("Server: Component\"".$comp->getName()."\" have invalid child nodes");
					if(!strlen($comp->children()[0]))
						die("Server: ".$comp->getName()."->CANBUS_IF is empty");
					break;
				case "MDAQ_HANDLER":
				case "IOBOX_HANDLER":
				case "MTI_HANDLER":
					switch($comp->getName())
					{
						case "MDAQ_HANDLER": $comp_ord=3; break;
						case "IOBOX_HANDLER": $comp_ord=4; break;
						case "MTI_HANDLER": $comp_ord=5; break;
					}
					if($comp->children()[0]->getName()!=='DEV_NAME'&&$comp->children()[1]->getName()!=='IPv4_ADDR')
						die("Server: Component\"".$comp->getName()."\" have invalid child nodes");
					if(!strlen($comp->children()[0]))
						die("Server: ".$comp->getName()."->DEV_NAME is empty");
					if(preg_match("/[^[a-zA-Z0-9_-]]*/", $comp->children()[0]))
						die("Server: ".$comp->getName()."->DEV_NAME contains invalid characters");
					if(!strlen($comp->children()[1]))
						die("Server: ".$comp->getName()."->IPv4_ADDR is empty");
					if(!filter_var($comp->children()[1], FILTER_VALIDATE_IP))
						die("Server: ".$comp->getName()."->IPv4_ADDR is not a valid IPv4 address");
					break;
				default:
					return false;
			}
			if($pre_comp_ord<=$comp_ord)
				$pre_comp_ord=$comp_ord;
			else
				die("Server: validation failure \"COMPONENTS\" is not in order!!!");
		}
		if(!$opc_ua_server_det)
			die("Server: \"OPC_UA_SERVER\" component missing");
		return true;
	}

	$morfeas_flag_file = "/var/run/morfeas/update_needed";// A path for the update flag

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
					header('Content-Type: Morfeas_bundle');
					header("Content-Disposition: attachment; filename=\"$bundle_name.mbl\"");
					header('Content-Length: '.strlen($bundle_content));
					echo $bundle_content;
					return;
				case 'getISOstandard_file':
					$ISO_STD_cont=file_get_contents($opc_ua_config_dir."ISOstandard.xml") or Die("Unable to read ISOstandard.xml");
					$ISO_STD_name='ISOstandard_'.gethostname().'_'.date("Y_d_m_G_i_s");
					header('Content-Description: File Transfer');
					header('Content-Type: Morfeas_bundle');
					header("Content-Disposition: attachment; filename=\"$ISO_STD_name.xml\"");
					header('Content-Length: '.strlen($ISO_STD_cont));
					echo $ISO_STD_cont;
					return;
				case 'getCurConfig':
					$conf = new eth_if_config();
					$conf->parser($eth_if_name) or Die("Server: Parsing of configuration file failed!!!");
					$currConfig = new stdClass();
					$currConfig->hostname=gethostname();
					if(($mac=getMACaddr($eth_if_name)))
						$currConfig->mac=$mac;
					if(($currConfig->mode=$conf->mode)==='Static')
					{
						$currConfig->ip=$conf->ip;
						$currConfig->mask=$conf->mask;
						$currConfig->gate=$conf->gate;
						$currConfig->dns=$conf->dns;
					}
					else
					{
						if(isset($conf->dhcp_ip_conf))
							$currConfig->dhcp_ip_conf=$conf->dhcp_ip_conf;
						if(isset($conf->dhcp_prefix_conf))
							$currConfig->dhcp_prefix_conf=$conf->dhcp_prefix_conf;
						if(isset($conf->dhcp_gate_conf))
							$currConfig->dhcp_gate_conf=$conf->dhcp_gate_conf;
					}
					$currConfig->ntp=get_timesyncd_ntp();
					if(($CAN_ifs=getCANifs()))
						$currConfig->CAN_ifs=$CAN_ifs;
					if(file_exists($opc_ua_config_dir."FTP_backup_conf.json") && filesize($opc_ua_config_dir."FTP_backup_conf.json"))
					{
						$FTP_backup_conf=file_get_contents($opc_ua_config_dir.'FTP_backup_conf.json');
						if(!($FTP_backup_conf=json_decode($FTP_backup_conf)))
						{
							exec("rm -f $opc_ua_config_dir/FTP_backup_conf.json");
							die("Server: JSON Decode of FTP_backup_conf failed\n FTP_backup_conf.json removed!!!");
						}
						if(isset($FTP_backup_conf->addr, $FTP_backup_conf->username, $FTP_backup_conf->password))
						{
							$currConfig->FTP_backup_server = new stdClass();
							$currConfig->FTP_backup_server->host = $FTP_backup_conf->addr;
							$currConfig->FTP_backup_server->user = $FTP_backup_conf->username;
							$currConfig->FTP_backup_server->pass = $FTP_backup_conf->password;
							if(property_exists($FTP_backup_conf, "dir_name") && isset($FTP_backup_conf->dir_name))
								$currConfig->FTP_backup_server->dir_name = $FTP_backup_conf->dir_name;
						}
					}
					header('Content-Type: application/json');
					echo json_encode($currConfig);
					return;
				case 'timedatectl':
					exec("timedatectl timesync-status", $ret_str);
					echo implode("<br>",$ret_str);
					return;
				case 'getMorfeasConfig':
					$doc = new DOMDocument('1.0');
					$doc->load($opc_ua_config_dir."Morfeas_config.xml",LIBXML_NOBLANKS) or die("Server: Fail to read Morfeas_config.xml");
					$doc->formatOutput = false;
					header('Content-Type: Morfeas_config/xml');
					echo $doc->saveXML();
					return;
				case 'getISOstandard':
					$doc = new DOMDocument('1.0');
					$doc->load($opc_ua_config_dir."ISOstandard.xml",LIBXML_NOBLANKS) or die("Server: Fail to read ISOstandard.xml");
					$doc->formatOutput = false;
					header('Content-Type: ISOstandard/xml');
					echo $doc->saveXML();
					return;
				case 'getCANifs_names':
					exec("SDAQ_worker -l", $ret_str);
					header('Content-Type: getCANifs_names/json');
					echo json_encode($ret_str);
					return;
			}
		}
	}
	else if($requestType == 'POST')
	{
		$RX_data = file_get_contents('php://input');
		switch($_SERVER["CONTENT_TYPE"])
		{
			case "net_conf":
				$data = decompress($RX_data) or die("Server: Decompressing of ISOChannels failed");
				$new_config = json_decode($data) or die("Server: JSON Decode of ISOChannels failed");
				if(property_exists($new_config,"hostname"))
				{
					new_hostname($new_config->hostname);
					exec("sudo hostname $new_config->hostname");
					exec('sudo systemctl restart networking.service');
				}
				if(property_exists($new_config,"mode"))
				{
					isset($eth_if_name) or die('Server: $eth_if_name is Undefined!!!');
					new_ip_conf($new_config, $eth_if_name);
					exec('sudo systemctl restart networking.service');
				}
				if(property_exists($new_config,"ntp"))
				{
					new_ntp($new_config->ntp);
					exec('sudo systemctl restart systemd-timesyncd.service');
				}
				if(property_exists($new_config,"CAN_ifs"))
				{
					if(count($new_config->CAN_ifs))
					{
						foreach($new_config->CAN_ifs as $CAN_if)
						{
							new_CANif_config($CAN_if);
							$CAN_if_name=strtolower($CAN_if->if_Name);$CAN_if_bitrate=$CAN_if->bitrate;
							exec("sudo ip link set $CAN_if_name down");
							exec("sudo ip link set $CAN_if_name up type can bitrate $CAN_if_bitrate");
						}
						exec('sudo systemctl restart Morfeas_system.service');
					}
				}
				if(property_exists($new_config,"FTP_backup_server"))
				{
					if(($FTP_backup_conf=json_encode($new_config->FTP_backup_server))!==false)
					{
						if($FTP_backup_conf === '"delete"')
							exec("rm -f $opc_ua_config_dir/FTP_backup_conf.json");
						else if(strlen($FTP_backup_conf))
							file_put_contents($opc_ua_config_dir."FTP_backup_conf.json", $FTP_backup_conf) or die("Error: Can't write FTP_backup_conf.json!!!");
					}
				}
				header('Content-Type: application/json');
				echo '{"report":"Okay"}';
				return;
			case "Morfeas_config":
				$new_morfeas_config = new DOMDocument; $local_Morfeas_config = new DOMDocument;
				$data = decompress($RX_data) or die("Server: Decompressing of Morfeas_config failed");
				$new_morfeas_config->loadXML($data) or die("Server: XML Parsing error at Morfeas_config");
				$new_morfeas_config->formatOutput = true;
				new_morfeas_config_val($new_morfeas_config) or die("Server: Morfeas_config Validation Error");

				$local_Morfeas_config->load($opc_ua_config_dir."Morfeas_config.xml") or die("Server: Failure on reading of Local Morfeas_config.xml");
				$local_Morfeas_config->preserveWhiteSpace = false;
				$local_Morfeas_config->formatOutput = true;

				$local_Morfeas_config->documentElement->removeChild($local_Morfeas_config->getElementsByTagName('COMPONENTS')[0]);
				$new_config=$local_Morfeas_config->importNode($new_morfeas_config->documentElement, true);
				$local_Morfeas_config->documentElement->appendChild($new_config);
				$local_Morfeas_config->loadXML($local_Morfeas_config->saveXML());
				$local_Morfeas_config->save($opc_ua_config_dir.'Morfeas_config.xml') or die('Server: Unable to write Morfeas_config.xml');
				//exec('rm -fr /mnt/ramdisk/Morfeas_Loggers/*');
				exec('sudo systemctl restart Morfeas_system.service');
				if(file_exists($opc_ua_config_dir."FTP_backup_conf.json") && filesize($opc_ua_config_dir."FTP_backup_conf.json"))
				{
					$FTP_backup_conf=file_get_contents($opc_ua_config_dir."FTP_backup_conf.json");
					$FTP_backup_conf=json_decode($FTP_backup_conf) or die("Server: JSON Decode of FTP_backup_conf failed");
					if(isset($FTP_backup_conf->addr, $FTP_backup_conf->username, $FTP_backup_conf->password))
					{
						$dir_name = "";
						if(property_exists($FTP_backup_conf, "dir_name") && isset($FTP_backup_conf->dir_name))
							$dir_name = $FTP_backup_conf->dir_name;
						if(!morfeas_ftp_mbl_backup($FTP_backup_conf->addr,
												   $FTP_backup_conf->username,
												   $FTP_backup_conf->password,
												   $dir_name,
												   gethostname().'_'.date("Y_d_m_G_i_s"),
												   bundle_make()))
							die("Error: FTP Backup Failed!!!");
					}
					else
						die("Error: FTP backup config is invalid!!!");
				}
				header('Content-Type: report/json');
				echo '{"report":"Okay"}';
				return;
			case "ISOstandard":
				$data = decompress($RX_data) or die("Server: Decompressing of ISOstandard failed");
				$dom = DOMDocument::loadXML($data) or die("Server: XML Parsing error at ISOstandard");
				$dom->formatOutput = true;
				$dom->save($opc_ua_config_dir."ISOstandard.xml") or Die("Server: Unable to Write ISOstandard.xml");
				echo "Server: New ISOstandard XML saved";
				return;
			case "Morfeas_bundle":
				$data = gzdecode($RX_data) or die("Server: Decompressing of Bundle failed");
				$bundle = json_decode($data) or die("Server: JSON Decode of Bundle failed");
				if(property_exists($bundle,"OPC_UA_config")&&
				   property_exists($bundle,"Morfeas_config")&&
				   property_exists($bundle,"Checksum"))
				{
					if (crc32($bundle->OPC_UA_config.$bundle->Morfeas_config)!==$bundle->Checksum)
						die("Server: Bundle Checksum Error!!!");
					file_put_contents($opc_ua_config_dir."OPC_UA_Config.xml",$bundle->OPC_UA_config)or Die("Server: OPC_UA_config.xml is Unwritable!!!");
					file_put_contents($opc_ua_config_dir."Morfeas_config.xml",$bundle->Morfeas_config)or Die("Server: Morfeas_config.xml is Unwritable!!!");
					exec('sudo systemctl restart Morfeas_system.service');
					echo("Server: Morfeas System Reconfigured and Restarted");
				}
				else
					die("Server: Bundle does not have valid content");
				return;
			case "FTP_backup_test":
				$data = decompress($RX_data) or die("Server: Decompression failed!!!");
				$ftp_ser_test = json_decode($data) or die("Server: JSON Decode failed!!!");
				if(property_exists($ftp_ser_test, "ftp_serv_host_val")&&$ftp_ser_test->ftp_serv_host_val&&
				   property_exists($ftp_ser_test, "ftp_serv_user_val")&&$ftp_ser_test->ftp_serv_user_val&&
				   property_exists($ftp_ser_test, "ftp_serv_pass_val")&&$ftp_ser_test->ftp_serv_pass_val)
				{
					if(!filter_var($ftp_ser_test->ftp_serv_host_val, FILTER_VALIDATE_IP))
					{
						if(gethostbyname($ftp_ser_test->ftp_serv_host_val)===$ftp_ser_test->ftp_serv_host_val)
							die("Error: Hostname can't be reached!!!");
					}
					$dir_name = "";
					if(property_exists($ftp_ser_test, "dir_name"))
						$dir_name = $ftp_ser_test->dir_name;
					if(morfeas_ftp_mbl_backup($ftp_ser_test->ftp_serv_host_val,
											  $ftp_ser_test->ftp_serv_user_val,
											  $ftp_ser_test->ftp_serv_pass_val,
											  $dir_name,
											  gethostname().'_'.date("Y_d_m_G_i_s"),
											  bundle_make()))
						die("FTP Backup success!!!");
					else
						die("Error: FTP Backup Failed!!!");
				}
				else
					die("Error: Property missing!!!");
				return;
			case "reboot":
				exec('sudo reboot');
				return;
			case "shutdown":
				exec('sudo poweroff');
				return;
			case "update_status":
				header('Content-Type: application/json');
				if (file_exists($morfeas_flag_file)) {
					echo json_encode(["update_needed" => true]);
				} else {
					echo json_encode(["update_needed" => false]);
				}
				return;
			case "check_update":
				$cmd_check = "sudo /var/www/html/morfeas_web/update.sh --check-only 2>&1";
				exec($cmd_check, $output, $return_var);			
				$debug = implode("\n", $output);			
				if ($return_var === 2) {
					$message = "Failed to check for updates. Network or server unreachable.";
					$update_needed = false;
				} elseif ($return_var === 100) {
					$update_needed = true;
					$message = "Update available.";
				} elseif ($return_var === 0) {
					$update_needed = false;
					$message = "System is already up-to-date.";
				} else {
					$update_needed = false;
					$message = "Unknown error during update check.";
				}
				header('Content-Type: application/json');
				echo json_encode([
					"update" => $update_needed,
					"message" => $message,
					"debug"  => $debug
				]);
				return;							
			case "update":
				$cmd  = "sudo /var/www/html/morfeas_web/update.sh 2>&1";
				exec($cmd, $output, $return_var);            
				$final_output = implode("\n", $output);
				exec("sudo rm -f " . escapeshellarg($morfeas_flag_file));			
				header('Content-Type: application/json');
				echo json_encode([
					"report" => $return_var === 0 ? "Update completed" : "Update failed",
					"output" => $final_output,
				]);
				return;												
		}
	}
	http_response_code(404);
?>
