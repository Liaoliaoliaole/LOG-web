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
function MTI_status_tab_update(MTI_data, MTI_status_table)
{
	var data_cells_new_values=[];
	
	data_cells_new_values.push(MTI_data.IPv4_address);
	data_cells_new_values.push(MTI_data.MTI_status.MTI_CPU_temp);
	data_cells_new_values.push(MTI_data.MTI_status.MTI_charge_status);
	data_cells_new_values.push(MTI_data.MTI_status.MTI_batt_volt);
	data_cells_new_values.push(MTI_data.MTI_status.MTI_batt_capacity);
	data_cells_new_values.push((MTI_data.MTI_status.PWM_gen_out_freq/1000)+"Kc");
	for(let i=0; i<MTI_data.MTI_status.PWM_CHs_outDuty.length;i++)	
		data_cells_new_values.push(MTI_data.MTI_status.PWM_CHs_outDuty[i]+"%");
	var data_cells = document.getElementsByName("stat");
	for(let i=0; i<data_cells_new_values.length&&i<data_cells.length;i++)
		data_cells[i].innerHTML=data_cells_new_values[i];
	document.getElementById('PB1').style.backgroundColor=MTI_data.MTI_status.MTI_buttons_state.PB1?'#00e657':'#000000';
	document.getElementById('PB2').style.backgroundColor=MTI_data.MTI_status.MTI_buttons_state.PB2?'#00e657':'#000000';
	document.getElementById('PB3').style.backgroundColor=MTI_data.MTI_status.MTI_buttons_state.PB3?'#00e657':'#000000';
}
function MTI_status_bar_update(MTI_data)
{
	const batt_icons_path = "./MTI_art/batt_icons/batt";
	const rssid_icons_path = "./MTI_art/RSSID_icons/rssid";
	var batt=document.getElementById("batt");
	var batt_icon=document.getElementById("batt_icon");
	var rssid=document.getElementById("rssid");
	var rssid_icon=document.getElementById("rssid_icon");
	//Battery status update
	if(MTI_data.MTI_status.MTI_charge_status === "Discharging")
	{
		batt.title=MTI_data.MTI_status.MTI_batt_capacity+"%";
		if(MTI_data.MTI_status.MTI_batt_capacity==100)
			batt_icon.src=batt_icons_path+"_100.svg";
		else if(MTI_data.MTI_status.MTI_batt_capacity>=80&&
				MTI_data.MTI_status.MTI_batt_capacity<100)
					batt_icon.src=batt_icons_path+"_80.svg";
		else if(MTI_data.MTI_status.MTI_batt_capacity>=60&&
				MTI_data.MTI_status.MTI_batt_capacity<80)
					batt_icon.src=batt_icons_path+"_60.svg";
		else if(MTI_data.MTI_status.MTI_batt_capacity>=40&&
				MTI_data.MTI_status.MTI_batt_capacity<60)
					batt_icon.src=batt_icons_path+"_40.svg";
		else if(MTI_data.MTI_status.MTI_batt_capacity>=20&&
				MTI_data.MTI_status.MTI_batt_capacity<40)
					batt_icon.src=batt_icons_path+"_20.svg";
		else if(MTI_data.MTI_status.MTI_batt_capacity>=0&&
				MTI_data.MTI_status.MTI_batt_capacity<20)
					batt_icon.src=batt_icons_path+".svg";
	}
	else if(MTI_data.MTI_status.MTI_charge_status === "Charging")
	{
		batt.title="Charging";
		batt_icon.src=batt_icons_path+"_charge.svg";
	}
	else if(MTI_data.MTI_status.MTI_charge_status === "Full")
	{
		batt.title="Full";
		batt_icon.src=batt_icons_path+"_full.svg";
	}
	//RSSID status update
	switch(MTI_data.MTI_status.Tele_Device_type)
	{
		case "":
		case "Disabled":
			rssid.title="TRX OFF";
			rssid_icon.src=rssid_icons_path+"_off.svg";
			break;
		case "RMSW/MUX":
			rssid.title="TX mode";
			rssid_icon.src=rssid_icons_path+"_100.svg";
			break;
		case "TC16":
		case "TC8":
		case "QUAD":
		case "TC4":
			rssid.title="RX "+MTI_data.Tele_data.RX_Success_Ratio+"%";
			if(MTI_data.Tele_data.RX_Success_Ratio>=0&&
			   MTI_data.Tele_data.RX_Success_Ratio<20)
			   rssid_icon.src=rssid_icons_path+"_20.svg";
			else if(MTI_data.Tele_data.RX_Success_Ratio>=20&&
					MTI_data.Tele_data.RX_Success_Ratio<50)
						rssid_icon.src=rssid_icons_path+"_50.svg";
			else if(MTI_data.Tele_data.RX_Success_Ratio>=50&&
					MTI_data.Tele_data.RX_Success_Ratio<75)
						rssid_icon.src=rssid_icons_path+"_75.svg";
			else if(MTI_data.Tele_data.RX_Success_Ratio>=75&&
					MTI_data.Tele_data.RX_Success_Ratio<=100)
						rssid_icon.src=rssid_icons_path+"_100.svg";
			break;
	}

}
//@license-end