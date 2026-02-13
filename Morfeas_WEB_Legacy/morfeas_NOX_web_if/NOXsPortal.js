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
const Buffer_size=3600/0.1; //Roughly 1hr
var x,y1,y2,graph,
	ws_timer,
	data_buff = [], //rolling data buffer
	NOx_if_ws,
	pause_or_play=1; //1 for play, 0 for pause

var	graph_options = {
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
	axisLabelWidth: 60,
	zoomCallback: function(minX, maxX, yRanges) {

		if(!data_buff.length || (minX >= maxX))
			return;
		var DWL_buttons = document.getElementsByName("DWL_buttons");
		if(graph.isZoomed("x"))
		{
			stats_calc(data_buff, minX, maxX);
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
	data_buff=[];
	x = new Date();  // current time
	y1=NaN;
	y2=NaN;
	data_buff.push([x, y1, y2]);
	if(graph != null)
		graph.destroy();
	graph = new Dygraph(document.getElementById("div_g"), data_buff, graph_options);
}
function init_websocket(wsUri)
{
	if(!wsUri)
		return;
	NOx_if_ws = new WebSocket(wsUri,"Morfeas_NOX_WS_if");
	NOx_if_ws.binaryType = 'arraybuffer';
	NOx_if_ws.onopen = onOpen;
	NOx_if_ws.onclose = function(evt){clearInterval(ws_timer);ws_info(evt);};
	NOx_if_ws.onmessage = onMessage;
	NOx_if_ws.onerror = ws_info;
}
function onOpen(evt)
{
	init_graph();
	NOx_if_ws.send("getMeasRAW");
	ws_timer = setInterval(function(){doSend();},100);
}
function onMessage(evt)
{
	var sel_addr = document.getElementById("sel_addr").value;
	var msg_data, timestamp, NOx_val=[], O2_val=[];
	if(!(evt.data instanceof ArrayBuffer))
		 return;
	msg_data = new DataView(evt.data);
	timestamp = new Date(Number(msg_data.getBigUint64(0, true)));
	for(let i=0; i<2; i++)
	{
		NOx_val[i] = Number(msg_data.getFloat32(8+4*i, true));
		O2_val[i] = Number(msg_data.getFloat32(8+8+4*i, true));
	}
	if(!(isNaN(NOx_val[sel_addr]) && isNaN(O2_val[sel_addr])))
	{
		if(data_buff.length>=Buffer_size) // rolling buffer
			data_buff.shift();
		data_buff.push([timestamp, NOx_val[sel_addr], O2_val[sel_addr]]);
		if(pause_or_play)
			graph.updateOptions({'file':data_buff});
	}
}
function ws_info(evt)
{
	if(evt.reason)
	{
		document.getElementById("status_tab").value = 'WS_info:'+evt.reason;
		console.log('WS_error:' + evt.reason);
	}
}
function doSend()
{
	if(NOx_if_ws.readyState===NOx_if_ws.OPEN)
		NOx_if_ws.send("getMeasRAW");
}

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
	if(!data_ist || !data_ist.length || !minX || !maxX)
		return;
	var NOx_stat=document.getElementsByName("NOx_stat"),
		O2_stat=document.getElementsByName("O2_stat"),
		imin,NOx_min,NOx_max,NOx_acc=0,O2_min,O2_max,O2_acc=0;
	//console.log(minX + ", " + maxX);
	for(var i=0;data_ist[i]&&(data_ist[i][0].getTime())<=minX;i++);
	if(!data_ist[i])
		return;
	imin=i;
	NOx_min=data_ist[i][1];
	NOx_max=NOx_min;
	O2_min=data_ist[i][2];
	O2_max=O2_min;
	stats_calc.csv = 'Timestamp,NOx(ppm),O2(%)\n'; //init csv
	for(i++; data_ist[i]&&(data_ist[i][0].getTime())<=maxX; i++)
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
		//load zoom data to csv export obj
		let d = data_ist[i][0],
			l_date = d.getMonth()+'/'
				   + d.getDate()+'/'
				   + d.getFullYear()+' '
				   + d.getHours()+':'
				   + d.getMinutes()+':'
				   + d.getSeconds()+'.'
				   + d.getMilliseconds();
		stats_calc.csv += l_date+','+data_ist[i][1].toFixed(3)+','+data_ist[i][2].toFixed(3)+'\n';
	}
	NOx_stat[0].value=(Math.round((NOx_acc/(i-imin))*1000)/1000) + " (ppm)";
	NOx_stat[1].value=(Math.round(NOx_max*1000)/1000) + " (ppm)";
	NOx_stat[2].value=(Math.round(NOx_min*1000)/1000) + " (ppm)";
	NOx_stat[3].value=(Math.round((NOx_max-NOx_min)*1000)/1000) + " (ppm)";

	O2_stat[0].value=(Math.round((O2_acc/(i-imin))*1000)/1000) + " (%)";
	O2_stat[1].value=(Math.round(O2_max*1000)/1000) + " (%)";
	O2_stat[2].value=(Math.round(O2_min*1000)/1000) + " (%)";
	O2_stat[3].value=(Math.round((O2_max-O2_min)*1000))/1000 + " (%)";
}
function download_csv()
{
	let	d = new Date(),
		NOX_CAN_if = document.getElementById("NOX_CAN_if"),
		sel_addr = document.getElementById("sel_addr"),
		l_date = d.getMonth()+'_'
			   + d.getDate()+'_'
			   + d.getFullYear()+'_'
			   + d.getHours()+'_'
			   + d.getMinutes()+'_'
			   + d.getSeconds();
	var hiddenElement = document.createElement('a');
	hiddenElement.href = 'data:text/csv;charset=utf-8,' + encodeURI(stats_calc.csv);
	hiddenElement.target = '_blank';
	hiddenElement.download = "NOx_"+NOX_CAN_if.value+'_'+sel_addr.value+l_date+".csv";
	hiddenElement.click();
}

