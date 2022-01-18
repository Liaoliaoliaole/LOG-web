<?php
	define("Conf_begin_guard", '#Configure from web start');
	define("Conf_end_guard", '#Configure from web end');

	function su_file_put_contents($file_name, $contents)
	{
    	$handle = popen("sudo ./write_config " . $file_name, "w");
		fwrite($handle,$contents,strlen($contents));
		pclose($handle);
		return 1;
	}

	$requestType = $_SERVER['REQUEST_METHOD'];
	if($requestType == 'POST')
	{
		if(array_key_exists("IP_ADD", $_POST)&&array_key_exists("MASK", $_POST)&&array_key_exists("GATE", $_POST))
		{
			$config_file_json=json_decode(file_get_contents("config.json"));
			$config_file_json->eth_ip=explode(".",$_POST['IP_ADD']);
			$config_file_json->mask=explode(".",$_POST['MASK']);
			$config_file_json->gateway=explode(".",$_POST['GATE']);
			su_file_put_contents("config.json",json_encode($config_file_json));
			$interface_config=file_get_contents("interfaces");
		    $interface_config_before = substr($interface_config, 0, strrpos($interface_config, Conf_begin_guard) + strlen(Conf_begin_guard));
		    $interface_config_after = substr($interface_config, strrpos($interface_config, Conf_end_guard));
			$interface_config_new = sprintf("\niface eth0:0 inet static\n\taddress %d.%d.%d.%d\n\tnetmask %d.%d.%d.%d\n",
									  $config_file_json->eth_ip[0],$config_file_json->eth_ip[1],$config_file_json->eth_ip[2],$config_file_json->eth_ip[3],
									  $config_file_json->mask[0],$config_file_json->mask[1],$config_file_json->mask[2],$config_file_json->mask[3]);
			su_file_put_contents("interfaces",$interface_config_before . $interface_config_new . $interface_config_after);
			//exec('sudo reboot'); // to run this, the program reboot need to be added on the www-data sudoers
		}
		else
			echo 'Argument Error';
	}
?>
