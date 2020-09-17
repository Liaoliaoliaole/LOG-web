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
//Init of FileReader object
const reader = new FileReader();
reader.addEventListener('load', function(){
		xhttp.open("POST", "../morfeas_php/config.php", true);
		xhttp.setRequestHeader("Content-type", "Morfeas_bundle");
		xhttp.send(this.result);
	}
);
reader.addEventListener('error', function(){alert("File Read Error!!!");});
//Function for upload a Morfeas Bundle
function bundle_upload(data)
{
	const fileSelector = document.getElementById('bundle');
	const fileList = fileSelector.files;
	reader.readAsArrayBuffer(fileList[0]);
}
//Function for download Morfeas Bundle
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
		liNode.addEventListener("click", function()
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
		});
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
			arg_inp.addEventListener("change", function()
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
			});
			arg_inp.addEventListener("input", function()
			{
				this.value = this.value.replace(" ","");
			});
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