function download_PDF()
{
	let d = new Date(),
		NOX_CAN_if = document.getElementById("NOX_CAN_if"),
		sel_addr = document.getElementById("sel_addr"),
		div_pdf = document.getElementById('div_pdf'),
		l_date = d.getMonth()+'_'
			   + d.getDate()+'_'
			   + d.getFullYear()+'_'
			   + d.getHours()+'_'
			   + d.getMinutes()+'_'
			   + d.getSeconds(),
		filename = "NOx_"+NOX_CAN_if.value+'_'+sel_addr.value+"_"+l_date;
	console.log(filename);
	if(!document.getElementById("Zoom_Stats_check").checked)
	{
		document.getElementById("Current_data").style.display="none";
		document.getElementById("Stats").style.display="";
	}
	if (filename != null && filename.indexOf('.') == -1)
	{
		let l_date = d.getMonth()+'/'
				   + d.getDate()+'/'
				   + d.getFullYear()+' '
				   + d.getHours()+':'
				   + d.getMinutes()+':'
				   + d.getSeconds();
		html2canvas(div_pdf, {
			onrendered: function (canvas) {
				let docDefinition = {
					pageSize: 'LETTER',
					pageOrientation: 'landscape',
					pageMargins: [40, 40, 40, 40],
					header: {
						columns:[{text: window.location.hostname, alignment:'center'}]
					},
					footer: {
						columns:[
							"NOx@"+NOX_CAN_if.value+'_Addr:'+sel_addr.value,
							{text: l_date, alignment: 'right'}
						]
					},
					content: [{
						image: canvas.toDataURL(),
						width: 700,
						alignment: 'center'
					}]
				};
				pdfMake.createPdf(docDefinition).download(filename+'.pdf');
			}
		});
	}
	if(!document.getElementById("Zoom_Stats_check").checked)
		setTimeout(function()
		{
			document.getElementById("Current_data").style.display="";
			document.getElementById("Stats").style.display="none";
		}, 50);
}
//@license-end
