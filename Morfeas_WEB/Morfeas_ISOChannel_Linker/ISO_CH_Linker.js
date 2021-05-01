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
function build_opcua_config_table(curr_opcua_config)
{
	if(!curr_opcua_config)
		return;
	let tableData = [];
	for(let i=0; i<curr_opcua_config.length; i++)
	{
		let table_data_entry = {};
		table_data_entry.id=i;
		table_data_entry.iso_name=curr_opcua_config[i].ISO_CHANNEL;
		table_data_entry.type=curr_opcua_config[i].INTERFACE_TYPE;
		table_data_entry.desc=curr_opcua_config[i].DESCRIPTION;
		table_data_entry.min=curr_opcua_config[i].MIN;
		table_data_entry.max=curr_opcua_config[i].MAX;
		if(curr_opcua_config[i].hasOwnProperty('UNIT'))
			table_data_entry.unit=curr_opcua_config[i].UNIT;
		table_data_entry.anchor = curr_opcua_config[i].ANCHOR;
		table_data_entry.graph = new Array();
		tableData.push(table_data_entry);
	}
	opcua_config_table.setData(tableData);
}
function load_data_to_opcua_config_table(curr_logstats)
{
	if(!curr_logstats)
		return;
	let tableData = opcua_config_table.getData(),
		curr_logstats_com = morfeas_logstat_commonizer(curr_logstats);
	if(typeof(curr_logstats_com)==="object")
	{
		for(let i=0; i<tableData.length; i++)
		{
			let data = get_from_common_logstats_by_anchor(curr_logstats_com, tableData[i].type, tableData[i].anchor);
			if(!data)
			{
				tableData[i].col="red";
				tableData[i].conn="OFF-Line";
				tableData[i].meas = '-';
				tableData[i].status = '-';
				tableData[i].graph = [];
				continue;
			}
			tableData[i].conn = data.sensorUserId;
			if(data.unit)
				tableData[i].unit=data.unit;
			tableData[i].col=data.Is_meas_valid?'green':'yellow';
			tableData[i].status=data.Is_meas_valid?'Okay':data.Error_explanation;
			if(typeof(data.calibrationDate)==='number' && typeof(data.calibrationPeriod)=='number')
			{
				let cal_date = new Date(data.calibrationDate*1000),
					valid_until = new Date(cal_date.setMonth(cal_date.getMonth()+data.calibrationPeriod));
				if(new Date() >= valid_until)
				{
					tableData[i].col="orange";
					tableData[i].status = 'Cal no valid';
				}
				tableData[i].cal_date=valid_until;
			}
			else
				tableData[i].cal_date=null;
			if(typeof(data.avgMeasurement)==='number')//data.Is_meas_valid)
			{
				tableData[i].meas = data.avgMeasurement.toFixed(3)+' '+tableData[i].unit;
				if(tableData[i].graph.length>=GRAPH_LENGTH)
					tableData[i].graph.shift();
				tableData[i].graph.push(data.avgMeasurement.toFixed(1));
			}
			else
			{
				tableData[i].meas = '-';
				tableData[i].graph = [];
			}
		}
	}
	opcua_config_table.replaceData(tableData);
}
//@license-end
