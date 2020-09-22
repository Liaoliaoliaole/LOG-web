<?php
/*
File: Supplementary.php PHP Script for decompression via LZW algorithm
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
	function charSizeAt($inp, $pos)
	{
		if(ord($inp[$pos])<=0x7F)//Check for ASCII
			return 1;
		else//Char is Unicode
		{
			$s=ord($inp[$pos]);
			$i=1;
			while(1)
			{
				if(!(($s<<=1)&0x80))
					break;
				$i++;
			}
		}
		return $i;
	};
	function unicodetoarray($inp)
	{
		$ret_arr = array();
		for($i=0, $c=0; $i<strlen($inp); $i+=$c)
		{
			$c=charSizeAt($inp, $i);
			array_push($ret_arr, substr($inp, $i, $c));
		}
		return $ret_arr;
	};

	$result = "";
	$dictionary = array();
	$data = mb_str_split($data);
	$dictOffset = mb_ord($data[0]);
	$RX_Checksum=mb_ord($data[count($data)-1]);
	$Cal_Checksum=0;

	for($i=1; $i<count($data)-1; $i++)
	{
		if(mb_ord($data[$i])<$dictOffset)
		{
			array_push($dictionary, $data[$i]);
			$result .= $data[$i];
		}
		else
		{
			$dict_pos = mb_ord($data[$i])-$dictOffset;
			if(isset($dictionary[$dict_pos]))
			{
				$result .= $dictionary[$dict_pos].$data[$i+1];
				array_push($dictionary, $dictionary[$dict_pos].$data[$i+1]);
				$i++;
			}
			else
				return NULL;
		}
	}
	foreach(unicodetoarray($result) as $num)
		$Cal_Checksum^=mb_ord($num);
	if(!($RX_Checksum^($Cal_Checksum&0xFF)))
		return $result;
	return NULL;
}
?>
