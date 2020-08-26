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

function decompress($data)
{
	mb_check_encoding($data, "UTF-8") or die("Decompression Error!!!!");
	//echo strlen($data)."\n";
	$result = "";
	$dictionary = array();

	if($dictOffset = ord($data[0])>0x7F)//Check for Unicode
		$dictOffset = mb_ord(substr($data, 0, 2));

	echo "dictOffset=".$dictOffset."\n";

	for ($i=1; $i<strlen($data); $i++)
	{
		if(ord($c=$data[$i])>0x7F)//Check for Unicode
			$c=$data[++$i];

		if($c<chr($dictOffset))
		{
			array_push($dictionary, $c);
			$result .= $c;
		}
		else
		{
			$dict_pos = ord($c)-$dictOffset;
			if(isset($dictionary[$dict_pos]))
			{
				$result .= $dictionary[$dict_pos].$data[$i+1];
				array_push($dictionary, $dictionary[$dict_pos].$data[$i+1]);
				$i++;
			}
			else
			{
				echo "Error ".$c.'('.ord($c).") Pos".$i."\n";
				echo "Error offset:".($dict_pos)."\n";
				print_r($dictionary);
				return NULL;
			}
		}
	}

	print_r($dictionary);
	echo $result."\n";
	return $result;
}
?>
