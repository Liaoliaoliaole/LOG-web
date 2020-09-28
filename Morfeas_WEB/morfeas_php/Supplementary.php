<?php
/*
File: Supplementary.php PHP Script for decompression via LZ77 algorithm
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
//Decompression function
function decompress($data)
{
	if(!$data)
		return null;

	$Cal_Checksum=0;
	$result = "";
	$dictionary = array();

	$data = preg_split('/(?<!^)(?!$)/u', $data);
	$dictOffset = mb_ord($data[0]);
	$RX_Checksum = mb_ord($data[count($data)-1]);

	for($i=1; $i<count($data)-1; $i++)
	{
		$new_dict_entry = new stdClass();
		if(($code=mb_ord($data[$i]))<$dictOffset)
		{
			$new_dict_entry->data = $data[$i];
			$new_dict_entry->num = $code;
		}
		else
		{
			$dict_pos = $code-$dictOffset;
			if(isset($dictionary[$dict_pos]))
			{
				$new_dict_entry->data = ($dictionary[$dict_pos]->data).$data[$i+1];
				$new_dict_entry->num = ($dictionary[$dict_pos]->num)^mb_ord($data[$i+1]);
				$i++;
			}
			else
				die("Server: Fatal error at decompression!!!");
		}
		$dictionary[] = $new_dict_entry;
		$result .= $new_dict_entry->data;
		$Cal_Checksum ^= $new_dict_entry->num;
	}
	$Cal_Checksum &= 0xFF;
	if(!($RX_Checksum^$Cal_Checksum))
		return $result;
	else
		die("Server: Checksum error at decompression! ($RX_Checksum!=$Cal_Checksum)");
}
?>
