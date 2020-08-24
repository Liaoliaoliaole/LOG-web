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
//LZW Compression function, forked from https://rosettacode.org/wiki/LZW_compression
function LZW_compress(data)
{
	var i,
		dictionary = {},
		c,
		wc,
		w = "",
		result = [],
		dictSize = 256;//sizeof UTF-8

	for (i = 0; i < dictSize; i += 1)
		dictionary[String.fromCharCode(i)] = i;
	for (i = 0; i < data.length; i += 1)
	{
		c = data.charAt(i);
		wc = w + c;
		if (dictionary.hasOwnProperty(wc))
			w = wc;
		else
		{
			result.push(dictionary[w]);
			dictionary[wc] = dictSize++;
			w = String(c);
		}
	}
	if (w !== "")
		result.push(dictionary[w]);

	console.log(dictionary);

	console.log(data.length);
	console.log(result.join("").length);

	return result.toString();
}
