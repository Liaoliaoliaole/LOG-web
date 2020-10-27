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
//Function for ISOStandard XML file Sanitization, called onChange of selected file
function isoSTD_xml_file_val(selected_files)
{
	//Constants declaration
	const ISOSTD_NODE_MAX_LENGTH=20;

	reader.onload = function()
	{
		var xml_inp = this.result;
		xml_inp = xml_inp.replace(/>[ \s\r\n]*</g,"><");
		var removed_elements = new Array();
		var _isoSTD_xml = (new DOMParser()).parseFromString(xml_inp, "text/xml");
		if(_isoSTD_xml.firstElementChild.nodeName ==="parsererror")
		{
			alert("XML Parsing Error!!!");
			isoSTD_xml_str = ""; selected_files.value="";
			return;
		}
		if(_isoSTD_xml.firstChild.nodeName !== "root")
		{
			alert("Not \"root\" node");
			isoSTD_xml_str = ""; selected_files.value="";
			return;
		}
		if(_isoSTD_xml.firstChild.firstChild.nodeName !== "points")
		{
			alert("Not \"points\" node");
			isoSTD_xml_str = ""; selected_files.value="";
			return;
		}

		let i = 0;
		let prog_bar=document.getElementById("prog_bar");
		let prog=document.getElementById("prog");
		let isoSTD_points = _isoSTD_xml.firstChild.firstChild;
		prog_bar.max=isoSTD_points.childElementCount;
		
		//function that checking nodes for errors
		var promise = new Promise(function(resolve, reject){(
			function check()
			{
				prog_bar.value=i;
				prog.innerHTML=Math.round(i/(prog_bar.max)*100)+'%';
				let rem_elem_obj = new Object();
				let gtbd_node = isoSTD_points.childNodes[i];
				rem_elem_obj.Node_Name = gtbd_node.nodeName;

				//Check for duplicate Nodes
				if(isoSTD_points.getElementsByTagName(gtbd_node.nodeName).length>1)
				{
					reject("Node \""+isoSTD_points.childNodes[i].nodeName+"\" found multiple times"); 
					return;
				}

				//Too long Node name check and remove
				if(isoSTD_points.childNodes[i].nodeName.length >= ISOSTD_NODE_MAX_LENGTH)
				{
					rem_elem_obj.Reason = "Too long name (>="+ISOSTD_NODE_MAX_LENGTH+")";
					removed_elements.push(rem_elem_obj);
					isoSTD_points.removeChild(gtbd_node);
					i--;
				}
				//Check for empty Node and remove
				else if(!isoSTD_points.childNodes[i].childNodes.length)
				{
					rem_elem_obj.Reason = "Node without components";
					removed_elements.push(rem_elem_obj);
					isoSTD_points.removeChild(gtbd_node);
					i--;
				}
				//Check for Node with only invalid components and remove it
				else if(isoSTD_points.childNodes[i].childNodes.length)
				{
					let invalid_cnt=0;
					for(let j=0; j<isoSTD_points.childNodes[i].childElementCount; j++)
					{
						let node = isoSTD_points.childNodes[i].childNodes[j];
						switch(node.nodeName)
						{
							case "description": case "unit": case "max": case "min":
								break;
							default:
								invalid_cnt++;
						}
					}
					if(invalid_cnt === isoSTD_points.childNodes[i].childElementCount)
					{
						rem_elem_obj.Reason = "Node with only invalid components";
						removed_elements.push(rem_elem_obj);
						isoSTD_points.removeChild(gtbd_node);
						i--;
					}
				}
				//Check components of node and remove invalid
				for(let j=0; j<isoSTD_points.childNodes[i].childElementCount; j++)
				{
					let node = isoSTD_points.childNodes[i].childNodes[j];
					switch(node.nodeName)
					{
						case "description":
							if(!node.textContent.length)
							{
								reject(isoSTD_points.childNodes[i].nodeName+'.'+node.nodeName+" is empty");
								return;
							}
							break;
						case "unit":
							if(!node.textContent.length)
								node.textContent = "-";
							break;
						case "max":
						case "min":
							if(isNaN(node.textContent)||!node.textContent)
							{
								reject(isoSTD_points.childNodes[i].nodeName+'.'+node.nodeName+" is NAN"); 
								return;
							}
							break;
						default:
							let rem_elem_obj = new Object();
							rem_elem_obj.Node_Name = isoSTD_points.childNodes[i].nodeName+'.'+node.nodeName;
							rem_elem_obj.Reason = "Unknown nodeName";
							removed_elements.push(rem_elem_obj);
							isoSTD_points.childNodes[i].removeChild(node);
							j--;
					}
				}
				i++;
				prog_bar.max=isoSTD_points.childElementCount;
				if (i < prog_bar.max)
					setTimeout(check, 0);
				else
				{
					resolve();
					return;
				}
			})();
		});
		isoSTD_xml_str = "";
		promise.then(function () {
				let isoSTD_xml_str_uncomp = (new XMLSerializer()).serializeToString(_isoSTD_xml);
				isoSTD_xml_str = compress(isoSTD_xml_str_uncomp);
				prog.innerHTML="Okay";
				if(removed_elements.length)//Report removed nodes, if any
				{
					let report_win = PopupCenter("about:blank", "", 500, 300);
					child_wins.push(report_win);
					var table = document.createElement("table");
					generateTableHead(table, Object.keys(removed_elements[0]));
					generateTable(table, removed_elements);
					report_win.document.write("<div style=\"text-align:center;\"><b>The following Node(s) has been removed</b></div><br>"+table.outerHTML);
				}
				document.getElementById("isoSTD_xml_file").disabled = false;
			})
			.catch(function (errorMessage) 
			{
				alert(errorMessage);
				isoSTD_xml_str = ""; selected_files.value="";
				prog.innerHTML=" Failed";
				document.getElementById("isoSTD_xml_file").disabled = false;
			});
	};
	document.getElementById("isoSTD_xml_file").disabled = true;
	reader.readAsText(selected_files.files[0]);
}
//@license-end