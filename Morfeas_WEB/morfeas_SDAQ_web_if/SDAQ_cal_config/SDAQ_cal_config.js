//@license magnet:?xt=urn:btih:0b31508aeb0634b347b8270c7bee4d411b5d4109&dn=agpl-3.0.txt AGPL-v3.0
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
"use strict";
function getUrlParam(parameter)
{
    function getUrlVars()
	{
		var vars = {};
		var parts = window.location.href.replace(/[?&]+([^=&]+)=([^&]*)/gi, function(m,key,value) {
			vars[key] = value;
		});
		return vars;
	}
    if(window.location.href.indexOf(parameter) > -1)
        return getUrlVars()[parameter];
}

function SDAQ_cal_XML2obj(SDAQ_cal_xml)
{
	if(!SDAQ_cal_xml)
		return;
	var	data = {};
	//Append, value to data.name
	function Add_to_data(name, value)
	{
		if(!data[name])//Check for duplicate
		{
			let val;
			if(name==="Calibration_date")
			{
				if(value==="2000/00/00")//Compare with uncalibrated date value
					val = new Date();
				else
					val = new Date(Date.parse(value));
			}
			else
				val = isNaN(value)?value:parseFloat(value);//Check if value is number and if convert it to float.
			data[name] = val;
		}
	};
	//Scan child elements and add them to data
	for(let i=0, cur_node; cur_node = SDAQ_cal_xml.childNodes[i]; i++)
	{
		if(cur_node.nodeType === SDAQ_cal_xml.ELEMENT_NODE)
		{
			if(cur_node.childNodes.length === SDAQ_cal_xml.ELEMENT_NODE && cur_node.firstChild.nodeType === SDAQ_cal_xml.TEXT_NODE)
				Add_to_data(cur_node.nodeName, cur_node.firstChild.nodeValue);
			else
				Add_to_data(cur_node.nodeName, SDAQ_cal_XML2obj(cur_node));//Recursive call for sub-object
		}
	}
	return data;
}

function new_SDAQ_cal_for_post(SDAQnet_name, SDAQ_addr, SDAQ_cal_data, units_std_array)
{
	if(!SDAQnet_name || !SDAQ_addr || !SDAQ_cal_data || !units_std_array)
		return;
	var ret = {}, SDAQ_xmlDoc = new DOMParser().parseFromString('<?xml version="1.0" encoding="utf-8"?><SDAQ/>', "application/xml");
	let SDAQ_info = SDAQ_xmlDoc.childNodes[SDAQ_xmlDoc.childNodes.length-1].appendChild(SDAQ_xmlDoc.createElement("SDAQ_info"));
	let Calibration_Data = SDAQ_xmlDoc.childNodes[SDAQ_xmlDoc.childNodes.length-1].appendChild(SDAQ_xmlDoc.createElement("Calibration_Data"));

	//Add Calibration_Data to SDAQ_xmlDoc
	for(let SDAQ_CHn in SDAQ_cal_data.SDAQ.Calibration_Data)
	{
		let new_CH_node = SDAQ_xmlDoc.createElement(SDAQ_CHn);
		if(SDAQ_cal_data.SDAQ.Calibration_Data[SDAQ_CHn].Calibration_date instanceof Date &&
		   units_std_array.indexOf(SDAQ_cal_data.SDAQ.Calibration_Data[SDAQ_CHn].Unit)>=0)
		{
			let Cal_period = SDAQ_xmlDoc.createElement("Calibration_Period"),
				Used_Points = SDAQ_xmlDoc.createElement("Used_Points"),
				Unit = SDAQ_xmlDoc.createElement("Unit"),
				Cal_date = SDAQ_xmlDoc.createElement("Calibration_date"),
				Points = SDAQ_xmlDoc.createElement("Points");

			Cal_period.appendChild(SDAQ_xmlDoc.createTextNode(SDAQ_cal_data.SDAQ.Calibration_Data[SDAQ_CHn].Calibration_Period));
			Used_Points.appendChild(SDAQ_xmlDoc.createTextNode(SDAQ_cal_data.SDAQ.Calibration_Data[SDAQ_CHn].Used_Points));
			Unit.appendChild(SDAQ_xmlDoc.createTextNode(SDAQ_cal_data.SDAQ.Calibration_Data[SDAQ_CHn].Unit));
			Cal_date.appendChild(SDAQ_xmlDoc.createTextNode( SDAQ_cal_data.SDAQ.Calibration_Data[SDAQ_CHn].Calibration_date.getFullYear() + '/' +
														    (SDAQ_cal_data.SDAQ.Calibration_Data[SDAQ_CHn].Calibration_date.getMonth()+1) + '/' +
															 SDAQ_cal_data.SDAQ.Calibration_Data[SDAQ_CHn].Calibration_date.getDate()));
			//Check USer points and add that amount
			if(SDAQ_cal_data.SDAQ.Calibration_Data[SDAQ_CHn].Used_Points)
			{
				let c=0;
				for(let Point_n in SDAQ_cal_data.SDAQ.Calibration_Data[SDAQ_CHn].Points)
				{
					if(c++<SDAQ_cal_data.SDAQ.Calibration_Data[SDAQ_CHn].Used_Points)
					{
						let Point_n_node = SDAQ_xmlDoc.createElement(Point_n);
						for(let Point_n_data in SDAQ_cal_data.SDAQ.Calibration_Data[SDAQ_CHn].Points[Point_n])
						{
							let new_node = SDAQ_xmlDoc.createElement(Point_n_data);
							new_node.appendChild(SDAQ_xmlDoc.createTextNode(SDAQ_cal_data.SDAQ.Calibration_Data[SDAQ_CHn].Points[Point_n][Point_n_data]))
							Point_n_node.appendChild(new_node);
						}
						Points.appendChild(Point_n_node);
					}
					else
						break;
				}
			}
			new_CH_node.appendChild(Cal_period);
			new_CH_node.appendChild(Used_Points);
			new_CH_node.appendChild(Unit);
			new_CH_node.appendChild(Cal_date);
			new_CH_node.appendChild(Points);
		}
		else
			continue;
		Calibration_Data.appendChild(new_CH_node);
	}
	if(!Calibration_Data.childElementCount)
		return;
	//Add SDAQ_info to SDAQ_xmlDoc
	for(let SDAQ_info_prop in SDAQ_cal_data.SDAQ.SDAQ_info)
	{
		let new_node = SDAQ_xmlDoc.createElement(SDAQ_info_prop);
		new_node.appendChild(SDAQ_xmlDoc.createTextNode(SDAQ_cal_data.SDAQ.SDAQ_info[SDAQ_info_prop]))
		SDAQ_info.appendChild(new_node);
	}
	ret["SDAQnet"] = SDAQnet_name;
	ret["SDAQaddr"] = SDAQ_addr;
	ret["XMLcontent"] = new XMLSerializer().serializeToString(SDAQ_xmlDoc);
	//return ret
	return JSON.stringify(ret);
}
//@license-end