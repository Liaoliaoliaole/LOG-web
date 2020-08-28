<?php
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
			if($key=array_search('iface '.$if_name.' inet static', $eth_if))
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
	include("../Morfeas_env.php");
	include("./Supplementary.php");

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
				case "getCurConfig":
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
			}
		}
	}
	else if($requestType == 'POST')
	{
		$RX_data = file_get_contents('php://input');
		$new_config_json = decompress($RX_data) or die("Error: Decompressing of ISOChannels failed");
		$new_config = json_decode($new_config_json) or die("Error: JSON Decode of ISOChannels failed");
		print_r($new_config);
		echo long2ip($new_config->ip);
		return;
		/*
		if(array_key_exists("IP_ADD", $_POST)&&array_key_exists("MASK", $_POST)&&array_key_exists("GATE", $_POST)&&array_key_exists("PORT", $_POST))
		{
			$config_file_json=json_decode(file_get_contents("config.json"));
			$config_file_json->eth_ip=explode(".",$_POST['IP_ADD']);
			$config_file_json->mask=explode(".",$_POST['MASK']);
			$config_file_json->gateway=explode(".",$_POST['GATE']);
			$config_file_json->modbus_tcp_port=intval($_POST['PORT']);
			$config_file_json->max_con=intval($_POST['MAX_CONN']);
			$config_file_json->slave_add=intval($_POST['ADD']);
			file_put_contents("config.json",json_encode($config_file_json));// save the config file (json format)
			//change static ip, mask of rpi's eth0:0 interface
			//$mask=16+array_search($config_file_json->mask[2], $mask_val)+array_search($config_file_json->mask[3], $mask_val);
			$interface_config=file_get_contents("/etc/network/interfaces");
		    $interface_config = substr($interface_config, 0, strrpos($interface_config, 'iface eth0:0 inet static'));
			$interface_config = sprintf("%siface eth0:0 inet static\n\taddress %d.%d.%d.%d\n\tnetmask %d.%d.%d.%d\n",
									  $interface_config,
									  $config_file_json->eth_ip[0],$config_file_json->eth_ip[1],$config_file_json->eth_ip[2],$config_file_json->eth_ip[3],
									  $config_file_json->mask[0],$config_file_json->mask[1],$config_file_json->mask[2],$config_file_json->mask[3]);
									  //echo $interface_config;
			file_put_contents("/etc/network/interfaces",$interface_config);
			//add gateway on rc.local
			$interface_config=file_get_contents("/etc/rc.local");
		    $interface_config = substr($interface_config, 0, strrpos($interface_config, 'sudo route add default gw'));

			$interface_config = sprintf("%ssudo route add default gw %d.%d.%d.%d eth0:0\nexit 0\n",
									  $interface_config,
									  $config_file_json->gateway[0],
									  $config_file_json->gateway[1],
									  $config_file_json->gateway[2],
									  $config_file_json->gateway[3]);
									  echo $interface_config;
			file_put_contents("/etc/rc.local",$interface_config);
			//exec('sudo reboot');
		}
		*/
	}
	http_response_code(404);
?>
