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
FOR A PARTICULAR PURPOSE.  See the GNU AGPL for more details.

@licend  The above is the entire license notice
for the JavaScript code in this page.
*/
"use strict";
/*
 *ANSI escape sequences for color output taken from here:
 * https://stackoverflow.com/questions/3219393/stdlib-and-colored-output-in-c

# define ANSI_COLOR_RED     "\x1b[31m"
# define ANSI_COLOR_GREEN   "\x1b[32m"
# define ANSI_COLOR_YELLOW  "\x1b[33m"
# define ANSI_COLOR_BLUE    "\x1b[34m"
# define ANSI_COLOR_MAGENTA "\x1b[35m"
# define ANSI_COLOR_CYAN    "\x1b[36m"
# define ANSI_COLOR_RESET   "\x1b[0m"
*/
function morfeas_opcua_logger_colorizer(inp)
{
	var ret;
	if(inp === undefined)
		return;
	ret = inp;
	ret = ret.replace(/\n/g, "<br>");//tag /n as <br>
	ret = ret.replace(/\x1b\[0m/g, "</a>");
	ret = ret.replace(/\x1b\[31m/g, "<a style=\"color:red\">");
	ret = ret.replace(/\x1b\[32m/g, "<a style=\"color:green\">");
	ret = ret.replace(/\x1b\[33m/g, "<a style=\"color:gold\">");
	ret = ret.replace(/\x1b\[34m/g, "<a style=\"color:blue\">");
	ret = ret.replace(/\x1b\[35m/g, "<a style=\"color:magenta\">");
	ret = ret.replace(/\x1b\[36m/g, "<a style=\"color:cyan\">");
	return ret;
}

function morfeas_logstat_commonizer(logstats)
{
	var data_table_index, dev_index, sensor_index;
	var data_table = new Array();

	//Check for incompatible input
	if(!logstats)
		return "no logstats type data";
	if(typeof(logstats)==="string")
	{
		try {logstats = JSON.parse(logstats);}
		catch{return "Parsing error";}
	}
	if(!logstats.logstats_names)
		return "missing logstats_names";
	if(!logstats.logstat_contents)
		return "missing logstat_contents";

	function sensor(type,
				deviceUserIdentifier,
				sensorUserId,
				anchor,
				unit,
				calibrationDate,
				calibrationPeriod,
				avgMeasurement,
				Is_meas_valid,
				Error_explanation)
	{
		this.deviceUserIdentifier = deviceUserIdentifier === undefined ? null : deviceUserIdentifier;
		this.sensorUserId = sensorUserId === undefined ? null : sensorUserId;
		this.anchor = anchor === undefined ? null : anchor;
		this.unit = unit === undefined ? null : unit;
		this.calibrationDate = calibrationDate === undefined ? null : calibrationDate;
		this.calibrationPeriod = calibrationPeriod === undefined ? null : calibrationPeriod;
		this.type = type === undefined ? null : type;
		this.avgMeasurement = avgMeasurement === undefined ? null : avgMeasurement;
		this.Is_meas_valid = Is_meas_valid === undefined ? null : Is_meas_valid;
		this.Error_explanation = Error_explanation === undefined ? '-' : Error_explanation
	}
	function connection(name, value, unit)
	{
		this.name = name === undefined ? null : name;
		this.value = value === undefined ? null : value;
		this.unit = unit === undefined ? null : unit;
	}
	function table_data_entry()
	{
		this.if_name = new Object();
		this.logstat_build_date_UNIX = new Object();
		this.sensors = new Array();
		this.connections = new Array();
	}
	function sys_logstat(logstat)
	{
		let new_data_table_entry = new table_data_entry();
		//Load if_name and build_date
		new_data_table_entry.if_name = "RPi_Health_Status";
		new_data_table_entry.logstat_build_date_UNIX = logstat.logstat_build_date_UNIX;
		//Load system's status
		new_data_table_entry.sensors = null;
		if(logstat.CPU_temp)
			new_data_table_entry.connections.push(new connection("CPU_temp", logstat.CPU_temp.toFixed(1), "°C"));
		new_data_table_entry.connections.push(new connection("CPU_Util", logstat.CPU_Util.toFixed(2), "%"));
		new_data_table_entry.connections.push(new connection("RAM_Util", logstat.RAM_Util.toFixed(2), "%"));
		new_data_table_entry.connections.push(new connection("Disk_Util", logstat.Disk_Util.toFixed(2), "%"));
		new_data_table_entry.connections.push(new connection("Up_time", Seconds_to_human(logstat.Up_time)));
		return new_data_table_entry;
	}
	function MDAQ_logstat(logstat)
	{
		let new_data_table_entry = new table_data_entry();
		//Load IF_name and build_date
		new_data_table_entry.if_name = "MDAQ";
		new_data_table_entry.logstat_build_date_UNIX = logstat.logstat_build_date_UNIX;
		//Load Device's status
		new_data_table_entry.connections.push(new connection("Dev_name", logstat.Dev_name));
		new_data_table_entry.connections.push(new connection("IPv4_address", logstat.IPv4_address));
		new_data_table_entry.connections.push(new connection("Identifier", logstat.Identifier));
		new_data_table_entry.connections.push(new connection("Connection_status", logstat.Connection_status));
		//Load Device's sensors
		if(logstat.MDAQ_Channels !== undefined)
		{
			new_data_table_entry.connections.push(new connection("Board_temp", logstat.Board_temp.toFixed(1), "°C"));
			for(let i=0; i<logstat.MDAQ_Channels.length; i++)
			{
				for(let j=1; j<=3; j++)//limit to 3]
				{
					new_data_table_entry.sensors.push(new sensor
					(
						"MDAQ",
						logstat.Dev_name + " (" + logstat.IPv4_address + ")",
						logstat.Dev_name+".CH:"+logstat.MDAQ_Channels[i].Channel+".Val"+j,
						logstat.Identifier+'.'+"CH"+logstat.MDAQ_Channels[i].Channel+".Val"+j,
						null,null,null,
						eval("logstat.MDAQ_Channels[i].Values.Value"+j),
						eval("logstat.MDAQ_Channels[i].Warnings.Is_Value"+j+"_valid"),
						eval("logstat.MDAQ_Channels[i].Warnings.Is_Value"+j+"_valid")?'-':'No sensor'
					));
				}
			}
		}
		else
			new_data_table_entry.sensors = [];
		return new_data_table_entry;
	}
	function SDAQs_logstat(logstat)
	{
		let new_data_table_entry = new table_data_entry();
		//Load IF_name and build_date
		new_data_table_entry.if_name = "SDAQs ("+logstat.CANBus_interface+")";
		new_data_table_entry.logstat_build_date_UNIX = logstat.logstat_build_date_UNIX;
		//Load Device's status
		new_data_table_entry.connections.push(new connection("BUS_Utilization", logstat.BUS_Utilization.toFixed(1), "%"));
		new_data_table_entry.connections.push(new connection("BUS_Error_Rate", logstat.BUS_Error_rate.toFixed(1), "%"));
		new_data_table_entry.connections.push(new connection("Detected_SDAQs", logstat.Detected_SDAQs));
		new_data_table_entry.connections.push(new connection("Incomplete_SDAQs", logstat.Incomplete_SDAQs));
		if(logstat.Electrics)
		{
			new_data_table_entry.connections.push(new connection("SDAQnet_("+logstat.CANBus_interface+")_last_calibration_UNIX",
																		  logstat.Electrics.Last_calibration_UNIX));
			new_data_table_entry.connections.push(new connection("SDAQnet_("+logstat.CANBus_interface+")_outVoltage",
																		  logstat.Electrics.BUS_voltage.toFixed(2), "V"));
			new_data_table_entry.connections.push(new connection("SDAQnet_("+logstat.CANBus_interface+")_outAmperage",
																		  logstat.Electrics.BUS_amperage.toFixed(2), "A"));
			new_data_table_entry.connections.push(new connection("SDAQnet_("+logstat.CANBus_interface+")_ShuntTemp",
																		  logstat.Electrics.BUS_Shunt_Res_temp.toFixed(1), "°C"));
		}
		//Load Device's sensors
		if(logstat.Detected_SDAQs)
		{
			for(let i=0; i<logstat.SDAQs_data.length; i++)
			{
				if(logstat.SDAQs_data[i].SDAQ_Status.Registration_status !== "Done")
					continue;
				for(let j=0; j<logstat.SDAQs_data[i].Meas.length; j++)
				{
					let error_str='-';
					let meas_val = logstat.SDAQs_data[i].Meas[j].Meas_avg;
					if(logstat.SDAQs_data[i].Meas[j].Channel_Status.Channel_status_val)
					{
						if(logstat.SDAQs_data[i].Meas[j].Channel_Status.Out_of_Range)
							error_str='Out of Range';
						else if(logstat.SDAQs_data[i].Meas[j].Channel_Status.No_Sensor)
						{
							error_str='No sensor';
							meas_val='-';
						}
						else if(logstat.SDAQs_data[i].Meas[j].Channel_Status.Over_Range)
						{
							error_str='Over Range';
							meas_val='-';
						}
						else
							error_str='Unclassified';
					}
					new_data_table_entry.sensors.push(new sensor
					(
						"SDAQ",
						logstat.SDAQs_data[i].SDAQ_type,
						logstat.CANBus_interface.toUpperCase()+".ADDR:"+norm(logstat.SDAQs_data[i].Address,2)+".CH:"+norm(logstat.SDAQs_data[i].Meas[j].Channel,2),
						logstat.SDAQs_data[i].Serial_number+".CH"+logstat.SDAQs_data[i].Meas[j].Channel,
						logstat.SDAQs_data[i].Meas[j].Unit,
						logstat.SDAQs_data[i].Calibration_Data[j].Calibration_date_UNIX,
						logstat.SDAQs_data[i].Calibration_Data[j].Calibration_period,
						meas_val,
						!(logstat.SDAQs_data[i].Meas[j].Channel_Status.Channel_status_val),
						error_str
					));
				}
			}
		}
		else
			new_data_table_entry.sensors = [];
		return new_data_table_entry;
	}
	function MTI_logstat(logstat)
	{
		let new_data_table_entry = new table_data_entry();
		//Load IF_name and build_date
		new_data_table_entry.if_name = "MTI";
		new_data_table_entry.logstat_build_date_UNIX = logstat.logstat_build_date_UNIX;
		//Load Device's status
		new_data_table_entry.connections.push(new connection("Dev_name", logstat.Dev_name));
		new_data_table_entry.connections.push(new connection("IPv4_address", logstat.IPv4_address));
		new_data_table_entry.connections.push(new connection("Identifier", logstat.Identifier));
		new_data_table_entry.connections.push(new connection("Connection_status", logstat.Connection_status));
		//Load Device's sensors
		if(logstat.Connection_status === "Okay")
		{
			new_data_table_entry.connections.push(new connection("CPU_temp", logstat.MTI_status.MTI_CPU_temp.toFixed(1), "°C"));
			new_data_table_entry.connections.push(new connection("Battery state", logstat.MTI_status.MTI_charge_status));
			if(logstat.MTI_status.MTI_charge_status !== "Charging" && logstat.MTI_status.MTI_charge_status !== "Full")
				new_data_table_entry.connections.push(new connection("Battery capacity", logstat.MTI_status.MTI_batt_capacity));
			new_data_table_entry.connections.push(new connection("Radio_mode", logstat.MTI_status.Tele_Device_type));
			new_data_table_entry.connections.push(new connection("RF Channel", logstat.MTI_status.Radio_CH));
			if(logstat.MTI_status.Tele_Device_type !== "RMSW/MUX")
			{
				let lim;
				switch(logstat.MTI_status.Tele_Device_type)
				{
					case "TC16":
						lim=16;//limit to 16], max amount of channels on a TC16 Telemetry.
						break;
					case "TC8":
						lim=8;//limit to 8], max amount of channels on a TC8 Telemetry.
						break;
					case "TC4":
						lim=4;//limit to 4], max amount of channels on a TC4 Telemetry.
						break;
					case "QUAD":
						lim=2;//limit to 2], max amount of channels on a Quadrature counter Telemetry.
						break;
					default: lim=0; new_data_table_entry.sensors = null;
				}
				for(let i=0; i<lim; i++)
				{
					new_data_table_entry.sensors.push(new sensor
					(
						"MTI",
						logstat.Dev_name+" ("+logstat.IPv4_address+')',
						logstat.Dev_name+'.'+logstat.MTI_status.Tele_Device_type+".CH:"+(i+1),
						logstat.Identifier+"."+logstat.MTI_status.Tele_Device_type+"."+"CH"+(i+1),
						lim!==2?"°C":"",null,null,
						logstat.Tele_data.CHs[i],
						logstat.Tele_data.IsValid && typeof(logstat.Tele_data.CHs[i])=='number',
						!logstat.Tele_data.RX_Success_Ratio?'Disconnected':typeof(logstat.Tele_data.CHs[i])!=='number'?logstat.Tele_data.CHs[i]:'-'
					));
				}
			}
			else if(logstat.MTI_status.Tele_Device_type === "RMSW/MUX")
			{
				for(let i=0; i<logstat.Tele_data.length; i++)
				{
					if(logstat.Tele_data[i].Dev_type === "Mini_RMSW")
					{
						for(let j=0; j<4; j++)
						{
							new_data_table_entry.sensors.push(new sensor
							(
								"MTI",
								logstat.Dev_name + " (" + logstat.IPv4_address + ")",
								logstat.Dev_name+'.'+logstat.Tele_data[i].Dev_type+"(ID:"+logstat.Tele_data[i].Dev_ID+").CH:"+(j+1),
								logstat.Identifier+".ID:"+logstat.Tele_data[i].Dev_ID+"."+"CH"+(j+1),
								"°C",null,null,
								logstat.Tele_data[i].CHs_meas[j] !== "No sensor"?
									logstat.Tele_data[i].CHs_meas[j]:null,
								logstat.Tele_data[i].CHs_meas[j] !== "No sensor",
								logstat.Tele_data[i].CHs_meas[j] !== "No sensor"?'-':logstat.Tele_data[i].CHs_meas[j]
							));
						}
					}
				}
			}
		}
		else
			new_data_table_entry.sensors = [];
		return new_data_table_entry;
	}
	function IOBOX_logstat(logstat)
	{
		let new_data_table_entry = new table_data_entry();
		//Load IF_name and build_date
		new_data_table_entry.if_name = "IOBOX";
		new_data_table_entry.logstat_build_date_UNIX = logstat.logstat_build_date_UNIX;
		//Load Device's status
		new_data_table_entry.connections.push(new connection("Dev_name", logstat.Dev_name));
		new_data_table_entry.connections.push(new connection("IPv4_address", logstat.IPv4_address));
		new_data_table_entry.connections.push(new connection("Identifier", logstat.Identifier));
		new_data_table_entry.connections.push(new connection("Connection_status", logstat.Connection_status));
		//Load Device's sensors
		if(logstat.Connection_status === "Okay")
		{
			for(let i=1; i<=4; i++)//limit to 4], amount of receivers on a IOBOX = 4.
			{
				if(eval("logstat.RX"+i) !== "Disconnected")
				{
					for(let j=1; j<=16; j++)//limit to 16], max amount of channels on a telemetry.
					{
						new_data_table_entry.sensors.push(new sensor
						(
							"IOBOX",
							logstat.Dev_name + " (" + logstat.IPv4_address + ")",
							logstat.Dev_name + ".RX"+i+".CH:"+norm(j,2),
							logstat.Identifier+".RX"+i+".CH"+j,
							"°C",null,null,
							eval("logstat.RX"+i+".CH"+j),
							eval("logstat.RX"+i+".CH"+j) !== "No sensor",
							eval("logstat.RX"+i+".CH"+j) !== "No sensor" ? undefined : "No sensor"
						));
					}
				}
			}
		}
		else
			new_data_table_entry.sensors = [];
		return new_data_table_entry;
	}
	function NOXs_logstat(logstat)
	{
		let new_data_table_entry = new table_data_entry();
		//Load IF_name and build_date
		new_data_table_entry.if_name = "NOXs ("+logstat.CANBus_interface+")";
		new_data_table_entry.logstat_build_date_UNIX = logstat.logstat_build_date_UNIX;
		//Load Device's status
		new_data_table_entry.connections.push(new connection("BUS_Utilization", logstat.BUS_Utilization.toFixed(1), "%"));
		new_data_table_entry.connections.push(new connection("BUS_Error_Rate", logstat.BUS_Error_rate.toFixed(1), "%"));
		let det_NOx = 0;
		if(Object.entries(logstat.NOx_sensors))
		{
			for(let j=0; j<logstat.NOx_sensors.length; j++)
				if(Object.entries(logstat.NOx_sensors[j]).length)
					det_NOx++;
		}
		new_data_table_entry.connections.push(new connection("Detected_UniNOx", det_NOx));
		if(logstat.Electrics)
		{
			new_data_table_entry.connections.push(new connection("SDAQnet_("+logstat.CANBus_interface+")_last_calibration_UNIX",
																		  logstat.Electrics.Last_calibration_UNIX));
			new_data_table_entry.connections.push(new connection("SDAQnet_("+logstat.CANBus_interface+")_outVoltage",
																		  logstat.Electrics.BUS_voltage.toFixed(2), "V"));
			new_data_table_entry.connections.push(new connection("SDAQnet_("+logstat.CANBus_interface+")_outAmperage",
																		  logstat.Electrics.BUS_amperage.toFixed(2), "A"));
			new_data_table_entry.connections.push(new connection("SDAQnet_("+logstat.CANBus_interface+")_ShuntTemp",
																		  logstat.Electrics.BUS_Shunt_Res_temp.toFixed(2), "°C"));
		}
		//Load Device's sensors
		if(det_NOx)
		{
			for(let i=0; i<logstat.NOx_sensors.length; i++)
			{
				if(!Object.entries(logstat.NOx_sensors[i]).length)
					continue;
				let error_str = "-";
				if(!logstat.NOx_sensors[i].status.is_NOx_value_valid ||
				   !logstat.NOx_sensors[i].status.is_O2_value_valid)
				{
					if(logstat.NOx_sensors[i].errors.heater !== "No error")
						error_str = logstat.NOx_sensors[i].errors.heater;
					else if(logstat.NOx_sensors[i].errors.NOx !== "No error")
						error_str = logstat.NOx_sensors[i].errors.NOx;
					else if(logstat.NOx_sensors[i].errors.O2 !== "No error")
						error_str = logstat.NOx_sensors[i].errors.O2;
					else
						error_str = logstat.NOx_sensors[i].status.heater_mode_state;
				}
				new_data_table_entry.sensors.push(new sensor(
					"NOX",
					"UniNOx(Addr:"+i+")",
					logstat.CANBus_interface.toUpperCase()+".ADDR:"+i+".NOx",
					logstat.CANBus_interface+".addr_"+i+".NOx",
					"ppm", null, null,
					logstat.NOx_sensors[i].NOx_value_avg,
					logstat.NOx_sensors[i].status.is_NOx_value_valid,
					error_str
				));
				new_data_table_entry.sensors.push(new sensor(
					"NOX",
					"UniNOx(Addr:"+i+")",
					logstat.CANBus_interface.toUpperCase()+".ADDR:"+i+".O2",
					logstat.CANBus_interface+".addr_"+i+".O2",
					"%", null, null,
					logstat.NOx_sensors[i].O2_value_avg,
					logstat.NOx_sensors[i].status.is_O2_value_valid,
					error_str
				));
			}
		}
		else
			new_data_table_entry.sensors = [];
		return new_data_table_entry;
	}
	//Logstat commonizer converter
	for(let i=0; i<logstats.logstats_names.length; i++)
	{
		try{
			if(logstats.logstats_names[i].includes("logstat"))
			{
				data_table_index = data_table.length;
				if(!logstats.logstat_contents[i])
					continue;
				if(logstats.logstats_names[i] === "logstat_sys.json")//RPi_Health_Stats
					data_table[data_table_index] = sys_logstat(logstats.logstat_contents[i]);
				else if(logstats.logstats_names[i].includes("logstat_MDAQ"))//Morfeas_MDAQ_if handlers
					data_table[data_table_index] = MDAQ_logstat(logstats.logstat_contents[i]);
				else if(logstats.logstats_names[i].includes("logstat_MTI"))//Morfeas_MTI_if handlers
					data_table[data_table_index] = MTI_logstat(logstats.logstat_contents[i]);
				else if(logstats.logstats_names[i].includes("logstat_NOXs"))//Morfeas_NOX_if handlers
					data_table[data_table_index] = NOXs_logstat(logstats.logstat_contents[i]);
				else if(logstats.logstats_names[i].includes("logstat_SDAQs"))//Morfeas_SDAQ_if handlers
					data_table[data_table_index] = SDAQs_logstat(logstats.logstat_contents[i]);
				else if(logstats.logstats_names[i].includes("logstat_IOBOX"))//Morfeas_IOBOX_if handlers
					data_table[data_table_index] = IOBOX_logstat(logstats.logstat_contents[i]);
			}
		} catch(err) {
			console.log("At i="+i);
			console.log("Error: "+err);
			console.log(logstats);
		}
	}
	return data_table;
}
function get_from_common_logstats_by_anchor(logstats, type, anchor)
{
	if(!logstats || !anchor ||
	   !logstats.length || !type)
		return false;

	for(let i=0; i<logstats.length; i++)
	{
		if(!logstats[i].sensors || !logstats[i].sensors.length || !logstats[i].if_name.includes(type))
			continue;
		for(let j=0; j<logstats[i].sensors.length; j++)
		{
			if(logstats[i].sensors[j].anchor === anchor)
				return logstats[i].sensors[j];
		}
	}
	return false;
}
function morfeas_build_dev_tree_from_logstats(logstats, dev_type, curr_ISOCHs)
{
	function is_anchor_in_use(Anchor)
	{
		if(!curr_ISOCHs)
			return false;
		for(let i=0; i<curr_ISOCHs.length; i++)
			if(curr_ISOCHs[i].anchor === Anchor)
				return true;
	}
	function get_SDAQ_if_chidren(SDAQ_if_data, if_name)
	{
		let SDAQs = [];
		for(let i=0; i<SDAQ_if_data.length; i++)
		{
			let SDAQ = {};
			SDAQ.name = '(ADDR:'+norm(SDAQ_if_data[i].Address,2)+') '+SDAQ_if_data[i].SDAQ_type;
			SDAQ.addr = SDAQ_if_data[i].Address;
			SDAQ.Status = SDAQ_if_data[i].SDAQ_Status;
			SDAQ.Info = SDAQ_if_data[i].SDAQ_info;
			SDAQ.Timediff = SDAQ_if_data[i].Timediff;
			SDAQ.Serial_number = SDAQ_if_data[i].Serial_number;
			SDAQ.children = [];
			for(let j=0; j<SDAQ_if_data[i].SDAQ_info.Number_of_channels; j++)
			{
				let Channel = {};
				Channel.name = "CH:"+norm((j+1),2);
				Channel.Calibration_Data = SDAQ_if_data[i].Calibration_Data[j];
				Channel.Meas = SDAQ_if_data[i].Meas[j];
				Channel.Path = if_name+".ADDR:"+norm(SDAQ.addr,2)+".CH:"+norm(j+1,2);
				Channel.Anchor = SDAQ.Serial_number+".CH"+(j+1);
				if(curr_ISOCHs && is_anchor_in_use(Channel.Anchor))
					continue;
				SDAQ.children.push(Channel);
			}
			SDAQ.expandable = SDAQ.children.length ? true : false;
			if(!SDAQ.expandable)
				SDAQ.name += "→\"All CHs in Use\"";
			SDAQs.push(SDAQ);
		}
		return SDAQs;
	}

	var data_table_index, dev_index, sensor_index;
	var morfeas_devs_tree = new Array();
	//Check for incompatible inputs
	if(!dev_type)
		return "No dev_type data";
	if(!logstats)
		return "No logstats type data";
	if(typeof(logstats)!=="object")
		return "Invalid type of arg:\"logstats\"";
	//Logstat to dev_tree converter
	let if_handler = {};
	for(let i=0; i<logstats.length; i++)
	{
		switch(dev_type)
		{
			case "SDAQ":
				if_handler = {};
				if_handler.name = logstats[i].CANBus_interface.toUpperCase();
				if(logstats[i].SDAQs_data && logstats[i].SDAQs_data.length)
				{
					if_handler.expanded = true;
					if_handler.children = get_SDAQ_if_chidren(logstats[i].SDAQs_data, if_handler.name);
				}
				morfeas_devs_tree.push(if_handler);
				break;
			case "MDAQ":
				if_handler = {};
				if_handler.name = logstats[i].Dev_name;
				if(logstats[i].Connection_status==="Okay" && logstats[i].MDAQ_Channels.length)
				{
					if_handler.expanded = true;
					if_handler.children = [];
					for(let j=0; j<logstats[i].MDAQ_Channels.length; j++)
					{
						let CH_Vals = [],
							MDAQ_CH = {
							name: "CH "+(j+1),
							expandable: true,
							children: CH_Vals
						};
						for(let k=1; k<=3; k++)
						{
							let CH_val = {};
							CH_val.name = "Value_"+k;
							CH_val.Meas = logstats[i].MDAQ_Channels[j].Values["Value"+k];
							CH_val.is_Meas_valid = logstats[i].MDAQ_Channels[j].Warnings["Is_Value"+k+"_valid"];
							CH_val.Path = logstats[i].Dev_name+".CH:"+logstats[i].MDAQ_Channels[j].Channel+".Val"+k;
							CH_val.Anchor = logstats[i].Identifier+'.'+"CH"+logstats[i].MDAQ_Channels[j].Channel+".Val"+k;
							if(curr_ISOCHs && is_anchor_in_use(CH_val.Anchor))
								continue;
							CH_Vals.push(CH_val);
						}
						MDAQ_CH.expandable = CH_Vals.length ? true : false;
						if(!MDAQ_CH.expandable)
							MDAQ_CH.name += "\u2192\"All Values in use\"";
						if_handler.children.push(MDAQ_CH);
					}
				}
				morfeas_devs_tree.push(if_handler);
				break;
			case "IOBOX":
				if_handler = {};
				if_handler.name = logstats[i].Dev_name;
				if(logstats[i].Connection_status==="Okay")
				{
					if_handler.expanded = true;
					if_handler.children = [];
					for(let j=1; j<=4; j++)
					{
						if(logstats[i]["RX"+j] === "Disconnected")
							continue;
						let CHs = [],
							IOBOX_RX = {
							name: "RX_"+j,
							expandable: true,
							children: CHs
						};
						for(let k=1; k<=16; k++)
						{
							let CH = {};
							CH.name = "CH"+norm(k,2);
							CH.Meas = logstats[i]["RX"+j]["CH"+k];
							CH.is_Meas_valid = typeof(CH.Meas)==="number";
							CH.Path = logstats[i].Dev_name+".RX:"+j+".CH:"+norm(k,2);
							CH.Anchor = logstats[i].Identifier+".RX"+j+".CH"+k;
							if(curr_ISOCHs && is_anchor_in_use(CH.Anchor))
								continue;
							CHs.push(CH);
						}
						IOBOX_RX.expandable = CHs.length ? true : false;
						if(!IOBOX_RX.expandable)
							IOBOX_RX.name += "\u2192\"All CHs in use\"";
						if_handler.children.push(IOBOX_RX);
					}
				}
				morfeas_devs_tree.push(if_handler);
				break;
			case "MTI":
				if_handler = {};
				if_handler.name = logstats[i].Dev_name;
				if(logstats[i].Connection_status==="Okay")
				{
					if_handler.expanded = true;
					if_handler.children = [];
					switch(logstats[i].MTI_status.Tele_Device_type)
					{
						case "TC4":
						case "TC8":
						case "TC16":
							let CHs = [],
								MTI = {
								name: logstats[i].MTI_status.Tele_Device_type+'(RF:'+logstats[i].MTI_status.Radio_CH+')',
								expandable: true,
								children: CHs
							};
							for(let j=0; j<logstats[i].Tele_data.CHs.length; j++)
							{
								let CH = {};
								CH.name = "CH"+norm((j+1),2);
								CH.Meas = logstats[i].Tele_data.CHs[j];
								CH.is_Meas_valid = typeof(CH.Meas)==="number";
								CH.Path = logstats[i].Dev_name+'.'+logstats[i].MTI_status.Tele_Device_type+'.'+CH.name;
								CH.Anchor = logstats[i].Identifier+"."+logstats[i].MTI_status.Tele_Device_type+"."+"CH"+(j+1);
								if(curr_ISOCHs && is_anchor_in_use(CH.Anchor))
									continue;
								CHs.push(CH);
							}
							MTI.expandable = CHs.length ? true : false;
							if(!MTI.expandable)
								MTI.name += "→\"All CHs in use\"";
							if_handler.children.push(MTI);
						break;
						case "RMSW/MUX":
							for(let j=0; j<logstats[i].Tele_data.length; j++)
							{
								if(logstats[i].Tele_data[j].Dev_type==="Mini_RMSW")
								{
									let CHs = [],
									Mini_RMSW = {
										name: "Mini_RMSW(ID:"+logstats[i].Tele_data[j].Dev_ID+")",
										expandable: true,
										children: CHs
									};
									for(let k=0; k<logstats[i].Tele_data[j].CHs_meas.length; k++)
									{
										let CH = {};
										CH.name = "CH"+(k+1);
										CH.Meas = logstats[i].Tele_data[j].CHs_meas[k];
										CH.is_Meas_valid = typeof(CH.Meas)==="number";
										CH.Path = logstats[i].Dev_name+'.'+Mini_RMSW.name+'.'+CH.name;
										CH.Anchor = logstats[i].Identifier+".ID:"+logstats[i].Tele_data[j].Dev_ID+".CH"+(k+1);
										if(curr_ISOCHs && is_anchor_in_use(CH.Anchor))
											continue;
										CHs.push(CH);
									}
									Mini_RMSW.expandable = CHs.length ? true : false;
									if(!Mini_RMSW.expandable)
										Mini_RMSW.name += "\u2192\"All CHs in use\"";
									if_handler.children.push(Mini_RMSW);
								}
							}
						break;
					}
				}
				morfeas_devs_tree.push(if_handler);
				break;
			case "NOX":
				if_handler = {};
				if_handler.name = logstats[i].CANBus_interface.toUpperCase();
				let det_NOx = 0;
				if(Object.entries(logstats[i].NOx_sensors))
				{
					for(let j=0; j<logstats[i].NOx_sensors.length; j++)
						if(Object.entries(logstats[i].NOx_sensors[j]).length)
							det_NOx++;
				}
				if(det_NOx)
				{
					if_handler.expanded = true;
					if_handler.children = [];
					const sensor_names = ["NOx","O2"];
					for(let j=0; j<logstats[i].NOx_sensors.length; j++)
					{
						if(!Object.keys(logstats[i].NOx_sensors[j]).length)
							continue;
						let UniNOX_at_addr = {};
						UniNOX_at_addr.name = "Addr:"+j;
						UniNOX_at_addr.expanded = true;
						UniNOX_at_addr.children = [];
						for(let k=0; k<2; k++)
						{
							let Sensor = {};
							Sensor.name = sensor_names[k];
							Sensor.is_Meas_valid = logstats[i].NOx_sensors[j].status["is_"+sensor_names[k]+"_value_valid"];
							Sensor.Meas = Sensor.is_Meas_valid ? logstats[i].NOx_sensors[j][sensor_names[k]+"_value_avg"] :
																 logstats[i].NOx_sensors[j].status.heater_mode_state;
							Sensor.Path = logstats[i].CANBus_interface.toUpperCase()+".Addr:"+j+'.'+sensor_names[k];
							Sensor.Anchor = logstats[i].CANBus_interface+".addr_"+j+'.'+sensor_names[k];
							if(curr_ISOCHs && is_anchor_in_use(Sensor.Anchor))
								continue;
							UniNOX_at_addr.children.push(Sensor);
						}
						UniNOX_at_addr.expandable = UniNOX_at_addr.children.length ? true : false;
						if(!UniNOX_at_addr.expandable)
							UniNOX_at_addr.name += "\u2192\"All Sensors in use\"";
						if_handler.children.push(UniNOX_at_addr);
					}
				}
				morfeas_devs_tree.push(if_handler);
				break;
			default: return "dev_type unknown";
		}
	}
	return morfeas_devs_tree;
}
function import_from_file_validator(inp_obj, logger)
{
	if(!logger)
	{
		console.log("input argument logger is Undefined!!!")
		return;
	}
	if(!inp_obj)
	{
		logger.value+="Error: Data is Invalid\n";
		return;
	}
	if(!inp_obj.length)
	{
		logger.value+="Error: Data isn't array\n";
		return;
	}
	//Validate element for each inp_obj entry
	for(let i=0; i<inp_obj.length; i++)
	{
		if(!inp_obj[i].hasOwnProperty("ISO_CHANNEL") ||
		   !inp_obj[i].hasOwnProperty("INTERFACE_TYPE") ||
		   !inp_obj[i].hasOwnProperty("ANCHOR") ||
		   !inp_obj[i].hasOwnProperty("DESCRIPTION") ||
		   !inp_obj[i].hasOwnProperty("MIN") ||
		   !inp_obj[i].hasOwnProperty("MAX"))
		{
			logger.value+="Error: Element "+i+" have missing elements!!!\n";
			return;
		}
		if(typeof(inp_obj[i].ISO_CHANNEL)!=="string" ||
		   typeof(inp_obj[i].INTERFACE_TYPE)!=="string" ||
		   typeof(inp_obj[i].ANCHOR)!=="string" ||
		   typeof(inp_obj[i].DESCRIPTION)!=="string" ||
		   isNaN(inp_obj[i].MIN)||isNaN(inp_obj[i].MAX) ||
		   (inp_obj[i].hasOwnProperty('UNIT') && typeof(inp_obj[i].UNIT)!=="string") ||
		   (inp_obj[i].hasOwnProperty('CAL_DATE') && typeof(inp_obj[i].CAL_DATE)!=="string") ||
		   (inp_obj[i].hasOwnProperty('CAL_PERIOD') && isNaN(inp_obj[i].CAL_PERIOD)))
		{
			logger.value+="Error: Element "+i+" have invalid contents!!!\n";
			return;
		}

		if(inp_obj[i].hasOwnProperty('UNIT') && !inp_obj[i].UNIT)
		{
			logger.value+="Error: \"UNIT\" property of Element "+i+" is empty!!!\n";
			return;
		}
		if(inp_obj[i].hasOwnProperty('CAL_DATE'))
		{
			if(!inp_obj[i].CAL_DATE)
			{
				logger.value+="Error: \"CAL_DATE\" property of Element "+i+" is empty!!!\n";
				return;
			}
			if(!inp_obj[i].CAL_DATE.match(/^[\d]{4}\/[[\d]{2}\/[[\d]{2}$/g))
			{
				logger.value+="Error: \"CAL_DATE\" property of Element "+i+" is invalid. (YYYY/MM/DD)\n";
				return;
			}
			let date_p = inp_obj[i].CAL_DATE.split('/'),
				year = Number(date_p[0]),
				month = Number(date_p[1]),
				day = Number(date_p[2]);
			if((year<2000||year>2255)
			 ||(month<1||month>12)
			 ||(day<1||day>31))
			{
				logger.value+="Error: \"CAL_DATE\" property of Element "+i+" is not a valid date\n";
				return;
			}
		}
		if(inp_obj[i].hasOwnProperty('CAL_PERIOD'))
		{
			if(inp_obj[i].CAL_PERIOD<0 || inp_obj[i].CAL_PERIOD>255)
			{
				logger.value+="Error: \"CAL_PERIOD\" property of Element "+i+" is out of range!!!\n";
				return;
			}
		}
	}
	return true;
}
var iso_standard = {
	iso_standard_xml : new Object(),
	request_isostandard : function()
	{
		var xhttp = new XMLHttpRequest();
		xhttp.timeout = 5000;
		xhttp.onreadystatechange = (function(iso_standard_xml){
			return function()
			{
				if(this.readyState == 4)
				{
					if(this.status == 200)
					{
						if(this.getResponseHeader("Content-Type")==="ISOstandard/xml")
						{
							if(!this.responseXML)
								iso_standard_xml.xml_data = (new DOMParser()).parseFromString(this.responseText, "application/xml").getElementsByTagName("points")[0];
							else
								iso_standard_xml.xml_data = this.responseXML.getElementsByTagName("points")[0];
						}
						else
							alert(this.responseText);
					}
					else if(this.status == 500)
						alert("FATAL Error on server!!!");
				}
			};
		})(this.iso_standard_xml);
		xhttp.ontimeout = function(){
			alert("Client: Communication Error!!!");
		};
		xhttp.open("GET", "/morfeas_php/config.php"+"?COMMAND=getISOstandard", true);
		xhttp.send();
	},
	get_isostandard_by_unit : function(unit)
	{
		if(this.iso_standard_xml.xml_data)
		{
			let ret = [], xml=this.iso_standard_xml.xml_data;
			for(let i=0; i<xml.children.length; i++)
			{
				if(!unit || unit === xml.children[i].children[1].textContent)
				{
					let elem = new Object({iso_code:"",attributes:{description:"",unit:"",max:"",min:""}});
					elem.iso_code_and_desc=xml.children[i].nodeName+' | '+xml.children[i].children[0].textContent;
					elem.attributes.iso_code=xml.children[i].nodeName;
					elem.attributes.description=xml.children[i].children[0].textContent;
					elem.attributes.unit=xml.children[i].children[1].textContent;
					elem.attributes.max=xml.children[i].children[2].textContent;
					elem.attributes.min=xml.children[i].children[3].textContent;
					ret.push(elem);
				}
			}
			return ret;
		}
		return;
	}
};
function Seconds_to_human(n)
{
	let days,hours,minutes,seconds,ret="";
	days = parseInt(n/(24*3600));
	n = n%(24*3600);
	hours = parseInt(n/3600);
	n %= 3600;
	minutes = n/60;
	n %= 60;
	seconds = n;
	if(days)
	{
		days = days.toFixed();
		ret += days+" day"+(days>1?"s ":' ');
	}
	ret += hours.toFixed()+":"+minutes.toFixed()+":"+seconds.toFixed();
	return ret;
}
function norm(num, targetLength)
{
	return num.toString().padStart(targetLength, 0);
}
//@license-end
