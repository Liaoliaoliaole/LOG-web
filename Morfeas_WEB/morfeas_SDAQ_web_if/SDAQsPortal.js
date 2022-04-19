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
var dev_tree;

function data_update(SDAQnet_data, update_tree)
{
	let SDAQnet_stats = document.getElementsByName("SDAQnet_stats"),
		Det_devs = document.getElementById("Det_devs"),
		Bus_util = document.getElementById("Bus_util"),
		Bus_Voltage = document.getElementById("Bus_Voltage"),
		Bus_Amperage = document.getElementById("Bus_Amperage");

	if(!SDAQnet_data)
		return;
	//Update SDAQnet stats
	for(let i=0; i<SDAQnet_stats.length; i++)
	{
		if(i<2)
			SDAQnet_stats[i].hidden = false;
		else
			SDAQnet_stats[i].hidden = SDAQnet_data.hasOwnProperty('Electrics')?false:true;
	}
	Det_devs.innerHTML = "Detected SDAQs: "+SDAQnet_data.Detected_SDAQs+'/'+SDAQnet_data.Incomplete_SDAQs;
	Bus_util.innerHTML = "Bus Util: "+SDAQnet_data.BUS_Utilization.toFixed(2)+'%';
	if(SDAQnet_data.hasOwnProperty('Electrics'))
	{
		Bus_Voltage.innerHTML = "Bus Voltage: "+SDAQnet_data.Electrics.BUS_voltage.toFixed(2)+'V';
		Bus_Amperage.innerHTML = "Bus current: "+SDAQnet_data.Electrics.BUS_amperage.toFixed(2)+'A';
	}
	//console.log(SDAQnet_data);

	if(update_tree)
	{
		let SDAQnet_logstat_tree = morfeas_build_dev_tree_from_SDAQ_logstat(SDAQnet_data);

		if(SDAQnet_logstat_tree)
		{
			dev_tree = new TreeView(SDAQnet_logstat_tree, 'Dev_tree');
			dev_tree.on('collapseAll', clean_sel_data);
			//dev_tree.on('collapse', clean_sel_data);
			dev_tree.on('select', select_callback);
			function clean_sel_data()
			{
				document.getElementById("stat_info_table").innerHTML="";
				document.getElementById("meas_table").innerHTML="";
				data_update.selection = undefined;
			}
			function select_callback(elem)
			{
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
					case "Info":
					default:
						data_update.selection = elem.data;
						break;
				}
			}
		}
		else
		{
			document.getElementById('Dev_tree').innerHTML='No SDAQs';
			document.getElementById("stat_info_table").innerHTML="";
			document.getElementById("meas_table").innerHTML="";
			data_update.selection = {};
		}
	}
	if(data_update.selection)
	{
		var table = document.getElementById("stat_info_table"),
			meas_table = document.getElementById("meas_table");

		if(SDAQnet_data.hasOwnProperty('SDAQs_data') &&
		   SDAQnet_data.SDAQs_data[data_update.selection.data_table_pos] &&
		   SDAQnet_data.SDAQs_data[data_update.selection.data_table_pos].Serial_number === data_update.selection.SDAQ_SN)
		{
			let selected_SDAQ = SDAQnet_data.SDAQs_data[data_update.selection.data_table_pos],
				data = [];

			table.innerHTML = "";
			meas_table.innerHTML = "";
			switch(data_update.selection.name)
			{
				case "Info":
					generateTableHead(table, ["Info for "+selected_SDAQ.SDAQ_type+"(ADDR:"+norm(selected_SDAQ.Address,2)+")"], 2);
					for(let j in selected_SDAQ.SDAQ_info)
						data.push([j, selected_SDAQ.SDAQ_info[j]]);
					generateTable(table, data);
					if(selected_SDAQ.SDAQ_type !== "Pseudo_SDAQ")
					{
						for(let j=0; table.rows.length; j++)
						{
							if(table.rows[j].innerText.includes("firm_rev"))
							{
								table.rows[j].onclick = function(){
									up_firm_wins.push(PopupCenter("/morfeas_SDAQ_web_if/SDAQ_update/SDAQ_update.html"+
																  "?SDAQnet="+SDAQnet_data.CANBus_interface+
																  "&SDAQaddr="+selected_SDAQ.Address+
																  "&q="+makeid(), "", "410", "170"));
								};
								break;
							}
						}
					}
					break;
				case "Status":
					generateTableHead(table, ["Status of "+selected_SDAQ.SDAQ_type+"(ADDR:"+norm(selected_SDAQ.Address,2)+")"], 2);
					for(let j in selected_SDAQ.SDAQ_Status)
						data.push([j, selected_SDAQ.SDAQ_Status[j]]);
					generateTable(table, data);
					break;
				default:
					//Build Table for Calibration Data
					if((data = Build_cal_data_array(SDAQnet_data.SDAQs_data[data_update.selection.data_table_pos].Calibration_Data[data_update.selection.channel])))
					{
						generateTableHead(table, ["Calibration data for "+selected_SDAQ.SDAQ_type+
												  "(ADDR:"+norm(selected_SDAQ.Address, 2)+"):CH"+
												  norm(data_update.selection.channel+1, 2)], 2);
						generateTable(table, data);
					}
					//Build Table for Measurements
					if((data = Build_meas_array(SDAQnet_data.SDAQs_data[data_update.selection.data_table_pos].Meas[data_update.selection.channel])))
					{
						generateTableHead(meas_table, ["Measurement of "+selected_SDAQ.SDAQ_type+
													   "(ADDR:"+norm(selected_SDAQ.Address, 2)+"):CH"+
													   norm(data_update.selection.channel+1, 2)], 2);
						generateTable(meas_table, data);
					}
					break;
			}
		}
	}
}

