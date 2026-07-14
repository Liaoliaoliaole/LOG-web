//@license magnet:?xt=urn:btih:0b31508aeb0634b347b8270c7bee4d411b5d4109&dn=agpl-3.0.txt AGPL-v3.0
/*
@licstart  The following is the entire license notice for the
JavaScript code in this page.

Copyright (C) 12021-12022  Sam Harry Tzavaras

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
function build_opcua_config_table(curr_opcua_config)
{
	if(!curr_opcua_config)
		return;
	let tableData = [];
	for(let i=0; i<curr_opcua_config.length; i++)
	{
		let table_data_entry = {};
		table_data_entry.id=i;
		table_data_entry.order_num = i+1;
		table_data_entry.iso_name=curr_opcua_config[i].ISO_CHANNEL;
		table_data_entry.type=curr_opcua_config[i].INTERFACE_TYPE;
		table_data_entry.dev_type=curr_opcua_config[i].INTERFACE_TYPE
		table_data_entry.desc=curr_opcua_config[i].DESCRIPTION;
		table_data_entry.min=Number(curr_opcua_config[i].MIN);
		table_data_entry.max=Number(curr_opcua_config[i].MAX);

		if(curr_opcua_config[i].hasOwnProperty('ALARM_HIGH_VAL'))
			table_data_entry.alarmHighVal=curr_opcua_config[i].ALARM_HIGH_VAL;
		if(curr_opcua_config[i].hasOwnProperty('ALARM_LOW_VAL'))
			table_data_entry.alarmLowVal=curr_opcua_config[i].ALARM_LOW_VAL;
		if(curr_opcua_config[i].hasOwnProperty('ALARM_HIGH'))
			table_data_entry.alarmHigh=curr_opcua_config[i].ALARM_HIGH;
		if(curr_opcua_config[i].hasOwnProperty('ALARM_LOW'))
			table_data_entry.alarmLow=curr_opcua_config[i].ALARM_LOW;
		if(curr_opcua_config[i].hasOwnProperty('UNIT'))
			table_data_entry.unit=curr_opcua_config[i].UNIT;
		if(curr_opcua_config[i].hasOwnProperty('CAL_DATE') && curr_opcua_config[i].hasOwnProperty('CAL_PERIOD'))
		{
				let cal_date_split = curr_opcua_config[i].CAL_DATE.split('/'), cal_date = new Date(0);
				cal_date.setFullYear(Number(cal_date_split[0]),
									 Number(cal_date_split[1])-1,
									 Number(cal_date_split[2]));
				let	valid_until = new Date(cal_date.setMonth(cal_date.getMonth()+Number(curr_opcua_config[i].CAL_PERIOD)));
				if(Date.now() >= valid_until.getTime())
				{
					table_data_entry.col = "orange";
					table_data_entry.status = "Cal not valid";
				}
				table_data_entry.valid_until = valid_until;
				table_data_entry.cal_date = curr_opcua_config[i].CAL_DATE;
				table_data_entry.cal_period = Number(curr_opcua_config[i].CAL_PERIOD);
		}
		if(curr_opcua_config[i].hasOwnProperty('BUILD_DATE'))
			table_data_entry.Build_date = new Date(curr_opcua_config[i].BUILD_DATE*1000);
		if(curr_opcua_config[i].hasOwnProperty('MOD_DATE'))
			table_data_entry.Mod_date = new Date(curr_opcua_config[i].MOD_DATE*1000);
		table_data_entry.unit=curr_opcua_config[i].UNIT;
		table_data_entry.anchor = curr_opcua_config[i].ANCHOR;
		table_data_entry.graph = new Array();
		tableData.push(table_data_entry);
	}
	opcua_config_table.setData(tableData);
}
function load_data_to_opcua_config_table(curr_logstats_comb)
{
	if(!curr_logstats_comb)
		return;
	let tableData = opcua_config_table.getData(),
		selectedRows_ids = [];
	if(typeof(curr_logstats_comb)==="object")
	{
		let selectedRows = opcua_config_table.getSelectedRows();
		for(let i=0; i<tableData.length; i++)
		{
			let data = get_from_common_logstats_by_anchor(curr_logstats_comb, tableData[i].type, tableData[i].anchor);
			if(!data)
			{
				tableData[i].col="black";
				tableData[i].dev_type = tableData[i].type;
				tableData[i].meas = '-';
				tableData[i].status = "OFF-Line";
				tableData[i].graph = [];
				if(tableData[i].dev_type === "SDAQ")
				{
					let anchor_parts = tableData[i].anchor.split('.');
					anchor_parts[0] = parseInt(anchor_parts[0]).toString(16).toUpperCase();
					tableData[i].conn = "0x"+anchor_parts[0]+'.'+anchor_parts[1];
				}
				else if(tableData[i].dev_type !== "SDAQ" && tableData[i].dev_type != "NOX")
				{
					let anchor_parts = tableData[i].anchor.split('.'), ip = [], logstat;
					for(let i=0; i<4; i++)
						ip[i] = ((anchor_parts[0]>>(8*i))&0xff);
					ip = ip.join('.');
					tableData[i].conn = '('+ip+').'+anchor_parts[1]+'.'+anchor_parts[2];
					logstat = get_from_common_logstats_by_IPv4(curr_logstats_comb, tableData[i].type, ip);
					if(logstat && logstat.connections[3].value === "Okay")
						tableData[i].status = "Disconnected";
				}
				else
					tableData[i].conn=tableData[i].anchor.toUpperCase();
				continue;
			}
			tableData[i].conn = data.sensorUserId;
			if(data.unit)
				tableData[i].unit = data.unit;
			tableData[i].col = data.Is_meas_valid?'green':'red';
			tableData[i].status = data.Is_meas_valid?'Okay':data.Error_explanation;
			if(tableData[i].type==="SDAQ")
			{
				tableData[i].dev_type = data.deviceUserIdentifier;
				if(!isNaN(data.calibrationDate) && !isNaN(data.calibrationPeriod) && data.calibrationDate)
				{
					let cal_date = new Date(data.calibrationDate*1000),
						valid_until = new Date(cal_date.setMonth(cal_date.getMonth()+data.calibrationPeriod));
					if(Date.now() >= valid_until.getTime())
					{
						tableData[i].col="orange";
						tableData[i].status = "Cal not valid";
					}
					tableData[i].valid_until=valid_until;
					tableData[i].cal_date = cal_date;
					tableData[i].cal_period = data.calibrationPeriod;
				}
				else
				{
					tableData[i].cal_date=null;
					tableData[i].valid_until=null;
					tableData[i].cal_period=null;
				}
			}
			else if(tableData[i].valid_until)
			{
				if(typeof(tableData[i].valid_until.getMonth)==='function')
				{
					if(Date.now() >= tableData[i].valid_until.getTime())
					{
						tableData[i].col="orange";
						tableData[i].status = "Cal not valid";
					}
				}
			}
			if(!isNaN(data.avgMeasurement) && data.Is_meas_valid)
			{
				tableData[i].meas = data.avgMeasurement.toFixed(3)+' '+tableData[i].unit;
				if(tableData[i].graph.length>=GRAPH_LENGTH)
					tableData[i].graph.shift();
				tableData[i].graph.push(data.avgMeasurement.toFixed(1));
			}
			else
			{
				tableData[i].col = 'red';
				tableData[i].status = data.Error_explanation;
				tableData[i].meas = '-';
				tableData[i].graph = [];
			}
		}
		if(selectedRows.length)
		{
			for(let i=0; i<selectedRows.length; i++)
				selectedRows_ids.push(selectedRows[i]._row.data.id);
		}
		opcua_config_table.updateOrAddData(tableData);
		//opcua_config_table.replaceData(tableData);
		if(selectedRows_ids)
			opcua_config_table.selectRow(selectedRows_ids);
	}
}
function ISOChannel_edit(event, cell)
{
	let popup_win = PopupCenter("./ISO_CH_MOD.html"+"?q="+makeid(), "", 600, 440);
	if(cell.hasOwnProperty("_cell"))
		popup_win.curr_config = cell.getRow().getData();
	else
		popup_win.curr_config = cell._row.data;
	popup_win.curr_iso_channels = opcua_config_table.getData();
	edit_wins.push(popup_win);
}
function ISOChannel_add()
{
	let popup_win = PopupCenter("./ISO_CH_ADD.html"+"?q="+makeid(), "", 600, 440);
	popup_win.curr_iso_standards = iso_standard;
	popup_win.curr_iso_channels = opcua_config_table.getData();
	add_wins.push(popup_win);
}
function ISOChannels_add()
{
	let popup_win = PopupCenter("./ISO_CHs_ADD.html"+"?q="+makeid(), "", 600, 440);
	popup_win.curr_iso_standards = iso_standard;
	popup_win.curr_iso_channels = opcua_config_table.getData();
	add_wins.push(popup_win);
}
function ISOChannels_import()
{
	if(import_win && !import_win.closed)
	{
		import_win.focus();
		return;
	}
	import_win = PopupCenter("./ISO_CH_IMPORT.html"+"?q="+makeid(), "", 500, 450);
	import_win.curr_iso_channels = opcua_config_table.getData();
}
function _ISOChannel_export_constractor(tableData)
{
	let ISOChannels_tbl = [];

	if(!tableData || !tableData.length)
		return;
	for(let i=0; i<tableData.length; i++)
	{
		let ISOChannel_entry = {};

		ISOChannel_entry.ISO_CHANNEL = tableData[i].iso_name;
		ISOChannel_entry.INTERFACE_TYPE = tableData[i].type;
		ISOChannel_entry.ANCHOR = tableData[i].anchor;
		ISOChannel_entry.DESCRIPTION = tableData[i].desc;
		ISOChannel_entry.MIN = tableData[i].min;
		ISOChannel_entry.MAX = tableData[i].max;
		if(tableData[i].hasOwnProperty('alarmHighVal'))
			ISOChannel_entry.ALARM_HIGH_VAL = tableData[i].alarmHighVal;
		if(tableData[i].hasOwnProperty('alarmLowVal'))
			ISOChannel_entry.ALARM_LOW_VAL = tableData[i].alarmLowVal;
		if(tableData[i].hasOwnProperty('alarmHigh'))
			ISOChannel_entry.ALARM_HIGH = tableData[i].alarmHigh;
		if(tableData[i].hasOwnProperty('alarmLow'))
			ISOChannel_entry.ALARM_LOW = tableData[i].alarmLow;

		if(tableData[i].type !== "SDAQ")
		{
			ISOChannel_entry.UNIT = tableData[i].unit;
			if(tableData[i].valid_until)
			{
				ISOChannel_entry.CAL_DATE = tableData[i].cal_date;
				ISOChannel_entry.CAL_PERIOD = tableData[i].cal_period;
			}
		}
		ISOChannels_tbl.push(ISOChannel_entry);
	}
	return ISOChannels_tbl;
}

function ISOChannels_export_all()
{
	let tableData = opcua_config_table.getData(),ISOChannels_tbl;

	if(!tableData.length)
		return;
	ISOChannels_tbl = _ISOChannel_export_constractor(tableData);
	Morfeas_ISOChannels_export(ISOChannels_tbl, "All");
}
function ISOChannels_export_all_selected()
{
	let tableData = opcua_config_table.getSelectedData(),ISOChannels_tbl;

	if(!tableData.length)
		return;
	ISOChannels_tbl = _ISOChannel_export_constractor(tableData);
	Morfeas_ISOChannels_export(ISOChannels_tbl, "Selection");
}
function ISOChannels_export_all_visible()
{
	let tableData = opcua_config_table.getData("active"),ISOChannels_tbl;

	if(!tableData.length)
		return;
	ISOChannels_tbl = _ISOChannel_export_constractor(tableData);
	Morfeas_ISOChannels_export(ISOChannels_tbl, "Visible");
}
function Morfeas_ISOChannels_export(ISOChannels_tbl, exp_type)
{
	if(!ISOChannels_tbl)
		return;
	if(typeof(exp_type)!=="string")
		exp_type = '';
	else
		exp_type += '_';
	let now = new Date(),
		filename = "Morfeas_ISOChannel_Linker_Export_"+exp_type+
				   now.getFullYear()+'_'+(now.getMonth()+1)+'_'+now.getDate()+".json";
	download(filename, JSON.stringify(ISOChannels_tbl, null, '\t'), "application/json;charset=utf-8");
}

function _ISOChannel_del_constructor(data)
{
	let del_ISOChannel = {};

	del_ISOChannel.ISOChannel = data.iso_name;
	del_ISOChannel.IF_type = data.type;
	del_ISOChannel.Anchor = data.anchor;
	del_ISOChannel.Description = data.desc;
	del_ISOChannel.Min = data.min;
	del_ISOChannel.Max = data.max;
	return del_ISOChannel;
}
function ISOChannel_delete_curr(event, row)
{
	if(!row)
		return;
	let data = row._row.data,
		del_ISOChannels_tbl = [];

	if(confirm("The ISOChannel \""+data.iso_name+"\" will be deleted\nContinue?"))
	{
		del_ISOChannels_tbl.push(_ISOChannel_del_constructor(data));
		ISOChannels_delete_post(del_ISOChannels_tbl);
	}
}
function ISOChannels_delete_all_selected()
{
	let data = opcua_config_table.getSelectedData(),
		del_ISOChannels_tbl = [];

	if(data.length && confirm(data.length+" ISOChannel"+(data.length>1?'s':'')+" will be deleted\nContinue?"))
	{
		for(let i=0; i<data.length; i++)
			del_ISOChannels_tbl.push(_ISOChannel_del_constructor(data[i]));
		ISOChannels_delete_post(del_ISOChannels_tbl);
	}
}
function ISOChannels_delete_all_visible()
{
	let data = opcua_config_table.getData("active"),
		del_ISOChannels_tbl = [];

	if(data.length && confirm(data.length+" ISOChannel"+(data.length>1?'s':'')+" will be deleted\nContinue?"))
	{
		for(let i=0; i<data.length; i++)
			del_ISOChannels_tbl.push(_ISOChannel_del_constructor(data[i]));
		ISOChannels_delete_post(del_ISOChannels_tbl);
	}
}
function ISOChannels_delete_post(data_tb)
{
	if(!data_tb)
		return;
	let post_msg_contents = {},
		post_xhttp = new XMLHttpRequest(),
		status_tab = document.getElementById("status_tab");

	post_xhttp.timeout = 2000;
	post_xhttp.ontimeout = function(){
		status_tab.value = "Connection to server: Timeout Error";
		status_tab.style.color='blue';
	};
	post_xhttp.onreadystatechange = function(){
		if(this.readyState == 4 && this.status == 200)
		{
			if(this.getResponseHeader("Content-Type")==="report/text")
			{
				status_tab.value = this.responseText;
				status_tab.style.color='red';
				console.log(this.responseText);
			}
		}
		else if(this.status == 404)
		{
			status_tab.value = "Error 404: Data Not found";
			status_tab.style.color='red';
		}
	};
	//Prepare message contents.
	post_msg_contents.COMMAND = "DEL";
	post_msg_contents.DATA = data_tb;
	post_xhttp.open("POST", "/morfeas_php/morfeas_web_if.php", true);
	post_xhttp.send(compress(JSON.stringify(post_msg_contents)));
}
function ISOChannel_tooltip(cell)
{
	if(!cell)
		return;
	let data = cell._cell.row.data, ret = "";

	if(data.hasOwnProperty('Build_date'))
		ret += "Build_date: "+data.Build_date.toLocaleDateString()+" "+data.Build_date.toLocaleTimeString()+'\n';
	if(data.hasOwnProperty('Mod_date'))
		ret += " Mod_date: "+data.Mod_date.toLocaleDateString()+" "+data.Mod_date.toLocaleTimeString()+'\n';
	return ret;
}
function valid_until_tooltip(cell)
{
	if(!cell)
		return;
	let data = cell._cell.row.data;

	if(data.valid_until && data.cal_period)
	{
		let last_cal = new Date(data.valid_until);
		last_cal.setMonth(last_cal.getMonth()-Number(data.cal_period));
		return  "Last Calibration: "+last_cal.toLocaleDateString()+'\n'+
				"Re-Calibration every: "+data.cal_period+' Month'+(data.cal_period>1?'s':'');
	}
	return;
}
function Alarms_tooltip(cell)
{
	if(!cell)
		return;
	let data = cell._cell.row.data;

	if(data.alarmLowVal && data.alarmHighVal && data.alarmHigh && data.alarmLow)
		return "Alarm High: "+data.alarmHighVal+', En: '+data.alarmHigh+'\n'+
			   "Alarm Low: "+data.alarmLowVal+', En: '+data.alarmLow;
	return;
}

var ISOChannels_menu = [
	{label:"Add ISOChannel", action:ISOChannel_add},
	{label:"Add ISOChannels", action:ISOChannels_add},
	{separator:true},
	{label:"Import", action:ISOChannels_import},
	{label:"Export All", action:ISOChannels_export_all}
];
var rowMenu = [
	{label:"Edit", action:ISOChannel_edit},
	{label:"Delete", action:ISOChannel_delete_curr},
	{separator:true},
	{
		label:"All selected",
		menu:[
			{label:"Export", action:ISOChannels_export_all_selected},
			{label:"Delete", action:ISOChannels_delete_all_selected}
		]
	},
	{
		label:"All visible",
		menu:[
			{label:"Export", action:ISOChannels_export_all_visible},
			{label:"Delete", action:ISOChannels_delete_all_visible}
		]
	},
	{label:"Deselect All", action:function(){opcua_config_table.deselectRow();}}
]
//@license-end
