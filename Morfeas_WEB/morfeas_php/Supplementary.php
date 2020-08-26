<?php
/*
File: LWZ.php PHP Script for decompression via LZW algorithm
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

/*
//Compression function
function uncompress(data)
{
	"use strict";
	var i,
		dictOffset = 0,
		dictionary = [],
		result = "";

	dictOffset = data.charCodeAt(0);

	for (i=1; i<data.length; i++)
	{
		if(data.charAt(i)<String.fromCharCode(dictOffset))
		{
			dictionary.push(data.charAt(i));
			result += data.charAt(i);
		}
		else
		{
			result += dictionary[data.charCodeAt(i)-dictOffset] + data.charAt(i+1);
			dictionary.push(dictionary[data.charCodeAt(i)-dictOffset]+data.charAt(i+1));
			i++;

		}
	}
	return result;
}
*/

function ustrtoarray($inp)
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

	$ret_arr = array();
	for($i=0, $c=0; $i<strlen($inp); $i+=$c)
	{
		$c=charSizeAt($inp, $i);
		array_push($ret_arr, substr($inp, $i, $c));
	}
	return $ret_arr;
}

function decompress($data)
{
	mb_check_encoding($data, "UTF-8") or die("Decompression Error!!!!");
	$result = "";
	$dictionary = array();
	$data = ustrtoarray($data);

	$dictOffset = mb_ord($data[0]);

	for($i=1; $i<count($data); $i++)
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
	return $result;
}
?>
