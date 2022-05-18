<?php
/*
File: morfeas_ftp_backup.php PHP Script for connection to FTP server of backup of the Morfeas_system config in .mbl files.
Copyright (C) 12022-12023  Sam harry Tzavaras

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
	function morfeas_ftp_mbl_backup($ftp_server_hostname, $ftp_user_name, $ftp_user_pass, $dir_name, $file_name, $data)
	{
		$timeout = 200;
		$sys_hostname = $dir_name ? $dir_name : gethostname();
		$ret = true;

		if(!($ftp = ftp_connect($ftp_server_hostname, 21, $timeout)))//try to open FTP conn @Port:21
			return false;
		//Login with username and password
		if(!($login_result = ftp_login($ftp, $ftp_user_name, $ftp_user_pass)))
		{
			ftp_close($ftp);
			return false;
		}
		//Prepare the file in memory
		if(($fp = fopen('php://memory', 'w+b')))
		{
			fputs($fp, $data);
			rewind($fp);
			if(!in_array('./'.$sys_hostname, ftp_nlist($ftp, '.')))
				ftp_chdir($ftp, ftp_mkdir($ftp, $sys_hostname));
			else
				ftp_chdir($ftp, $sys_hostname);
			if(!ftp_fput($ftp, $file_name.'.mbl', $fp, FTP_BINARY))
				$ret = false;
			fclose($fp);
		}
		else
			$ret = false;
		ftp_close($ftp);
		return $ret;
	}
?>