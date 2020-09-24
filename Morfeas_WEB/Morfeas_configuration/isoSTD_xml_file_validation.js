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

	reader.onload = function(){
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
		let isoSTD_points = _isoSTD_xml.firstChild.firstChild;
		for(let i=0; i<isoSTD_points.childElementCount; i++)//Check for nodes with errors
		{
			let rem_elem_obj = new Object();
			let gtbd_node = isoSTD_points.childNodes[i];
			rem_elem_obj.Node_Name = gtbd_node.nodeName;
			//Too long Node name check and remove
			if(isoSTD_points.childNodes[i].nodeName.length >= ISOSTD_NODE_MAX_LENGTH)
			{
				rem_elem_obj.Reason = "Too long name (>="+ISOSTD_NODE_MAX_LENGTH+")";
				removed_elements.push(rem_elem_obj);
				isoSTD_points.removeChild(gtbd_node);
				i--;
				continue;
			}
			//Check for empty Node and remove
			if(!isoSTD_points.childNodes[i].childNodes.length)
			{
				rem_elem_obj.Reason = "Node without components";
				removed_elements.push(rem_elem_obj);
				isoSTD_points.removeChild(gtbd_node);
				i--;
				continue;
			}
			//Check for Node with only invalid components and remove it
			if(isoSTD_points.childNodes[i].childNodes.length)
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
					continue;
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
							alert(isoSTD_points.childNodes[i].nodeName+'.'+node.nodeName+" is empty");
							isoSTD_xml_str = ""; selected_files.value="";
							return;
						}
						break;
					case "unit":
						if(!node.textContent.length)
							node.textContent = "-";
						break;
					case "max":
					case "min":
						if(isNaN(node.textContent))
						{
							alert(isoSTD_points.childNodes[i].nodeName+'.'+node.nodeName+" is NAN");
							isoSTD_xml_str = ""; selected_files.value="";
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
		}
		//Check for duplicate Nodes
		for(let i=0; i<isoSTD_points.childElementCount-1; i++)
		{
			let check_node = isoSTD_points.childNodes[i];
			for(let j=i+1; j<isoSTD_points.childElementCount-1; j++)
			{
				let _select_node = isoSTD_points.childNodes[j];
				if(check_node.nodeName === _select_node.nodeName)
				{
					alert("Node \""+isoSTD_points.childNodes[i].nodeName+"\" found multiple times");
					isoSTD_xml_str = ""; selected_files.value="";
					return;
				}
			}
		}
		isoSTD_xml_str = compress((new XMLSerializer()).serializeToString(_isoSTD_xml));
		if(removed_elements.length)//Report removed nodes, if any
		{
			let report_win = PopupCenter("about:blank", "", 500, 300);
			child_wins.push(report_win);
			var table = document.createElement("table");
			generateTableHead(table, Object.keys(removed_elements[0]));
			generateTable(table, removed_elements);
			report_win.document.write("<div style=\"text-align:center;\"><b>The following Node(s) has been removed</b></div><br>"+table.outerHTML);
		}
	};
	reader.readAsText(selected_files.files[0]);
}
//@license-end