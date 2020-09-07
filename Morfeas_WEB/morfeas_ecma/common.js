/*
@licstart  The following is the entire license notice for the
JavaScript code in this page.

Copyright (C) 12019-12020  Sam Harry Tzavaras

The JavaScript code in this page is free software: you can
redistribute it and/or modify it under the terms of the GNU
General Public License (GNU AGPL) as published by the Free Software
Foundation, either version 3 of the License, or any later version.
The code is distributed WITHOUT ANY WARRANTY;
without even the implied warranty of MERCHANTABILITY or FITNESS
FOR A PARTICULAR PURPOSE.  See the GNU AGPL for more details.

@licend  The above is the entire license notice
for the JavaScript code in this page.
*/
function PopupCenter(url, title, w, h)
{
	// Fixes dual-screen position
	var dualScreenLeft = window.screenLeft != undefined ? window.screenLeft : screen.left;
	var dualScreenTop = window.screenTop != undefined ? window.screenTop : screen.top;

	var width = window.innerWidth ? window.innerWidth : document.documentElement.clientWidth ? document.documentElement.clientWidth : screen.width;
	var height = window.innerHeight ? window.innerHeight : document.documentElement.clientHeight ? document.documentElement.clientHeight : screen.height;

	var left = ((width / 2) - (w / 2)) + dualScreenLeft;
	var top = ((height / 2) - (h / 2)) + dualScreenTop;
	var newWindow = window.open(url, title, 'scrollbars=yes, width=' + w + ', height=' + h + ', top=' + top + ', left=' + left);

	// Puts focus on the newWindow
	if (window.focus) {
		newWindow.focus();
	}
}

function makeid()
{
	var text = "";
	var possible = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";

	for( var i=0; i < 10; i++ )
		text += possible.charAt(Math.floor(Math.random() * possible.length));

	return text;
}
//Compression function
function compress(data)
{
	"use strict";
	if(typeof(data)!=="string")
		return null;

	var i, _index, index,
		checksum=0,
		dictOffset=0,
		dictionary=[],
		word="",
		result="";

	for(i=0; i<data.length; i++)
	{
		if(data.charCodeAt(i)>dictOffset)
			dictOffset = data.charCodeAt(i);
		checksum^=data.charCodeAt(i);
	}
	dictOffset++;

	for(i=0, _index=0; i<data.length; i++)
	{
		word += data.charAt(i);
		if((index = dictionary.indexOf(word)) < 0)//Not in dictionary
		{
			dictionary.push(word);
			result += word.length==1 ? word : String.fromCharCode(dictOffset+_index) + word.replace(dictionary[_index], "");
			word = "";
		}
		else
			_index = index;
	}
	if(word !== "")
		result += word;
	result = String.fromCharCode(dictOffset) + result + String.fromCharCode(checksum&0xFF);
	console.log("Compression Ratio:"+Math.round((((1-result.length/data.length)) + Number.EPSILON)*100)+"%");
	return result;
}