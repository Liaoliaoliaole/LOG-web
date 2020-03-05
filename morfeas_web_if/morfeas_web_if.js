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

function morfeas_logstat_commonizer(logstats)
{
	var ret_val;
	if(logstats === undefined)
		return null;
	logstats = JSON.parse(logstats);
	if(logstats.logstat_name === undefined || logstats.logstat_contents === undefined)
		return null;
	
	return ret_val=logstats;
}
