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
const Buffer_size=3600/0.05; //Roughly 1hr
var csv; //export csv file
var pre_stamp=-1,x,y1,y2,graph;
var data = []; //rolling data buffer
var wsUri;
var websocket;
var pause_or_play=1; //1 for play, 0 for pause
var graph_options={
	drawPoints: false,
	showRoller: false,
	digitsAfterDecimal : 3,
	labels: ['Time', 'NOX(ppm)','O2(%)'],
	series : {
	  'O2(%)': {
		axis: 'y2'
	  }
	},
	title :"UniNOx:0",
	ylabel: "NOX(ppm)",
	y2label: "O2 (%)",
	legend: "never",
	zoomCallback: function(minX, maxX, yRanges) {
		if(!data.length)
			return;
		var DWL_buttons = document.getElementsByName("DWL_buttons");
		if(graph.isZoomed("x"))
		{
			stats_calc(data,minX,maxX);
			DWL_buttons.forEach(function (item){item.style.display=""});
			if(document.getElementById("Zoom_Stats_check").checked)
			{
				document.getElementById("Current_data").style.display="none";
				document.getElementById("Stats").style.display="";
			}
			pause_or_play=1; // simulate
		}
		else
		{
			DWL_buttons.forEach(function (item){item.style.display="none"});
			document.getElementById("Stats").style.display="none";
			document.getElementById("Current_data").style.display="";
			document.getElementById("play_pause_button").innerHTML="Pause";
			graph.updateOptions( { "legend": "never" } );
			pause_or_play=0;
		}
		play_pause();
	  },
};
function init_graph()
{
	data=[];
	x = new Date();  // current time
	y1=NaN;
	y2=NaN;
	data.push([x, y1, y2]);
	if(graph != null)
		graph.destroy();
	graph = new Dygraph(document.getElementById("div_g"), data, graph_options);
}
/*
function init_websocket()
{
	if(!wsUri)
		return;
	websocket = new WebSocket(wsUri);
	websocket.onopen = function(evt) { onOpen(evt) };
	websocket.onclose = function(evt) { onClose(evt) };
	websocket.onmessage = function(evt) { onMessage(evt) };
	websocket.onerror = function(evt) { onError(evt) };
}
function onOpen(evt)
{
	//writeToScreen("CONNECTED");
	document.getElementById("status_tab").value = "Opening Session!!!";
	init_graph();
	timer = setTimeout(function(){timer=setInterval(function(){doSend(Sensor_req);},100)}, 1000);
}
function onClose(evt)
{
	clearInterval(timer);
	document.getElementById("status_tab").value = evt.reason;
}
function onMessage(evt)
{
	if(evt.data.search("Data:")>=0)
	{
		var msg = evt.data.split(" ");
		if(msg.length===13)
		{
			if(pre_stamp!=msg[1])
			{
				fill_data(msg);
				pre_stamp=msg[1];
				if(!(isNaN(msg[3])&&isNaN(msg[4])))
				{
					if(data.length>=Buffer_size) // rolling buffer
						data.shift();
					x = new Date();  // current time
					y1 = parseFloat(msg[3]);
					y2 = parseFloat(msg[4]);
					data.push([x, y1, y2]);
					if(pause_or_play)
						graph.updateOptions( { 'file': data } );
				}
				document.getElementById("status_tab").value = "";
			}
			else
				document.getElementById("status_tab").value = 'ERROR: Sensor not responding';
		}
	}
	else if(evt.data.search("Info:")>=0)
	{
		document.getElementById("status_tab").value = evt.data;
	}
}
function onError(evt)
{
	document.getElementById("status_tab").value = 'WS_error:' + evt.reason;
}
function doSend(message)
{
	if(websocket.readyState===WebSocket.OPEN)
		switch(Heater_code)
		{
			case Heater_code_enum.ON: websocket.send("Heater=ON"); break;
			case Heater_code_enum.OFF: websocket.send("Heater=OFF");break;
			default: websocket.send(message);
		}
		Heater_code = Heater_code_enum.Notset;
}
*/
function play_pause()
{
	switch(pause_or_play)
	{
		case 0: //case for play pressed
				document.getElementById("play_pause_button").innerHTML="Pause";
				graph.resetZoom();
				pause_or_play=1;
				graph.updateOptions( { "legend": "never" } );
				break;
		case 1: //case for pause pressed
				document.getElementById("play_pause_button").innerHTML="Play";
				pause_or_play=0;
				graph.updateOptions( { "legend": "follow" } );
				break;
	}
}
function stats_calc(data_ist,minX,maxX)
{
	if(!data_ist.lenght)
		return;
	var NOx_stat=document.getElementsByName("NOx_stat");
	var O2_stat=document.getElementsByName("O2_stat");
	var imin,NOx_min,NOx_max,NOx_acc=0,O2_min,O2_max,O2_acc=0;
	//console.log(minX + ", " + maxX);
	for(var i=0;(data_ist[i][0].getTime())<=minX;i++);
	imin=i;
	NOx_min=data_ist[i][1];
	NOx_max=NOx_min;
	O2_min=data_ist[i][2];
	O2_max=O2_min;
	csv = 'Timestamp,NOx(ppm),O2(%)\n'; //init csv
	for(i++;(data_ist[i][0].getTime())<=maxX;i++)
	{
		NOx_acc+=data_ist[i][1];
		O2_acc+=data_ist[i][2];
		if(data_ist[i][1]>NOx_max)
			NOx_max=data_ist[i][1];
		if(data_ist[i][1]<NOx_min)
			NOx_min=data_ist[i][1];
		if(data_ist[i][2]>O2_max)
			O2_max=data_ist[i][2];
		if(data_ist[i][2]<O2_min)
			O2_min=data_ist[i][2];
		csv += data_ist[i].join(',') + "\n"; //load zoom data to csv export obj
	}
	NOx_stat[0].value=(Math.round((NOx_acc/(i-imin))*1000)/1000) + " (ppm)";
	NOx_stat[1].value=NOx_max + " (ppm)";
	NOx_stat[2].value=NOx_min + " (ppm)";
	NOx_stat[3].value=(Math.round((NOx_max-NOx_min)*1000)/1000) + " (ppm)";

	O2_stat[0].value=(Math.round((O2_acc/(i-imin))*1000)/1000) + " (%)";
	O2_stat[1].value=O2_max + " (%)";
	O2_stat[2].value=O2_min + " (%)";
	O2_stat[3].value=(Math.round((O2_max-O2_min)*1000))/1000 + " (%)";
}
function download_csv()
{
	var hiddenElement = document.createElement('a');
	hiddenElement.href = 'data:text/csv;charset=utf-8,' + encodeURI(csv);
	hiddenElement.target = '_blank';
	hiddenElement.download = "Export.csv";
	hiddenElement.click();
}

function download_PDF()
{
	let docDefinition, pic,
		NOX_CAN_if = document.getElementById("NOX_CAN_if"),
		sel_addr = document.getElementById("sel_addr"),
		div_pdf = document.getElementById('div_pdf');
	var filename = "NOx_"+NOX_CAN_if+'_'+sel_addr+"_graph";
	if(!document.getElementById("Zoom_Stats_check").checked)
	{
		document.getElementById("Current_data").style.display="none";
		document.getElementById("Stats").style.display="";
	}
	if (filename != null && filename.indexOf('.') == -1)
		html2canvas(div_pdf).then(function (canvas)
		{
			pic = canvas.toDataURL();
			docDefinition = {
				pageSize: 'A4',
				pageOrientation: 'landscape',
				pageMargins: [ 40, 60, 40, 60 ],
				content: [{
					image: pic,
					width: 600,
					alignment: 'center'
				}]
			};
			pdfMake.createPdf(docDefinition).download(filename+'.pdf');
		});
	if(!document.getElementById("Zoom_Stats_check").checked)
		setTimeout(function()
		{
			document.getElementById("Current_data").style.display="";
			document.getElementById("Stats").style.display="none";
		}, 50);
}
//@license-end