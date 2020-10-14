//@license magnet:?xt=urn:btih:0b31508aeb0634b347b8270c7bee4d411b5d4109&dn=agpl-3.0.txt AGPL-v3.0
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
"use strict";
const Morfeas_comp_amount_max = 16;
function comp_check()
{
	if(document.documentMode)
	{
		alert("IE is obsolete, and does not supported \n Use firefox or chromium");
		window.close();
	}
}

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
	return newWindow;
}

function makeid()
{
	var text = "";
	var possible = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";

	for( var i=0; i < 10; i++ )
		text += possible.charAt(Math.floor(Math.random() * possible.length));

	return text;
}

function ip_addr_val(ip_addr)
{
	const patt= new RegExp(/^(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/);
	return patt.test(ip_addr)?true:false;
}

function DEV_NAME_val(DEV_NAME)
{
	const patt= new RegExp(/[^[a-zA-Z0-9_-]]*/);
	return patt.test(DEV_NAME)?false:true;
}

function is_canIF_inuse(selected_can_if_val, _Morfeas_config_XML)
{
	for(let i=0;i<_Morfeas_config_XML.childNodes.length;i++)
	{
		let node_under_check=_Morfeas_config_XML.childNodes[i];
		if(node_under_check.nodeName==="SDAQ_HANDLER")
			if(node_under_check.childNodes[0].textContent===selected_can_if_val)
				return true;
	}
	return false;
}

function is_DevName_inuse(selected_DevName_val, _Morfeas_config_XML)
{
	for(let i=0;i<_Morfeas_config_XML.childNodes.length;i++)
	{
		let node_under_check=_Morfeas_config_XML.childNodes[i];
		switch(node_under_check.nodeName)
		{
			case "MDAQ_HANDLER":
			case "IOBOX_HANDLER":
			case "MTI_HANDLER":
			if(node_under_check.childNodes[0].textContent===selected_DevName_val)
				return true;
		}
	}
	return false;
}

function is_IPv4_addr_inuse(selected_IPv4_addr_val, _Morfeas_config_XML)
{
	for(let i=0;i<_Morfeas_config_XML.childNodes.length;i++)
	{
		let node_under_check=_Morfeas_config_XML.childNodes[i];
		switch(node_under_check.nodeName)
		{
			case "MDAQ_HANDLER":
			case "IOBOX_HANDLER":
			case "MTI_HANDLER":
			if(node_under_check.childNodes[1].textContent===selected_IPv4_addr_val)
				return true;
		}
	}
	return false;
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

function generateTableHead(table, data)
{
	let thead = table.createTHead();
	let row = thead.insertRow();
	for(let key of data)
	{
		let th = document.createElement("th");
		let text = document.createTextNode(key);
		th.appendChild(text);
		row.appendChild(th);
	}
}
function generateTable(table, data)
{
  for(let element of data)
  {
    let row = table.insertRow();
    for(let key in element)
	{
      let cell = row.insertCell();
      let text = document.createTextNode(element[key]);
      cell.appendChild(text);
    }
  }
}
//@license-end