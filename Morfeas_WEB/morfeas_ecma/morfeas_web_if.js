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
	if(logstats === undefined)
		return "no logstats type data";
	if((logstats = JSON.parse(logstats)) === undefined)
		return "Parsing error";
	if(logstats.logstats_names === undefined)
		return "missing logstats_names";
	if(logstats.logstat_contents === undefined)
		return "missing logstat_contents";

	function norm(num, targetLength)
	{
		return num.toString().padStart(targetLength, 0);
	}

	function sensor(type,
				deviceUserIdentifier,
				sensorUserId,
				anchor,
				unit,
				calibrationDate,
				calibrationPeriod,
				avgMeasurement,
				Is_meas_valid)
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


	for(let i=0; i<logstats.logstats_names.length; i++)
	{
		if(logstats.logstats_names[i].includes("logstat"))
		{
			data_table_index = data_table.length;
			if(logstats.logstats_names[i] === "logstat_sys.json")//RPi_Health_Stats
			{
				data_table[data_table_index] = new table_data_entry();
				//Load if_name and build_date
				data_table[data_table_index].if_name = "RPi_Health_Status";
				data_table[data_table_index].logstat_build_date_UNIX = logstats.logstat_contents[i].logstat_build_date_UNIX;
				//Load system's status
				data_table[data_table_index].sensors = null;
				if(logstats.logstat_contents[i].CPU_temp)
					data_table[data_table_index].connections.push(new connection("CPU_temp", logstats.logstat_contents[i].CPU_temp.toFixed(2), "°C"));
				data_table[data_table_index].connections.push(new connection("CPU_Util", logstats.logstat_contents[i].CPU_Util.toFixed(2), "%"));
				data_table[data_table_index].connections.push(new connection("RAM_Util", logstats.logstat_contents[i].RAM_Util.toFixed(2), "%"));
				data_table[data_table_index].connections.push(new connection("Disk_Util", logstats.logstat_contents[i].Disk_Util.toFixed(2), "%"));
				data_table[data_table_index].connections.push(new connection("Up_time", logstats.logstat_contents[i].Up_time, "sec"));
			}
			else if(logstats.logstats_names[i].includes("logstat_MDAQ"))//Morfeas_MDAQ_if handlers
			{
				data_table[data_table_index] = new table_data_entry();
				//Load IF_name and build_date
				data_table[data_table_index].if_name = "MDAQ";
				data_table[data_table_index].logstat_build_date_UNIX = logstats.logstat_contents[i].logstat_build_date_UNIX;
				//Load Device's status
				data_table[data_table_index].connections.push(new connection("Dev_name", logstats.logstat_contents[i].Dev_name));
				data_table[data_table_index].connections.push(new connection("IPv4_address", logstats.logstat_contents[i].IPv4_address));
				data_table[data_table_index].connections.push(new connection("Identifier", logstats.logstat_contents[i].Identifier));
				data_table[data_table_index].connections.push(new connection("Connection_status", logstats.logstat_contents[i].Connection_status));
				//Load Device's sensors
				if(logstats.logstat_contents[i].MDAQ_Channels !== undefined)
				{
					data_table[data_table_index].connections.push(new connection("Board_temp", logstats.logstat_contents[i].Board_temp, "°C"));
					for(let j=0; j<logstats.logstat_contents[i].MDAQ_Channels.length; j++)
					{
						for(let k=1; k<=3; k++)//limit to 3]
						{
							if(eval("logstats.logstat_contents[i].MDAQ_Channels[j].Warnings.Is_Value"+k+"_valid"))
							{
								data_table[data_table_index].sensors.push(new sensor
								(
									"MDAQ",
									logstats.logstat_contents[i].Dev_name + " (" + logstats.logstat_contents[i].IPv4_address + ")",
									"CH"+norm(logstats.logstat_contents[i].MDAQ_Channels[j].Channel,2)+".Val"+k,
									logstats.logstat_contents[i].Identifier+'.'+"CH"+logstats.logstat_contents[i].MDAQ_Channels[j].Channel+".Val"+k,
									null,null,null,
									eval("logstats.logstat_contents[i].MDAQ_Channels[j].Values.Value"+k),
									eval("logstats.logstat_contents[i].MDAQ_Channels[j].Warnings.Is_Value"+k+"_valid")
								));
							}
						}
					}
				}
				else
					data_table[data_table_index].sensors = [];
			}
			else if(logstats.logstats_names[i].includes("logstat_MTI"))//Morfeas_MTI_if handlers
			{
				data_table[data_table_index] = new table_data_entry();
				//Load IF_name and build_date
				data_table[data_table_index].if_name = "MTI";
				data_table[data_table_index].logstat_build_date_UNIX = logstats.logstat_contents[i].logstat_build_date_UNIX;
				//Load Device's status
				data_table[data_table_index].connections.push(new connection("Dev_name", logstats.logstat_contents[i].Dev_name));
				data_table[data_table_index].connections.push(new connection("IPv4_address", logstats.logstat_contents[i].IPv4_address));
				data_table[data_table_index].connections.push(new connection("Identifier", logstats.logstat_contents[i].Identifier));
				data_table[data_table_index].connections.push(new connection("Connection_status", logstats.logstat_contents[i].Connection_status));
				//Load Device's sensors
				if(logstats.logstat_contents[i].Connection_status === "Okay")
				{
					data_table[data_table_index].connections.push(new connection("CPU_temp", logstats.logstat_contents[i].MTI_status.MTI_CPU_temp, "°C"));
					data_table[data_table_index].connections.push(new connection("Battery state", logstats.logstat_contents[i].MTI_status.MTI_charge_status));
					if(logstats.logstat_contents[i].MTI_status.MTI_charge_status !== "Charging" && logstats.logstat_contents[i].MTI_status.MTI_charge_status !== "Full")
						data_table[data_table_index].connections.push(new connection("Battery capacity", logstats.logstat_contents[i].MTI_status.MTI_batt_capacity));
					data_table[data_table_index].connections.push(new connection("Radio_mode", logstats.logstat_contents[i].MTI_status.Tele_Device_type));
					data_table[data_table_index].connections.push(new connection("RF Channel", logstats.logstat_contents[i].MTI_status.Radio_CH));
					if(logstats.logstat_contents[i].MTI_status.Tele_Device_type !== "RMSW/MUX")
					{
						switch(logstats.logstat_contents[i].MTI_status.Tele_Device_type)
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
							default: lim=0; data_table[data_table_index].sensors = null;
						}
						for(let j=0; j<lim; j++)
						{
							if(logstats.logstat_contents[i].Tele_data.CHs[j] !=="No sensor")
								data_table[data_table_index].sensors.push(new sensor
								(
									"MTI",
									logstats.logstat_contents[i].Dev_name + " (" + logstats.logstat_contents[i].IPv4_address + ")",
									logstats.logstat_contents[i].MTI_status.Tele_Device_type+".CH"+(j+1),
									logstats.logstat_contents[i].Identifier+"."+logstats.logstat_contents[i].MTI_status.Tele_Device_type+"."+"CH"+(j+1),
									lim!==2?"°C":"",null,null,
									logstats.logstat_contents[i].Tele_data.CHs[j],
									logstats.logstat_contents[i].Tele_data.IsValid
								));
						}
					}
					else if(logstats.logstat_contents[i].MTI_status.Tele_Device_type === "RMSW/MUX")
					{
						for(let j=0; j<logstats.logstat_contents[i].Tele_data.length; j++)
						{
							if(logstats.logstat_contents[i].Tele_data[j].Dev_type === "Mini_RMSW")
							{
								for(let k=0; k<4; k++)
								{
									if(logstats.logstat_contents[i].Tele_data[j].CHs_meas[k] !== "No sensor")
									{
										data_table[data_table_index].sensors.push(new sensor
										(
											"MTI",
											logstats.logstat_contents[i].Dev_name + " (" + logstats.logstat_contents[i].IPv4_address + ")",
											logstats.logstat_contents[i].Tele_data[j].Dev_type+"(ID:"+logstats.logstat_contents[i].Tele_data[j].Dev_ID+").CH"+(k+1),
											logstats.logstat_contents[i].Identifier+".ID:"+logstats.logstat_contents[i].Tele_data[j].Dev_ID+"."+"CH"+(k+1),
											"°C",null,null,
											logstats.logstat_contents[i].Tele_data[j].CHs_meas[k],
											true
										));
									}
								}
							}
						}
					}
				}
				else
					data_table[data_table_index].sensors = [];
			}
			else if(logstats.logstats_names[i].includes("logstat_IOBOX"))//Morfeas_IOBOX_if handlers
			{
				data_table[data_table_index] = new table_data_entry();
				//Load IF_name and build_date
				data_table[data_table_index].if_name = "IOBOX";
				data_table[data_table_index].logstat_build_date_UNIX = logstats.logstat_contents[i].logstat_build_date_UNIX;
				//Load Device's status
				data_table[data_table_index].connections.push(new connection("Dev_name", logstats.logstat_contents[i].Dev_name));
				data_table[data_table_index].connections.push(new connection("IPv4_address", logstats.logstat_contents[i].IPv4_address));
				data_table[data_table_index].connections.push(new connection("Identifier", logstats.logstat_contents[i].Identifier));
				data_table[data_table_index].connections.push(new connection("Connection_status", logstats.logstat_contents[i].Connection_status));
				//Load Device's sensors
				if(logstats.logstat_contents[i].Connection_status === "Okay")
				{
					for(let j=1; j<=4; j++)//limit to 4], amount of receivers on a IOBOX = 4.
					{
						if(eval("logstats.logstat_contents[i].RX"+j) !== "Disconnected")
						{
							for(let k=1; k<=16; k++)//limit to 16], max amount of channels on a telemetry.
							{
								if(eval("logstats.logstat_contents[i].RX"+j+".CH"+k) !== "No sensor")
								{
									data_table[data_table_index].sensors.push(new sensor
									(
										"IOBOX",
										logstats.logstat_contents[i].Dev_name + " (" + logstats.logstat_contents[i].IPv4_address + ")",
										"RX"+j+".CH"+norm(k,2),
										logstats.logstat_contents[i].Identifier+".RX"+j+".CH"+k,
										"°C",null,null,
										eval("logstats.logstat_contents[i].RX"+j+".CH"+k),
										true
									));
								}
							}
						}
					}
				}
				else
					data_table[data_table_index].sensors = [];
			}
			else if(logstats.logstats_names[i].includes("logstat_SDAQs"))//Morfeas_SDAQ_if handlers
			{
				data_table[data_table_index] = new table_data_entry();
				//Load IF_name and build_date
				data_table[data_table_index].if_name = "SDAQs ("+logstats.logstat_contents[i].CANBus_interface+")";
				data_table[data_table_index].logstat_build_date_UNIX = logstats.logstat_contents[i].logstat_build_date_UNIX;
				//Load Device's status
				data_table[data_table_index].connections.push(new connection("BUS_Utilization", logstats.logstat_contents[i].BUS_Utilization, "%"));
				data_table[data_table_index].connections.push(new connection("Detected_SDAQs", logstats.logstat_contents[i].Detected_SDAQs));
				if(logstats.logstat_contents[i].Electrics)
				{
					data_table[data_table_index].connections.push(new connection("SDAQnet_("+logstats.logstat_contents[i].CANBus_interface+")_last_calibration_UNIX",
																				  logstats.logstat_contents[i].Electrics.Last_calibration_UNIX));
					data_table[data_table_index].connections.push(new connection("SDAQnet_("+logstats.logstat_contents[i].CANBus_interface+")_outVoltage",
																				  logstats.logstat_contents[i].Electrics.BUS_voltage.toFixed(2), "V"));
					data_table[data_table_index].connections.push(new connection("SDAQnet_("+logstats.logstat_contents[i].CANBus_interface+")_outAmperage",
																				  logstats.logstat_contents[i].Electrics.BUS_amperage.toFixed(2), "A"));
					data_table[data_table_index].connections.push(new connection("SDAQnet_("+logstats.logstat_contents[i].CANBus_interface+")_ShuntTemp",
																				  logstats.logstat_contents[i].Electrics.BUS_Shunt_Res_temp.toFixed(2), "°C"));
				}
				//Load Device's sensors
				if(logstats.logstat_contents[i].Detected_SDAQs)
				{
					for(let j=0; j<logstats.logstat_contents[i].SDAQs_data.length; j++)
					{
						for(let k=0; k<logstats.logstat_contents[i].SDAQs_data[j].Meas.length; k++)
						{
							if(!(logstats.logstat_contents[i].SDAQs_data[j].Meas[k].Channel_Status.Channel_status_val))
							{
								data_table[data_table_index].sensors.push(new sensor
								(
									"SDAQ",
									logstats.logstat_contents[i].SDAQs_data[j].SDAQ_type,
									"ADDR:"+norm(logstats.logstat_contents[i].SDAQs_data[j].Address,2)+".CH"+norm(logstats.logstat_contents[i].SDAQs_data[j].Meas[k].Channel,2),
									logstats.logstat_contents[i].SDAQs_data[j].Serial_number+".CH"+logstats.logstat_contents[i].SDAQs_data[j].Meas[k].Channel,
									logstats.logstat_contents[i].SDAQs_data[j].Meas[k].Unit,
									logstats.logstat_contents[i].SDAQs_data[j].Calibration_Data[k].Calibration_date_UNIX,
									logstats.logstat_contents[i].SDAQs_data[j].Calibration_Data[k].Calibration_period,
									logstats.logstat_contents[i].SDAQs_data[j].Meas[k].Meas_avg,
									!(logstats.logstat_contents[i].SDAQs_data[j].Meas[k].Channel_Status.Channel_status_val)
								));
							}
						}
					}
				}
				else
					data_table[data_table_index].sensors = [];
			}
		}
	}
	return data_table;
}
