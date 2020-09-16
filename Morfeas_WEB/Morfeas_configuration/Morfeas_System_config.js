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
//Function for download Morfeas Bundle
function down()
{
	window.open("../morfeas_php/config.php"+"?COMMAND=getbundle", '_self');
}
//function to get morfeas component name, return comp_name_id on success, NULL otherwise
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
function components_list(morfeas_components_xml)
{
	var listNode=document.getElementById("Comp_UL"),
	comp=morfeas_components_xml.firstChild;
	listNode.innerHTML="";
	for(let i = 0; i<morfeas_components_xml.childNodes.length; i++)
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
		liNode.setAttribute("id", i);
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
			set_comp_table(comp_args_table, curr_config_xml, this.innerText);
		});
		liNode.appendChild(textNode);
		listNode.appendChild(liNode);
	  }
	  comp = comp.nextSibling;
	}
}
//function for input sanitization
function inp_sanit(inp, name)
{
	console.log(name);
	console.log(inp.value);
}
//function for set component's argument to the configuration table
function set_comp_table(args_table, _curr_config_xml, comp_id)
{
	function appendHeader(table, header_str)
	{
		var h=document.createElement("TH");
		var t=document.createTextNode(header_str);
		h.appendChild(t);
		h.colSpan="2";
		table.appendChild(h);
	};
	function appendArguments(table, xml_root)
	{
		var arg=xml_root.firstChild;
		for(let i=0,row_count=0; i<xml_root.childNodes.length; i++)
		{
			if(arg.nodeType == Node.ELEMENT_NODE)
			{
				var nRow = table.insertRow(row_count);
				nRow.insertCell(0).innerHTML=arg.nodeName+':';
				var arg_inp=document.createElement("INPUT");
				arg_inp.setAttribute("type", "text");
				arg_inp.value=arg.textContent;
				var name = arg.nodeName;
				arg_inp.addEventListener("input", function()
				{
					inp_sanit(this,name);
				});
				arg_inp.addEventListener("change", function()
				{

					var list_select = document.getElementsByClassName("caret-down");
					if(list_select[0].innerHTML.charAt(0)!=="*")
						list_select[0].innerHTML="*"+list_select[0].innerHTML;
				});
				nRow.insertCell(1).appendChild(arg_inp);
				row_count++;
			}
			arg = arg.nextSibling;
		}
	};
	if(!args_table||!_curr_config_xml||!comp_id)
		return;
	var comps;
	args_table.innerHTML="";
	appendHeader(args_table, comp_id);
	if(comp_id === "OPC-UA SERVER")
		appendArguments(args_table, _curr_config_xml.getElementsByTagName("OPC_UA_SERVER")[0]);
	else
	{
		var handler_info=comp_id.split(" "),
		comp_node=_curr_config_xml.getElementsByTagName(handler_info[0]+"_HANDLER");
		for(let i=0; i<comp_node.length; i++)
		{
			if(comp_node[i].firstChild.textContent === handler_info[1].replace(/[()*]/g,""))
			{
				appendArguments(args_table, comp_node[i]);
				return;
			}
		}
	}
}