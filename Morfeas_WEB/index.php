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
button{width:100px;}

.portal_button{cursor: pointer;}

.dropdown{
	position: relative;
	display: inline-block;
}

.dropdown-content{
	cursor: pointer;
	display: none;
	position: absolute;
	background-color: #f1f1f1;
	min-width: 160px;
	overflow: auto;
	z-index: 1;
}

.dropdown-content a {
	color: black;
	padding: 12px 16px;
	text-decoration: none;
	display: block;
}

.dropdown a:hover {background-color: #ddd;}

.show {display: block;}

</style>
</head>
<body>
<span style="margin:auto;">
	<a style="float:left;cursor:pointer;color:blue;"
	onclick='PopupCenter("/Version.html"+"?q="+makeid(),"","400","300")'
	onMouseOver="this.style.color='red'"
	onMouseOut="this.style.color='blue'">Ver</a>
	<a style="float:right;cursor:pointer;color:blue;"
	onclick='PopupCenter("/Morfeas_web_Docs/build-doc/"+"?q="+makeid(),"","1480","800")'
	onMouseOver="this.style.color='red'"
	onMouseOut="this.style.color='blue'">Morfeas-WEB Docs</a>
</span>
<br>
<div class="content">
	<table style="margin:auto;text-align:center;margin-bottom:.075in;width:6.2in;">
	  <tr>
		<th colspan="5" style="font-weight: bold; font-size: xx-large">
			<img src="./art/Morfeas_logo_yellow.png" width="100" height="100">
		</th>
	  </tr>
	  <tr>
		<th colspan="5" style="font-weight: bold; font-size: xx-large">Morfeas WEB<br>(<?php echo gethostname();?>)</th>
	  </tr>
	  <tr style="height:1.3in;">
		<td><button type="button" onclick='PopupCenter("/morfeas_Loggers/Morfeas_Loggers.html"+"?q="+makeid(),"","1024","768")'>
			<span title="Morfeas System Loggers">
				<img src="./art/logger.svg" class="bsize">
				<p><b>System<br>Loggers</b></p>
			</span>
		</td>
		<td name="ISOCH"><button type="button" onclick='PopupCenter("/Morfeas_ISOChannel_Linker/ISO_CH_Linker.html"+"?q="+makeid(),"","1280","1024")'>
			<span title="Morfeas ISOChannel Linker">
				<img src="./art/anchor.png" class="bsize">
				<p><b>ISOChannel<br>Linker</b></p>
			</span>
		</td>
		<td name="ISOCH" style="display:none;"><button type="button" onclick='PopupCenter("/External_components/ISOChannel_Linker/html/","","1280","1024")'>
			<span title="Old ISOChannel Linker">
				<img src="./art/Anchor_wapise.png" class="bsize">
				<p><b>ISOChannel<br>Linker</b></p>
			</span>
		</td>
		<td><button type="button" onclick='PopupCenter("/Morfeas_configuration/Morfeas_System_config.html"+"?q="+makeid(),"","1024","768")'>
			<span title="Morfeas System Configuration">
				<img src="./art/morfeas_gear.png" class="bsize">
				<p><b>System<br>Configuration</b></p>
			</span>
		</td>
		<td><button type="button" onclick='PopupCenter("/Morfeas_configuration/Network_config.html"+"?q="+makeid(),"","500","500")'>
			<span title="Network Configuration">
				<img src="./art/eth.png" class="bsize">
				<p><b>Network<br>Configuration</b></p>
			</span>
		</td>
		<td>
			<div class="dropdown">
				<button class="portal_button" name="portal_btn" onclick="toggle_portals_list()">
					<span title="Morfeas Components Portal">
						<img src="./art/portal.png" class="bsize" name="portal_btn">
						<p name="portal_btn"><b name="portal_btn">Morfeas<br>Portals</b></p>
					</span>
				</button>
				<div id="portals_dropdown" class="dropdown-content">
					<a onclick='PopupCenter("/morfeas_SDAQ_web_if/SDAQsPortal.html"+"?q="+makeid(),"","1024","768")'>SDAQs</a>
					<a onclick='PopupCenter("/morfeas_MDAQ_web_if/MDAQsPortal.html"+"?q="+makeid(),"","1024","768")'>MDAQs</a>
					<a onclick='PopupCenter("/morfeas_IOBOX_web_if/IOBOXsPortal.html"+"?q="+makeid(),"","1024","768")'>IOBOXs</a>
					<a onclick='PopupCenter("/morfeas_MTI_web_if/MTIsPortal.html"+"?q="+makeid(),"","1024","768")'>MTIs</a>
					<a onclick='PopupCenter("/morfeas_NOX_web_if/NOXsPortal.html"+"?q="+makeid(),"","1024","768")'>NOXs</a>
				</div>
			</div>
		</td>
	  </tr>
	  <tr>
		<td colspan="2"></td>
		<td><button type="button" onclick='update_system()'>
    		<span title="System Update">
        		<img src="./art/update.png" class="bsize">
        		<p><b>System<br>Update</b></p>
    		</span>
		</button></td>
		<td><button type="button" onclick='reboot()'>
			<span title="Reboot">
				<img src="./art/reboot.png" class="bsize">
				<p><b>System<br>Reboot</b></p>
			</span>
		</button></td>
		<td><button type="button" onclick='shutdown()'>
			<span title="Shutdown">
				<img src="./art/shutdown.png" class="bsize">
				<p><b>System<br>Shutdown</b></p>
			</span>
		</button></td>
		<td colspan="1"></td>
	  </tr>
	</table>
</div>
<footer id="footer">
	<div style="float:left;">
		Author: Sam Harry Tzavaras &#169; 12019-12022<br>
		<a href="LICENSE">License: AGPLv3</a><br>
		<a onclick='PopupCenter("/External_components/Credits.html","","750","300")'href="./">Credits</a>
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

Copyright (C) 12019-12022  Sam Harry Tzavaras

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
"use strict";
comp_check();

function toggle_portals_list()
{
	document.getElementById("portals_dropdown").classList.toggle("show");
}
function hide_portals_list()
{
	var dropdowns = document.getElementsByClassName("dropdown-content");
	for (let i = 0; i < dropdowns.length; i++)
		if (dropdowns[i].classList.contains('show'))
			dropdowns[i].classList.remove('show');
}

window.onclick = function(event)
{
	if((!event.target.attributes.name && event.target.attributes.name!=="portal_btn"))
		hide_portals_list();
}

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
	else if(key.key === "Escape")
		hide_portals_list();
};

