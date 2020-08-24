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
//Class forked from https://rosettacode.org/wiki/LZW_compression#PHP

function LZW_decompress($com)
{
	$com = explode(",",$com);
	$i;$w;$k;$result;
	$dictionary = array();
	$entry = "";
	$dictSize = 256;

	for ($i = 0; $i < $dictSize; $i++)
		$dictionary[$i] = chr($i);

	$w = chr($com[0]);
	$result = $w;
	for ($i = 1; $i < count($com); $i++)
	{
		$k = $com[$i];
		if ($dictionary[$k])
			$entry = $dictionary[$k];
		else
		{
			if ($k === $dictSize)
				$entry = $w.$w[0];
			//else
				//return null;
		}
		$result .= $entry;
		$dictionary[$dictSize++] = $w . $entry[0];
		$w = $entry;
	}
	return $result;
}
?>
