//@license magnet:?xt=urn:btih:0b31508aeb0634b347b8270c7bee4d411b5d4109&dn=agpl-3.0.txt AGPL-v3.0
/*
@licstart  The following is the entire license notice for the
JavaScript code in this page.

Copyright (C) 12020-12021  Sam Harry Tzavaras

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
function SDAQ_fw_upload(SDAQnet, SDAQaddr)
{
	const fileList = document.getElementById('SDAQ_fw_upload').files;
	const reader = new FileReader();
	reader.onerror = function(){alert("File Read Error!!!");};

	if(!SDAQnet || !SDAQaddr)
		return;
	if(fileList.length)
	{
		reader.onload = function(){
			if(this.result.length >= 2*Math.pow(2, 30))//2Mib limit
			{
				alert("File too big (>=2Mib)!!!");
				return;
			}
			let req = new Object();
			req.SDAQnet = SDAQnet;
			req.SDAQaddr = SDAQaddr;
			req.SDAQ_firmware_HEX = this.result;
			xhttp.open("POST", "../../morfeas_php/morfeas_SDAQnet_proxy.php", true);
			xhttp.setRequestHeader("Content-type", "SDAQ_firmware/json");
			xhttp.send(compress(JSON.stringify(req), false));
		};
		reader.readAsText(fileList[0]);
	}
	else
		alert("No firmware file is selected");
}
//@license-end
