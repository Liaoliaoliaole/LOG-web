<?php
	require("../Morfeas_env.php");
	require("./Supplementary.php");
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
				return;
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
					}
					if($gateway =substr($eth_if[$key+3],strpos($eth_if[$key+3],"gateway")+strlen("gateway ")))
						$this->gate=ip2long($gateway);
				}
			}
			return;
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
	function new_ip($new_config, $eth_if_name)
	{
		isset($eth_if_name) or die('$eth_if_name is Undefined!!!!');
		isset($new_config) or die('$new_config is Undefined!!!!');
		property_exists($new_config,"ip")or die('$new_config->ip is Undefined!!!!');
		property_exists($new_config,"mask")or die('$new_config->mask is Undefined!!!!');
		property_exists($new_config,"gate")or die('$new_config->gate is Undefined!!!!');
		if($new_config->mask<4||$new_config->mask>30)
			die("Subnet mask is Invalid!!!");
		$new_mask=0;
		while($new_config->mask)
		{
			$new_mask>>=1;
			$new_mask|=1<<31;
			$new_config->mask--;
		}
		if(!($new_config->ip&~$new_mask)||($new_config->ip|$new_mask)===0xFFFFFFFF)
			die('IP address is Invalid!!!');
		$new_ip=long2ip($new_config->ip);
		$new_mask=long2ip($new_mask);
		if(!$new_config->gate||!$new_config->gate === 0xFFFFFFFF)
			die('Gateway is Invalid!!!');
		$new_gate=long2ip($new_config->gate);

		$if_config= "auto $eth_if_name\n".
					"iface $eth_if_name inet static\n".
					"address $new_ip\n".
					"netmask $new_mask\n".
					"gateway $new_gate\n".
					"dns-nameservers 4.4.4.4\n".
					"dns-nameservers 8.8.8.8\n";
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
				case 'getCurConfig':
					$conf = new eth_if_config();
					$conf->parser($eth_if_name);
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
		$new_config_json = decompress($RX_data) or die("Error: Decompressing of ISOChannels failed");
		$new_config = json_decode($new_config_json) or die("Error: JSON Decode of ISOChannels failed");

		if(property_exists($new_config,"hostname"))
		{
			new_hostname($new_config->hostname);
			exec("sudo hostname $new_config->hostname");
			exec('sudo systemctl restart networking.service');
		}
		if(property_exists($new_config,"mode"))
		{
			if($new_config->mode==="Static")
			{
				new_ip($new_config, $eth_if_name);
				exec('sudo systemctl restart networking.service');
			}
			else if($new_config->mode==="DHCP")
			{
				is_writable("/etc/network/interfaces.d/$eth_if_name") or die("Permission error @ /etc/network/interfaces.d/$eth_if_name");
				unlink("/etc/network/interfaces.d/$eth_if_name") or die("Can not remove /etc/network/interfaces.d/$eth_if_name");
				exec('sudo reboot');
			}
		}
		if(property_exists($new_config,"ntp"))
		{
			new_ntp($new_config->ntp);
			exec('sudo systemctl restart systemd-timesyncd.service');
		}
		header('Content-Type: application/json');
		echo '{"report":"Okay"}';
		return;
	}
	http_response_code(404);
?>
