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
		//--- Functions for Up/DownLoad tab ---//
var isoSTD_xml_str;
//Init of FileReader object
const reader = new FileReader();
reader.onerror = function(){alert("File Read Error!!!");};
//Function for ISOStandard XML file Sanitization, called onChange of selected file
function isoSTD_xml_file_val(selected_files)
{
	reader.onload = function(){
		var xml_inp = this.result;
		xml_inp = xml_inp.replace(/[\t\r\n]+/g, '');
		var removed_elements = new Array();
		var _isoSTD_xml = (new DOMParser()).parseFromString(xml_inp, "application/xml");
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
		for(let i=0; i<_isoSTD_xml.firstChild.firstChild.childElementCount; i++)//Check for nodes with errors
		{
			if(_isoSTD_xml.firstChild.firstChild.childNodes[i].nodeName.length >= ISOSTD_NODE_MAX_LENGTH)
			{
				let gtbd_node = _isoSTD_xml.firstChild.firstChild.childNodes[i]
				let rem_elem_obj = new Object();
				rem_elem_obj.name = gtbd_node.nodeName;
				removed_elements.push(rem_elem_obj);
				_isoSTD_xml.firstChild.firstChild.removeChild(gtbd_node);
				continue;
			}
			for(let j=0; j<_isoSTD_xml.firstChild.firstChild.childNodes[i].childElementCount; j++)
			{
				let node = _isoSTD_xml.firstChild.firstChild.childNodes[i].childNodes[j];
				switch(node.nodeName)
				{
					case "description":
					case "unit": break;
					case "max":
					case "min":
						if(isNaN(node.textContent))
						{
							alert(_isoSTD_xml.firstChild.firstChild.childNodes[i].nodeName+'.'+node.nodeName+" is NAN");
							isoSTD_xml_str = ""; selected_files.value="";
							return;
						}
						break;
					default: _isoSTD_xml.firstChild.firstChild.childNodes[i].removeChild(node);
				}
			}
		}
		for(let i=0; i<_isoSTD_xml.firstChild.firstChild.childElementCount-1; i++)//Check for duplicate
		{
			let check_node = _isoSTD_xml.firstChild.firstChild.childNodes[i];
			for(let j=i+1; j<_isoSTD_xml.firstChild.firstChild.childElementCount-1; j++)
			{
				let _select_node = _isoSTD_xml.firstChild.firstChild.childNodes[j];
				if(check_node.nodeName === _select_node.nodeName)
				{
					alert("Node \""+_isoSTD_xml.firstChild.firstChild.childNodes[i].nodeName+"\" found multiple times");
					isoSTD_xml_str = ""; selected_files.value="";
					return;
				}
			}
		}
		isoSTD_xml_str = compress((new XMLSerializer()).serializeToString(_isoSTD_xml));
		if(removed_elements.length)
		{
			console.log(removed_elements);
			let report_win = PopupCenter("about:blank", "ISOstandard File Report", 500, 300);
			report_win.document.write("<p>Hello, world!</p>");
		}
	};
	reader.readAsText(selected_files.files[0]);
}
//Function for up/download ISOStandards
function isoSTD_upload()
{
	const fileSelector = document.getElementById('isoSTD_xml_file');
	const fileList = fileSelector.files;
	if(fileList.length && isoSTD_xml_str)
	{
		xhttp.open("POST", "../morfeas_php/config.php", true);
		xhttp.setRequestHeader("Content-type", "ISOstandard");
		xhttp.send(isoSTD_xml_str);
		fileSelector.value = "";
		curr_ISOstd_xml="";//element from ISOStandards tab
	}
	else
		alert("No ISOStandard XML file is selected");
}
function isoSTD_download()
{
	window.open("../morfeas_php/config.php"+"?COMMAND=getISOStandard_file", '_self');
}
//Functions for up/download a Morfeas Bundle
function bundle_upload()
{
	const fileList = document.getElementById('bundle_file').files;
	if(fileList.length)
	{
		reader.onload = function(){
			xhttp.open("POST", "../morfeas_php/config.php", true);
			xhttp.setRequestHeader("Content-type", "Morfeas_bundle");
			xhttp.send(this.result);
			document.getElementById('bundle_file').value = "";
			curr_morfeas_config_xml="";
			new_morfeas_config_xml="";
		};
		reader.readAsArrayBuffer(fileList[0]);
	}
	else
		alert("No bundle file is selected");
}
function bundle_download()
{
	window.open("../morfeas_php/config.php"+"?COMMAND=getbundle", '_self');
}
		//--- Functions for Morfeas system tab ---//
