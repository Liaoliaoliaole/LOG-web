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
function data_plot(SDAQnet_data)
{
	var SDAQnet_stats = document.getElementById("SDAQnet_stats");
	var SDAQs_list = document.getElementById("SDAQs_list");
	var time_now = Number((new Date().getTime()/1000).toFixed(0));
	if(!data_plot.prev)
		data_plot.prev={};
	SDAQnet_stats.innerHTML="Det_devs:"+SDAQnet_data.Detected_SDAQs+
							" Bus_util: "+SDAQnet_data.BUS_Utilization+'%';
	if(SDAQnet_data.hasOwnProperty('Electrics'))
		SDAQnet_stats.innerHTML+=" Bus_voltage: "+SDAQnet_data.Electrics.BUS_voltage+"V"+" Bus_Amperage: "+SDAQnet_data.Electrics.BUS_amperage+"A"
	if(data_plot.prev.amount!=SDAQnet_data.Detected_SDAQs || data_plot.prev.bus!=SDAQnet_data.CANBus_interface || !SDAQs_list.innerHTML)
	{
		SDAQs_list.innerHTML="";
		if(SDAQnet_data.SDAQs_data)
			SDAQ_dev_list_tree(SDAQs_list, SDAQnet_data.SDAQs_data);
		data_plot.prev.amount=SDAQnet_data.Detected_SDAQs;
		data_plot.prev.bus=SDAQnet_data.CANBus_interface;
	}
}
function SDAQ_dev_list_tree(listNode, SDAQs_data)
{
	for(let i = 0; i<SDAQs_data.length; i++)
	{
		let textNode = document.createTextNode(SDAQs_data[i].SDAQ_type+'('+SDAQs_data[i].Address+')'),
		liNode = document.createElement("LI");
		liNode.classList.add("caret");
		liNode.onclick = function()
		{
			var others = document.getElementsByClassName("caret-down");
			for(let j = 0; j<others.length; j++)
			{
				if(others[j] !== this)
				{
					others[j].style.fontWeight = "normal";
					others[j].classList.value = "caret";
				}
			}
			this.classList.value = "caret-down";
			this.style.fontWeight = "bold";
		};
		liNode.appendChild(textNode);
		listNode.appendChild(liNode);
	}
}
/*
var ctx = document.getElementById('data_plot_canvas').getContext('2d');
var myChart = new Chart(ctx, {
    type: 'line',
    data: {
        labels: ['Red', 'Blue', 'Yellow', 'Green', 'Purple', 'Orange'],
        datasets: [{
            label: '# of Votes',
            data: [12, 19, 3, 5, 2, 3]
        }]
    },
    options: {
        scales: {
            yAxes: [{
                ticks: {
                    beginAtZero: true
                }
            }]
        },
		plugins: {
			zoom: {
				// Container for pan options
				pan: {
					// Boolean to enable panning
					enabled: true,

					// Panning directions. Remove the appropriate direction to disable
					// Eg. 'y' would only allow panning in the y direction
					// A function that is called as the user is panning and returns the
					// available directions can also be used:
					//   mode: function({ chart }) {
					//     return 'xy';
					//   },
					mode: 'xy',

					rangeMin: {
						// Format of min pan range depends on scale type
						x: null,
						y: null
					},
					rangeMax: {
						// Format of max pan range depends on scale type
						x: null,
						y: null
					},

					// On category scale, factor of pan velocity
					speed: 20,

					// Minimal pan distance required before actually applying pan
					threshold: 10,

					// Function called while the user is panning
					onPan: function({chart}) { console.log("I'm panning!!!");},
					// Function called once panning is completed
					onPanComplete: function({chart}) { console.log("I was panned!!!");}
				}
			}
		}
    }
});
*/
//@license-end