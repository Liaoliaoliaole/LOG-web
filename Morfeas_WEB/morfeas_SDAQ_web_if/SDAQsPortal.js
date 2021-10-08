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
"use strict";
var Det_devs = SDAQnet_stats_disp_init("Det_devs"),
	Bus_util = SDAQnet_stats_disp_init("Bus_util"),
	Bus_Voltage = SDAQnet_stats_disp_init("Bus_Voltage"),
	Bus_Amperage = SDAQnet_stats_disp_init("Bus_Amperage");

function data_update(SDAQnet_data)
{
	let SDAQnet_stats = document.getElementsByName("SDAQnet_stats");
	
	if(!SDAQnet_data)
		return;
	console.log(SDAQnet_data);
	for(let i=0; i<SDAQnet_stats.length; i++)
	{
		if(i<2)
			SDAQnet_stats[i].hidden = false;
		else 
			SDAQnet_stats[i].hidden = SDAQnet_data.hasOwnProperty('Electrics')?false:true;
	}
	Det_devs.setValue(SDAQnet_data.Detected_SDAQs.toString());
	Bus_util.setValue(SDAQnet_data.BUS_Utilization.toString()+'%');
	/*
	var SDAQs_list = document.getElementById("SDAQs_list");
	if(!data_plot.prev)
		data_plot.prev={};
	SDAQnet_stats.innerHTML="Det_devs:"+SDAQnet_data.Detected_SDAQs+
							" Bus_util: "+SDAQnet_data.BUS_Utilization+'%';
	if(SDAQnet_data.hasOwnProperty('Electrics'))
		SDAQnet_stats.innerHTML+=" Bus_voltage: "+SDAQnet_data.Electrics.BUS_voltage+"V"+" Bus_Amperage: "+SDAQnet_data.Electrics.BUS_amperage+"A"
	if(data_plot.prev.amount!=SDAQnet_data.Detected_SDAQs || data_plot.prev.bus!=SDAQnet_data.CANBus_interface || !SDAQs_list.innerHTML)
	{
		//var SDAQ_Data_Chart = new Chart.Line("data_plot_canvas");
		SDAQs_list.innerHTML="";
		if(SDAQnet_data.SDAQs_data)
			SDAQ_dev_list_tree(SDAQs_list, SDAQnet_data.SDAQs_data);
		data_plot.prev.amount=SDAQnet_data.Detected_SDAQs;
		data_plot.prev.bus=SDAQnet_data.CANBus_interface;
	}
	var sel_sdaq=document.getElementsByClassName("caret-down");
	if(sel_sdaq.length)
	{
		//console.log(sel_sdaq);
	}
	*/
}

function SDAQnet_stats_disp_init(name)
{
	let SDAQnet_stats_disp;
	if(!name)
		return;
	SDAQnet_stats_disp = new SegmentDisplay(name);
	SDAQnet_stats_disp.pattern         = "##";
	SDAQnet_stats_disp.displayAngle    = 6;
	SDAQnet_stats_disp.digitHeight     = 20;
	SDAQnet_stats_disp.digitWidth      = 14;
	SDAQnet_stats_disp.digitDistance   = 2.5;
	SDAQnet_stats_disp.segmentWidth    = 2;
	SDAQnet_stats_disp.segmentDistance = 0.3;
	SDAQnet_stats_disp.segmentCount    = 7;
	SDAQnet_stats_disp.cornerType      = 3;
	SDAQnet_stats_disp.colorOn         = "black";
	SDAQnet_stats_disp.colorOff        = "white";
	SDAQnet_stats_disp.draw();
	return SDAQnet_stats_disp;
}

function SDAQ_dev_list_tree(listNode, SDAQs_data)
{
	if(!SDAQs_data)
		return;
	for(let i = 0; i<SDAQs_data.length; i++)
	{
		let textNode = document.createTextNode(SDAQs_data[i].SDAQ_type+'('+SDAQs_data[i].Address+')'),
		liNode = document.createElement("LI");
		liNode.classList.add("caret");
		liNode.onclick = list_sel_callback;
		liNode.appendChild(textNode);
		for(let j=0; j<SDAQs_data[i].Meas.length;j++)
		{
			console.log(SDAQs_data[i].Meas[j].Channel);
		}
		listNode.appendChild(liNode);
	}
}
function list_sel_callback()
{
	var others = document.getElementsByClassName("caret-down");
	for(let j = 0; j<others.length; j++)
	{
		if(others[j] !== this)
		{
			others[j].style.fontWeight = "normal";
			others[j].classList.value = "caret";
		}
	}
	this.classList.value = "caret-down";
	this.style.fontWeight = "bold";
}
//@license-end