// function update_system() {
// 	console.log("Checking for updates...");
//     alert("Checking for updates...");
//     var xhttp = new XMLHttpRequest();
//     xhttp.open("POST", "../morfeas_php/config.php", true);
//     xhttp.setRequestHeader("Content-type", "check_update");
//     xhttp.onreadystatechange = function () {
//         if (xhttp.readyState === 4 && xhttp.status === 200) {
//             var response = JSON.parse(xhttp.responseText);
// 			console.log("Response received:", response);

// 			if (response.debug) {
//                 console.log("=== DEBUG OUTPUT ===");
//                 response.debug.forEach(function (line) {
//                     console.log(line);
//                 });
//                 console.log("====================");
//             }

//             if(response.update) {
//                 if (confirm("Update available. Do you want to update?")) {
//                     alert("Please wait, updating...");
//                     var xhttp2 = new XMLHttpRequest();
//                     xhttp2.open("POST", "../morfeas_php/config.php", true);
//                     xhttp2.setRequestHeader("Content-type", "update");
//                     xhttp2.onreadystatechange = function () {
//                         if (xhttp2.readyState === 4) {
//                             if (xhttp2.status === 200) {
//                                 alert("Update completed successfully.\n\n" + xhttp2.responseText);
//                             } else {
//                                 alert("Failed to update. Please check logs in /tmp/log_update.log.");
//                             }
//                         }
//                     };
//                     xhttp2.send();
//                 }
//             } else {
//                 alert(response.message); 
//             }
//         }
//     };
//     xhttp.send();
// }
function update_system() {
    console.log("Starting update check...");

    // Show initial checking message
    alert("Checking for updates...");

    // Check for updates
    fetch("../morfeas_php/config.php", {
        method: "POST",
        headers: { "Content-Type": "check_update" }
    })
    .then(response => response.json())
    .then(checkResult => {
        console.log("Check Result:", checkResult);
        if (checkResult.debug) console.log("DEBUG:", checkResult.debug.join("\n"));

        if (!checkResult.update) {
            alert(checkResult.message); // System up to date
            return;
        }

        if (!confirm("Update available. Do you want to update now?")) return;

        alert("Updating... Please wait.");

        // Trigger update
        fetch("../morfeas_php/config.php", {
            method: "POST",
            headers: { "Content-Type": "update" }
        })
        .then(response => response.json())
        .then(updateResult => {
            console.log("Update Result:", updateResult);
            alert(updateResult.report + "\n\n" + updateResult.output);
        })
        .catch(error => {
            console.error("Update Error:", error);
            alert("Update in progress. Page may reload soon as services restart.");
        });
    })
    .catch(error => {
        console.error("Check Update Error:", error);
        alert("Error checking for updates.");
    });
}

function shutdown()
{
	if(confirm("System going to shutdown"))
	{
		var xhttp = new XMLHttpRequest();
		xhttp.open("POST", "../morfeas_php/config.php", true);
		xhttp.setRequestHeader("Content-type", "shutdown");
		xhttp.send();
		alert("Shudown executed. Close all related windows!!!");
	}
}

function reboot()
{
	if(confirm("System going to Reboot"))
	{
		var xhttp = new XMLHttpRequest();
		xhttp.open("POST", "../morfeas_php/config.php", true);
		xhttp.setRequestHeader("Content-type", "reboot");
		xhttp.send();
	}
}
//@license-end
</script>
</html>