function Build_cal_data_array(CH_cal_data)
{
	var ret = [], cal_date;

	if(!CH_cal_data)
		return;
	if(CH_cal_data.Is_calibrated)
	{
		if(CH_cal_data.Amount_of_points)
		{
			ret.push(["SDAQ's Channel:", CH_cal_data.Channel]);
			cal_date = new Date(CH_cal_data.Calibration_date_UNIX*1000);
			ret.push(["Calibration Date:", cal_date.toLocaleDateString()]);
			ret.push(["Valid for:", CH_cal_data.Calibration_period+" Month"+(CH_cal_data.Calibration_period>1?'s':'')]);
			ret.push(["Calibration Unit:", '"'+CH_cal_data.Unit+'"']);
		}
		else
			ret.push(["Calibration not used"]);
		return ret;
	}
	else
		return [["No Calibration data for Channel "+ CH_cal_data.Channel]];
}
function Build_meas_array(CH_meas_data)
{
	var ret = [];

	if(!CH_meas_data)
		return;
	if(!CH_meas_data.Channel_Status.Channel_status_val || CH_meas_data.Channel_Status.Channel_status_val == 2)
	{
		if(CH_meas_data.Channel_Status.Out_of_Range)
			ret.push(["Calculation Error:", "Out of calibration range"]);
		ret.push(["Measurements group average:", !isNaN(CH_meas_data.Meas_avg)?CH_meas_data.Meas_avg.toFixed(3)+' '+CH_meas_data.Unit:'-']);
		if(!isNaN(CH_meas_data.Meas_avg))
		{
			if(!isNaN(CH_meas_data.Meas_max)&&!isNaN(CH_meas_data.Meas_min))
			{
				ret.push(["Measurements group max:", CH_meas_data.Meas_max.toFixed(3)+' '+CH_meas_data.Unit]);
				ret.push(["Measurements group min:", CH_meas_data.Meas_min.toFixed(3)+' '+CH_meas_data.Unit]);
				ret.push(["Measurements group range:", (CH_meas_data.Meas_max-CH_meas_data.Meas_min).toFixed(3)+' '+CH_meas_data.Unit]);
			}
			if(!isNaN(CH_meas_data.Last_Meas))
				ret.push(["Measurements group deviation:", (100.0*(CH_meas_data.Meas_avg - CH_meas_data.Last_Meas)/CH_meas_data.Meas_avg).toFixed(2)+'%']);
		}
		return ret;
	}
	else
	{
		if(CH_meas_data.Channel_Status.No_Sensor)
			ret.push(["Input Error:", "No Sensor"]);
		if(CH_meas_data.Channel_Status.Over_Range)
			ret.push(["Input Error:", "Over range"]);
		return ret;
	}
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