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
var dev_tree,
	Det_devs = SDAQnet_stats_disp_init("Det_devs"),
	Bus_util = SDAQnet_stats_disp_init("Bus_util"),
	Bus_Voltage = SDAQnet_stats_disp_init("Bus_Voltage"),
	Bus_Amperage = SDAQnet_stats_disp_init("Bus_Amperage");

function data_update(SDAQnet_data, update_tree)
{
	let SDAQnet_stats = document.getElementsByName("SDAQnet_stats");

	if(!SDAQnet_data)
		return;
	/*
	//Update SDAQnet stats
	for(let i=0; i<SDAQnet_stats.length; i++)
	{
		if(i<2)
			SDAQnet_stats[i].hidden = false;
		else
			SDAQnet_stats[i].hidden = SDAQnet_data.hasOwnProperty('Electrics')?false:true;
	}
	Det_devs.setValue(SDAQnet_data.Detected_SDAQs.toString());
	Bus_util.setValue(SDAQnet_data.BUS_Utilization.toString()+'%');
	//console.log(SDAQnet_data);
	*/
	if(update_tree)
	{
		let SDAQnet_logstat_tree = morfeas_build_dev_tree_from_SDAQ_logstat(SDAQnet_data);
		//console.log(SDAQnet_logstat_tree);
		if(SDAQnet_logstat_tree)
		{
			dev_tree = new TreeView(SDAQnet_logstat_tree, 'Dev_tree');
			dev_tree.on('collapse', clean_sel_data);
			dev_tree.on('expand', clean_sel_data);
			dev_tree.on('select', select_callback);
			function clean_sel_data()
			{
				/*
				sel_data_tbl.innerHTML = '';
				sel_data = {};
				ok_button.disabled = true;
				*/
			}
			function select_callback(elem)
			{
				console.log(elem);
				switch(elem.data.name)
				{
					case "Calibration table":
						if(elem.data.if_name && elem.data.SDAQaddr && elem.data.Max_cal_point)
						{
							cal_table_wins.push(PopupCenter("/morfeas_SDAQ_web_if/SDAQ_cal_config/SDAQ_cal_config.html"+
															"?SDAQnet="+elem.data.if_name+
															"&SDAQaddr="+elem.data.SDAQaddr+
															"&q="+makeid(),"","850",elem.data.Max_cal_point>8?"780":"570"));
						}
						break;
					case "Status":
						break;
					case "Info":
						break;
					default:
						break;
				}
				/*
				sel_data_tbl.innerHTML = '';
				if(elem.data && elem.data.Anchor)
				{
					gen_sel_data_table(elem.data)
					ok_button.disabled = false;
				}
				*/
			}
		}
		else
			document.getElementById('Dev_tree').innerHTML='No SDAQs';
	}
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
function morfeas_build_dev_tree_from_SDAQ_logstat(SDAQ_logstat)
{
	function get_SDAQ_if_chidren(SDAQ_if_data, if_name)
	{
		let SDAQs = [];
		for(let i=0; i<SDAQ_if_data.length; i++)
		{
			let SDAQ = {};
			SDAQ.name = "(ADDR:"+norm(SDAQ_if_data[i].Address,2)+") "+SDAQ_if_data[i].SDAQ_type;
			SDAQ.expandable = true;
			SDAQ.children = [];
			//Add Channels
			let CHs = {};
			CHs.name = "Channels";
			CHs.expandable = true;
			CHs.children = [];
			for(let j=0; j<SDAQ_if_data[i].SDAQ_info.Number_of_channels; j++)
			{
				let CH = {};
				CH.name = "CH:"+norm((j+1),2);
				CH.data_table_pos = i;
				CH.SDAQ_SN = SDAQ_if_data[i].Serial_number;
				CH.channel = j;
				CHs.children.push(CH);
			}
			//Add Calibration table edit link.
			let cal_table = {};
			cal_table.name = "Calibration table";
			cal_table.if_name = if_name;
			cal_table.SDAQaddr = SDAQ_if_data[i].Address;
			cal_table.Max_cal_point = SDAQ_if_data[i].SDAQ_info.Max_cal_point;
			CHs.children.push(cal_table);
			SDAQ.children.push(CHs);
			//Add Status
			let Status = {};
			Status.name = "Status";
			Status.data_table_pos = i;
			Status.SDAQ_SN = SDAQ_if_data[i].Serial_number;
			SDAQ.children.push(Status);
			//Add Info
			let Info = {};
			Info.name = "Info";
			Info.data_table_pos = i;
			Info.SDAQ_SN = SDAQ_if_data[i].Serial_number;
			SDAQ.children.push(Info);
			//Push SDAQ to SDAQs tree
			SDAQs.push(SDAQ);
		}
		return SDAQs;
	}
	//Check for incompatible inputs
	if(!SDAQ_logstat || typeof(SDAQ_logstat)!=="object" || !SDAQ_logstat.SDAQs_data)
		return;
	//Logstat to dev_tree converter
	return get_SDAQ_if_chidren(SDAQ_logstat.SDAQs_data, SDAQ_logstat.CANBus_interface);
}
//@license-end