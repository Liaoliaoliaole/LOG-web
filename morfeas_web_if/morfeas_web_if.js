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
FOR A PARTICULAR PURPOSE.  See the GNU GPL for more details.

As additional permission under GNU GPL version 3 section 7, you
may distribute non-source (e.g., minimized or compacted) forms of
that code without the copy of the GNU GPL normally required by 
section 4, provided you include this license notice and a URL
through which recipients can access the Corresponding Source.   


@licend  The above is the entire license notice
for the JavaScript code in this page.
*/

/*
Morfeas_logstats = 
{
    sensors: 
	[ 
		sensor: 
		{
			address,
			channel,
			anchor,
			avgMeasurement,
			identifier,
			type,
			unit,
			calibrationDate,
			calibrationDateUnix,
			calibrationPeriod
		}
    ]
};

    "connections":
	[
        {
            "name": "can0",
            "logstat_build_timestamp_UTC": "",
            "logstat_build_timestamp_UNIX": 0,
            "details": [ // add as many that are of interest
                {
                    "name": "Voltage",
                    "value": "0",
                    "unit": "V"
                },
                {
                    "name": "Amperage",
                    "value": "0",
                    "unit": "A"
                },
                {
                    "name": "Detected SDAQs",
                    "value": "1",
                    "unit": null
                }
            ]
        },
        {
            "name": "IOBOX",
            "logstat_build_timestamp_UTC": "",
            "logstat_build_timestamp_UNIX": 0,
            "details": [
                {
                    "name": "DevName",
                    "value": "LAD_IOBOX",
                    "unit": null
                },
                {
                    "name": "IP Address",
                    "value": "10.0.0.7",
                    "unit": null
                }
            ]
        }
    ]
}
*/

function connection() 
{
  this.name = new Object();
  this.lastName = new Object();
  this.age = new Object();
  this.eyeColor = new Object();
}


function morfeas_logstat_commonizer(logstats)
{
	var comp = new Object();
	//Check for incompatible input
	if(logstats === undefined)
		return "no logstats type data";
	logstats = JSON.parse(logstats);
	if(logstats.logstats_names === undefined)
		return "missing logstats_names";
	if(logstats.logstat_contents === undefined)
		return "missing logstat_contents";
	
	comp.points = new Array();
	comp.connections = new Object();
	for(let i=0; i<logstats.logstats_names.length; i++)
	{
		if(logstats.logstats_names[i].includes("logstat"))
		{
			if(logstats.logstats_names[i] === "logstat_sys.json")//RPI_health_stats
			{
				comp.connections = new Object(logstats.logstat_contents[i]);
			}
			/*
			else if(logstats.logstats_names[i].includes("logstat_MDAQ"))//Morfeas_MDAQ_if handlers
			else if(logstats.logstats_names[i].includes("logstat_IOBOX"))//Morfeas_IOBOX_if handlers
			else if(logstats.logstats_names[i].includes("can"))//Morfeas_SDAQ_if handlers
			*/
			
		}
	}
	return comp;
}