//Function that send the new_morfeas_confit to the server
function save_morfeas_config()
{
	if(new_morfeas_config_xml.innerHTML !== curr_morfeas_config_xml.innerHTML)
	{
		xhttp.open("POST", "../morfeas_php/config.php", true);
		xhttp.setRequestHeader("Content-type", "Morfeas_config");
		xhttp.send(compress((new XMLSerializer()).serializeToString(new_morfeas_config_xml)));
	}
	else
		alert("Nothing to commit");
}
//Function to get morfeas component name, return comp_name_id on success, NULL otherwise
function get_comp_name(comp)
{
	if(!comp)
		return null;
	switch(comp.nodeName)
	{
		case "OPC_UA_SERVER":
			return "OPC-UA SERVER";
		case "SDAQ_HANDLER":
			return comp.nodeName.replace("_HANDLER","")+" ("+comp.getElementsByTagName("CANBUS_IF")[0].textContent+")";
		case "MDAQ_HANDLER":
		case "IOBOX_HANDLER":
		case "MTI_HANDLER":
			return comp.nodeName.replace("_HANDLER","")+" ("+comp.getElementsByTagName("DEV_NAME")[0].textContent+")";
		default: return null;
	}
}
function morfeas_comp_list(new_morfeas_components_xml, curr_morfeas_components_xml)
{
	var listNode=document.getElementById("Comp_UL"),
	comp=new_morfeas_components_xml.firstChild;
	listNode.innerHTML="";
	for(let i = 0; i<new_morfeas_components_xml.childNodes.length; i++)
	{
	  if(comp.nodeType == Node.ELEMENT_NODE)
	  {
		let comp_name_id;
		if(!(comp_name_id=get_comp_name(comp)))
			continue;
		let textNode = document.createTextNode(comp_name_id),
		liNode = document.createElement("LI");
		liNode.classList.add("caret");
		liNode.setAttribute("name", comp.nodeName);
		liNode.onclick = function()
		{
			var others = document.getElementsByClassName("caret-down");
			for(let j = 0; j<others.length; j++)
				if(others[j] !== this)
				{
					others[j].style.fontWeight = "normal";
					others[j].classList.value = "caret";
				}
			this.classList.value = "caret-down";
			this.style.fontWeight = "bold";
			morfeas_comp_table(comp_args_table,
							   new_morfeas_components_xml.childNodes[i],
							   curr_morfeas_components_xml.childNodes[i]);
		};
		liNode.appendChild(textNode);
		listNode.appendChild(liNode);
	  }
	  comp = comp.nextSibling;
	}
}
//function for set component's argument to the configuration table
function morfeas_comp_table(args_table, _newConfigXML_node, _currConfigXML_Node)
{
	args_table.innerHTML="";//clear arg_table
	//Add component name as header
	var h=document.createElement("TH");
	var t=document.createTextNode(_newConfigXML_node.nodeName);
	h.appendChild(t);
	h.colSpan="2";
	args_table.appendChild(h);
	//Add component's elements to the args_table
	for(let i=0,row_count=0; i<_newConfigXML_node.childNodes.length; i++)
	{
		if(_newConfigXML_node.childNodes[i].nodeType == Node.ELEMENT_NODE)
		{
			var nRow = args_table.insertRow(row_count);
			nRow.insertCell(0).innerHTML=_newConfigXML_node.childNodes[i].nodeName+':';
			var arg_inp=document.createElement("INPUT");
			arg_inp.setAttribute("type", "text");
			arg_inp.value=_newConfigXML_node.childNodes[i].textContent;
			arg_inp.onchange = function()
			{
				if(_newConfigXML_node.childNodes[i].nodeName === "IPv4_ADDR")//Check if is input for IPv4
				{
					const patt= new RegExp(/^(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/);
					if(!patt.test(this.value))
					{
						alert("You have entered an invalid IP address!")
						return;
					}
				}
				_newConfigXML_node.childNodes[i].textContent = this.value;
				const list_select = document.getElementsByClassName("caret-down")[0];
				if(_newConfigXML_node.childNodes[i].textContent !== _currConfigXML_Node.childNodes[i].textContent)
				{
					if(list_select.innerHTML.charAt(0)!=="*")
						list_select.innerHTML="*"+list_select.innerHTML;
				}
				else
					list_select.innerHTML=list_select.innerHTML.replace('*',"");
			};
			arg_inp.oninput = function()
			{
				this.value = this.value.replace(" ","");
			};
			nRow.insertCell(1).appendChild(arg_inp);
			row_count++;
		}
	}
}
		//--- Functions for ISOStandards tab ---//
//Function that diploid ISOstandard table
function isoSTD(table, ISOstd_xml)
{
	table.innerHTML="";
	var nRow = table.insertRow(0);
	nRow.insertCell(0).innerHTML="NAME";
	for(let i=0; i<ISOstd_xml.childNodes[0].childNodes.length; i++)
		nRow.insertCell(i+1).innerHTML=ISOstd_xml.childNodes[0].childNodes[i].nodeName.toUpperCase();
	//Add isoSTD elements to the table
	for(let i=0, row_count=1; i<ISOstd_xml.childNodes.length; i++)
	{
		if(ISOstd_xml.childNodes[i].nodeType == Node.ELEMENT_NODE)
		{
			nRow = table.insertRow(row_count);
			nRow.insertCell(0).innerHTML=ISOstd_xml.childNodes[i].nodeName;
			for(let j=0; j<ISOstd_xml.childNodes[i].childNodes.length; j++)
			{
				nRow.insertCell(j+1).innerHTML=ISOstd_xml.childNodes[i].childNodes[j].textContent;
			}
			row_count++;
		}
	}
}
//@license-end