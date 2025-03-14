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

function update_system() {
    if (window.updateInProgress) return;
    window.updateInProgress = true;

    let overlay = document.createElement('div');
    overlay.id = 'update-overlay';
    overlay.style = `
        position: fixed;
        top: 0; left: 0;
        width: 100%; height: 100%;
        background: rgba(201, 197, 197, 0.6);
        color: black;
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        font-family: Arial, sans-serif;
    `;
    overlay.innerHTML = `
        <div id="update-status-message" style="
            background: rgba(201, 197, 197, 0.6); 
            padding: 20px; 
            border-radius: 5px;
            text-align: center;
            max-width: 90%;
            font-size: 18px;
			font-weight: bold;
            line-height: 1.5;
        ">Preparing update check...</div>`;
    document.body.appendChild(overlay);

    function setStatus(msg, showButtons = false) {
        const messageBox = document.getElementById('update-status-message');
        messageBox.innerHTML = msg;
        if (showButtons) {
            const buttons = `
                <br><br>
                <button id="update-now" style="margin-right: 20px; padding: 10px 20px; font-size: 16px;">Update Now</button>
                <button id="update-later" style="margin-right: 20px; padding: 10px 20px; font-size: 16px;">Update Later</button>
            `;
            messageBox.innerHTML += buttons;

            document.getElementById('update-now').onclick = startUpdate;
            document.getElementById('update-later').onclick = () => {
                overlay.remove();
                window.updateInProgress = false;
            };
        }
    }

    setStatus("Checking for updates...");
    fetch("../morfeas_php/config.php", {
        method: "POST",
        headers: { "Content-type": "check_update" }
    })
    .then(response => response.json())
    .then(data => {
        console.log("Check Result:", data);

        if (data.update) {
            setStatus("<b>Update available!</b><br>Do you want to update now or later?", true);
        } else {
            setStatus("System is already up-to-date.");
            setTimeout(() => {
                overlay.remove();
                window.updateInProgress = false;
            }, 3000);
        }
    })
    .catch(error => {
        console.error("Error during update check:", error);
        setStatus("Error checking for updates: " + error.message);
        setTimeout(() => {
            overlay.remove();
            window.updateInProgress = false;
        }, 5000);
    });

    function startUpdate() {
        setStatus("⚙️ Updating system... Please do not close this window.");
        
        fetch("../morfeas_php/config.php", {
            method: "POST",
            headers: { "Content-type": "update" }
        })
        .then(response => response.json())
        .then(result => {
            console.log("Update result:", result);
            setStatus("Update completed. System will restart shortly...");
            setTimeout(waitForServerRecovery, 5000);
        })
        .catch(error => {
            console.warn("Update error:", error);
            if (error instanceof TypeError && error.message.includes('Failed to fetch')) {
                setStatus("System is restarting. Please wait...");
                waitForServerRecovery();
            } else {
                setStatus("Update failed: " + error.message);
                setTimeout(() => {
                    overlay.remove();
                    window.updateInProgress = false;
                }, 5000);
            }
        });
    }
}

function waitForServerRecovery() {
    const pingInterval = 5000; // every 5 sec
    const maxAttempts = 20; // retry limit
    let attempts = 0;

    function setStatus(msg) {
        document.getElementById('update-status-message').innerHTML = msg;
    }

    const intervalId = setInterval(() => {
        fetch(window.location.href, { method: 'HEAD', cache: 'no-store' })
            .then(() => {
                clearInterval(intervalId);
                setStatus("System is back online! Reloading...");
                setTimeout(() => location.reload(), 2000);
            })
            .catch(() => {
                attempts++;
                setStatus(`Waiting for system to restart... (Attempt ${attempts}/${maxAttempts})`);
                if (attempts >= maxAttempts) {
                    clearInterval(intervalId);
                    setStatus("System did not respond. Please refresh manually.");
                }
            });
    }, pingInterval);
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
