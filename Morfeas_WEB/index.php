<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
<meta http-equiv="Pragma" content="no-cache" />
<meta http-equiv="Expires" content="0" />
<link rel="shortcut icon" type="image/x-icon" href="/art/Morfeas_logo_yellow.ico"/>
<title>Morfeas WEB (<?php echo gethostname();?>)</title>
<style>
#footer{
   position:absolute;
   bottom:2%;
   width:99%;
}
img.bsize{
	width:35px;
	height:40px;
}
td.bold{
    font-weight: bold;
}
.content{
  top: 50%;
  left: 50%;
  margin: auto;
}
</style>
</head>
<body>
<span style="margin:auto; float:right;">
	<a style="cursor:pointer;color:blue;"
	onclick='PopupCenter("/Docs/build-doc/"+"?q="+makeid(),"","1480","800")'
	onMouseOver="this.style.color='red'"
	onMouseOut="this.style.color='blue'">Morfeas-WEB Docs</a>
</span>
<br>
<div class="content">
	<table style="margin:auto;text-align:center;margin-bottom:.075in;width:7in;">
	  <tr>
		<th colspan="6" style="font-weight: bold; font-size: xx-large">
			<img src="./art/Morfeas_logo_yellow.png" width="100" height="100">
		</th>
	  </tr>
	  <tr>
		<th colspan="6" style="font-weight: bold; font-size: xx-large">Morfeas WEB<br>(<?php echo gethostname();?>)</th>
	  </tr>
	  <tr>
		<td><button type="button" onclick='PopupCenter("/morfeas_SDAQ_web_if/SDAQsPortal.html"+"?q="+makeid(),"","1024","768")'>SDAQs<br>Portal</td>
		<td><button type="button" onclick='PopupCenter("/morfeas_MDAQ_web_if/MDAQsPortal.html"+"?q="+makeid(),"","1024","768")'>MDAQs<br>Portal</td>
		<td><button type="button" onclick='PopupCenter("/morfeas_IOBOX_web_if/IOBOXsPortal.html"+"?q="+makeid(),"","1024","768")'>IOBOXs<br>Portal</td>
		<td><button type="button" onclick='PopupCenter("/morfeas_MTI_web_if/MTIsPortal.html"+"?q="+makeid(),"","1024","768")'>MTIs<br>Portal</td>
		<td><button type="button" onclick='PopupCenter("/morfeas_NOX_web_if/NOXsPortal.html"+"?q="+makeid(),"","1024","768")'>NOXs<br>Portal</td>
		<td><button type="button" onclick='PopupCenter("/morfeas_CPAD_web_if/CPADsPortal.html"+"?q="+makeid(),"","1024","768")' disabled>CPADs<br>Portal</td>
	  </tr>
	  <tr>
		<td><button type="button" onclick='PopupCenter("/morfeas_Loggers/Morfeas_Loggers.html"+"?q="+makeid(),"","1024","768")'>
			<span title="System Loggers">
				<img src="./art/logger.svg" class="bsize">
			</span>
		</td>
		<td name="ISOCH"><button type="button" onclick='PopupCenter("/Morfeas_ISOChannel_Linker/ISO_CH_Linker.html"+"?q="+makeid(),"","1280","1024")'>
			<span title="Morfeas ISOChannel Linker">
				<img src="./art/anchor.png" class="bsize">
			</span>
		</td>
		<td name="ISOCH" style="display:none;"><button type="button" onclick='PopupCenter("/External_components/ISOChannel_Linker/html/","","1280","1024")'>
			<span title="Old ISOChannel Linker">
				<img src="./art/Anchor_wapise.png" class="bsize">
			</span>
		</td>
		<td><button type="button" onclick='PopupCenter("/Morfeas_configuration/Morfeas_System_config.html"+"?q="+makeid(),"","1024","768")'>
			<span title="System Configuration">
				<img src="./art/morfeas_gear.png" class="bsize">
			</span>
		</td>
		<td><button type="button" onclick='PopupCenter("/Morfeas_configuration/Network_config.html"+"?q="+makeid(),"","550","350")'>
			<span title="Network Configuration">
				<img src="./art/eth.png" class="bsize">
			</span>
		</td>
		<td><button type="button" onclick='reboot()'>
			<span title="Reboot">
				<img src="./art/reboot.png" class="bsize">
			</span>
		</td>
		<td><button type="button" onclick='shutdown()'>
			<span title="Shutdown">
				<img src="./art/shutdown.png" class="bsize">
			</span>
		</td>
	  </tr>
	</table>
</div>
<footer id="footer">
	<div style="float:left;">
		Author: Sam Harry Tzavaras &#169; 12019-12021<br>
		<a href="LICENSE">License: AGPLv3</a><br>
		<a onclick='PopupCenter("/External_components/Credits.html","","750","250")'href="./">Credits</a>
	</div>
	<div style="float:right;">
		<a id="pi" style="visibility:hidden;" onclick='PopupCenter("https://"+window.location.hostname+":4200","","1024","768");this.style.visibility="hidden"'>π</a>
		<img src="./art/debian.png" alt="Powered by Debian GNU/Linux">
	</div>
</footer>
</body>
<script src='../morfeas_ecma/common.js'></script>
<script>
//@license magnet:?xt=urn:btih:0b31508aeb0634b347b8270c7bee4d411b5d4109&dn=agpl-3.0.txt AGPL-v3.0
/*
@licstart  The following is the entire license notice for the
JavaScript code in this page.

Copyright (C) 12019-12021  Sam Harry Tzavaras

The JavaScript code in this page is free software: you can
redistribute it and/or modify it under the terms of the GNU
General Public License (GNU AGPL) as published by the Free Software
Foundation, either version 3 of the License, or any later version.
The code is distributed WITHOUT ANY WARRANTY;
without even the implied warranty of MERCHANTABILITY or FITNESS
FOR A PARTICULAR PURPOSE.  See the GNU GPL for more details.

As additional permission under GNU GPL version 3 section 7, you
may distribute non-source (e.g., minimized or compacted) forms of
that code without the copy of the GNU GPL normally required by
section 4, provided you include this license notice and a URL
through which recipients can access the Corresponding Source.

@licend  The above is the entire license notice
for the JavaScript code in this page.
*/
comp_check();
document.onkeyup = res_to_norm;
document.onfocus = res_to_norm;
function res_to_norm(){
		document.getElementById("pi").style.visibility="hidden";
		let ISOCH = document.getElementsByName("ISOCH");
		ISOCH[0].style.display = "table-cell";
		ISOCH[1].style.display = "none";
};
document.onkeydown = function(key){
	if(key.ctrlKey && key.shiftKey)
		document.getElementById("pi").style.visibility="visible";
	else if(key.key === 'o')
	{
		let ISOCH = document.getElementsByName("ISOCH");
		ISOCH[0].style.display = "none";
		ISOCH[1].style.display = "table-cell";
	}
};
function shutdown()
{
	var xhttp = new XMLHttpRequest();
	xhttp.open("POST", "../morfeas_php/config.php", true);
	xhttp.setRequestHeader("Content-type", "shutdown");
	xhttp.send();
	alert("Shudown executed. Close all related windows!!!");
}

function reboot()
{
	var xhttp = new XMLHttpRequest();
	xhttp.open("POST", "../morfeas_php/config.php", true);
	xhttp.setRequestHeader("Content-type", "reboot");
	xhttp.send();
}
//@license-end
</script>
</html>
