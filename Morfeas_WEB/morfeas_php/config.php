<?php
	class dhcpcd 
	{
		public $mode;
		public $ip;
		public $mask;
		public $gate;
	   
		function parser($if_name) 
		{
			$dhcpd_cont=file_get_contents("/etc/dhcpcd.conf.back");
			$dhcpd_cont=explode("\n",$dhcpd_cont);
			foreach($dhcpd_cont as $key => $line)
				if(!strlen($line)||$line[0]==="#")
					unset($dhcpd_cont[$key]);
			$dhcpd_cont = array_values($dhcpd_cont);
			if($key=array_search("interface ".$if_name, $dhcpd_cont))
			{
				//$ip = 
			}
			else
				$this->mode="DHCP";
			return;
		}
	}

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
					//get and decode contents from dhcpcd.conf
					$conf = new dhcpcd();
					$conf->parser("eth0");
					
					$currConfig = new stdClass();
					$currConfig->hostname=gethostname();
					if(($currConfig->mode=$conf->mode)==='Static')
					{
						$currConfig->ip=$conf->ip;
						$currConfig->mask=$conf->mask;
						$currConfig->gate=$conf->gate;
					}
					echo json_encode($currConfig);
					return;
			}

		}
	}
	else if($requestType == 'POST')
	{
		define("mask_val",[0,128,192,224,240,248,252,254,255]);
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
			/*
			$dhcpcd_config=file_get_contents("/etc/dhcpcd.conf");
		    $dhcpcd_config = substr($dhcpcd_config, 0, strrpos($dhcpcd_config, 'interface eth0.0'));
			$dhcpcd_config = sprintf("%sinterface eth0.0\nstatic ip_address=%d.%d.%d.%d/%d\nstatic routers=%d.%d.%d.%d\nstatic domain_name_servers=%d.%d.%d.%d\n"
									  ,$dhcpcd_config,$config_file_json->eth_ip[0],$config_file_json->eth_ip[1],$config_file_json->eth_ip[2],$config_file_json->eth_ip[3]
									  ,$mask,$config_file_json->gateway[0],$config_file_json->gateway[1],$config_file_json->gateway[2],$config_file_json->gateway[3]
									  ,$config_file_json->gateway[0],$config_file_json->gateway[1],$config_file_json->gateway[2],$config_file_json->gateway[3]);
			file_put_contents("/etc/dhcpcd.conf",$dhcpcd_config);
			exec('sudo reboot');
			*/
		}
		else
			echo 'Argument Error';
	}
	http_response_code(404);
?>
