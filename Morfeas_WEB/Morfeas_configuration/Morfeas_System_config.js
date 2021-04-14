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
		//--- Functions for Morfeas system tab ---//
//Function that delete the selected Morfeas component
function delete_selected_Morfeas_comp()
{
	var listNodes=document.getElementById("Comp_UL");
	var comp_args=document.getElementById("comp_args");
	var selected_listnode = document.getElementsByClassName("caret-down");

	if(!selected_listnode.length)
		return;
	if(selected_listnode[0].textContent==="OPC-UA SERVER")
	{
		alert("\"OPC-UA SERVER\" component can't be deleted");
		return;
	}
	var elem_attr= selected_listnode[0].attributes["name"].value.split('@');
	var elem_name = elem_attr[0];
	var elem_pos = Number(elem_attr[1]);
	if(!elem_name||!elem_pos)
		return;
	if(new_morfeas_config_xml.childNodes[elem_pos].nodeName === elem_name)
	{
		new_morfeas_config_xml.removeChild(new_morfeas_config_xml.childNodes[elem_pos]);
		if(curr_morfeas_config_xml.childNodes[elem_pos])
			curr_morfeas_config_xml.removeChild(curr_morfeas_config_xml.childNodes[elem_pos]);
	}
	comp_args.innerHTML="";
	morfeas_comp_list(listNodes, new_morfeas_config_xml, curr_morfeas_config_xml, document.getElementById("rem"));
}
function morfeas_config_ordered(_new_morfeas_config)
{
	var ordered_new_morfeas_config = document.implementation.createDocument(null, "COMPONENTS");
	const comp_order=["OPC_UA_SERVER","SDAQ_HANDLER","MDAQ_HANDLER","IOBOX_HANDLER","MTI_HANDLER","NOX_HANDLER"];
	for(let i=0;i<comp_order.length;i++)
	{
		var compsWithType=_new_morfeas_config.getElementsByTagName(comp_order[i]);
		console.log(compsWithType);
		if(compsWithType.length)
			for(let j=0;j<compsWithType.length;j++)
			{
				let compsWithType_clone = compsWithType[j].cloneNode(true);
				ordered_new_morfeas_config.documentElement.appendChild(compsWithType_clone);
			}
	}
	return ordered_new_morfeas_config;
}
//Function that send the new_morfeas_confit to the server
function save_morfeas_config()
{
	var config_to_send = morfeas_config_ordered(new_morfeas_config_xml);
	xhttp.open("POST", "../morfeas_php/config.php", true);
	xhttp.setRequestHeader("Content-type", "Morfeas_config");
	let data = (new XMLSerializer()).serializeToString(config_to_send);
	console.log(data);
	xhttp.send(compress(data, true));
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
		case "NOX_HANDLER":
			return comp.nodeName.replace("_HANDLER","")+" ("+comp.getElementsByTagName("CANBUS_IF")[0].textContent+")";
		case "MDAQ_HANDLER":
		case "IOBOX_HANDLER":
		case "MTI_HANDLER":
			return comp.nodeName.replace("_HANDLER","")+" ("+comp.getElementsByTagName("DEV_NAME")[0].textContent+")";
		default: return null;
	}
}
function morfeas_comp_list(listNode, new_morfeas_components_xml, curr_morfeas_components_xml, indicator)
{
	var comp=new_morfeas_components_xml.firstChild;
	listNode.innerHTML="";
	indicator.innerHTML="USED:"+new_morfeas_components_xml.childNodes.length+"/"+Morfeas_comp_amount_max;
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
			liNode.setAttribute("name", comp.nodeName+"@"+i);
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
//Function for set component's argument to the configuration table
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
				if(!this.value.length)
				{
					this.value = _newConfigXML_node.childNodes[i].textContent;
					return;
				}
				if(_newConfigXML_node.childNodes[i].nodeName === "IPv4_ADDR")
				{
					if(!ip_addr_val(this.value))
					{
						this.value = _newConfigXML_node.childNodes[i].textContent;
						alert("You have entered an invalid IP address!");
						return;
					}
					if(is_IPv4_addr_inuse(this.value, new_morfeas_config_xml))
					{
						alert("IPv4_ADDR ("+this.value+") used on an another handler!!!");
						this.value = _newConfigXML_node.childNodes[i].textContent;
						return;
					}
				}
				else if(_newConfigXML_node.childNodes[i].nodeName === "DEV_NAME")
				{
					if(!DEV_NAME_val(this.value))
					{
						this.value = _newConfigXML_node.childNodes[i].textContent;
						alert("DEV_NAME contains illegal characters");
						return;
					}
					if(is_DevName_inuse(this.value, new_morfeas_config_xml))
					{
						alert("DEV_NAME ("+this.value+") used on an another handler!!!");
						this.value = _newConfigXML_node.childNodes[i].textContent;
						return;
					}
				}
				else if(_newConfigXML_node.childNodes[i].nodeName === "CANBUS_IF")
				{
					let j;
					for(j=0;j<can_ifs_names.length;j++)
					{
						if(this.value===can_ifs_names[j])
							break;
					}
					if(j>=can_ifs_names.length)
					{
						alert("CANBUS_IF:"+this.value+" doesn't exist!!!");
						this.value = _newConfigXML_node.childNodes[i].textContent;
						return;
					}
					if(is_canIF_inuse(this.value, new_morfeas_config_xml))
					{
						alert("CAN-IF ("+this.value+") used by an another handler");
						this.value = _newConfigXML_node.childNodes[i].textContent;
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
		//--- Functions for ISOstandards tab ---//
//Function that develop ISOstandard table
function isoSTD_develop(table, ISOstd_xml)
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
		//--- Functions for Up/DownLoad tab ---//
var isoSTD_xml_str;
//Init of FileReader object
const reader = new FileReader();
reader.onerror = function(){alert("File Read Error!!!");};
//Function for up/download ISOstandards
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
		curr_ISOstd_xml="";//element from ISOstandards tab
	}
	else if(!fileList.length)
		alert("No ISOstandard XML file is selected");
	else
		alert("Validation in progress");
}
function isoSTD_download()
{
	window.open("../morfeas_php/config.php"+"?COMMAND=getISOstandard_file", '_self');
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
//@license-end