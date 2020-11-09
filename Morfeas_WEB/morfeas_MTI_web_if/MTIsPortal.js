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
	data_cells_new_values.push(MTI_data.MTI_status.Tele_Device_type);
	data_cells_new_values.push(MTI_data.MTI_status.Radio_CH);
	data_cells_new_values.push(MTI_data.MTI_status.Modem_data_rate);
	if(MTI_data.MTI_status.Tele_Device_type=="Disabled")
	{
		data_cells_new_values.push('Radio OFF');
		data_cells_new_values.push('N/A');
	}
	else if(MTI_data.MTI_status.Tele_Device_type=="RMSW/MUX")
	{
		data_cells_new_values.push('TRX Mode');
		data_cells_new_values.push('Both');
	}
	else
	{
		data_cells_new_values.push(MTI_data.Tele_data.RX_Success_Ratio+'%');
		switch(MTI_data.Tele_data.RX_Status)
		{
			default: data_cells_new_values.push('None'); break;
			case 1: data_cells_new_values.push('RX_1'); break;
			case 2: data_cells_new_values.push('RX_2'); break;
			case 3: data_cells_new_values.push('Both'); break;
		}
	}
	var data_cells = document.getElementsByName("stat");
	for(let i=0; i<data_cells_new_values.length&&i<data_cells.length;i++)
		data_cells[i].innerHTML=data_cells_new_values[i];
	document.getElementById('PB1').style.backgroundColor=MTI_data.MTI_status.MTI_buttons_state.PB1?'#00e657':'#000000';
	document.getElementById('PB2').style.backgroundColor=MTI_data.MTI_status.MTI_buttons_state.PB2?'#00e657':'#000000';
	document.getElementById('PB3').style.backgroundColor=MTI_data.MTI_status.MTI_buttons_state.PB3?'#00e657':'#000000';

	var pwm_meters=document.getElementsByName("PWM_meters");
	var pwm_text=document.getElementsByName("PWM_text");
	for(let i=0;i<pwm_meters.length;i++)
	{
		pwm_meters[i].value=MTI_data.MTI_status.PWM_CHs_outDuty[i];
		pwm_text[i].innerHTML=MTI_data.MTI_status.PWM_CHs_outDuty[i]+"%";
	}
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
		batt.title=MTI_data.MTI_status.MTI_batt_capacity.toFixed(0)+"%";
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
			rssid.title="Radio OFF";
			rssid_icon.src=rssid_icons_path+"_off.svg";
			break;
		case "RMSW/MUX":
			rssid.title="TRX mode";
			rssid_icon.src=rssid_icons_path+"_tx.svg";
			break;
		case "TC16":
		case "TC8":
		case "QUAD":
		case "TC4":
			rssid.title="RX "+MTI_data.Tele_data.RX_Success_Ratio+"%";
			if(MTI_data.Tele_data.RX_Success_Ratio==0)
				rssid_icon.src=rssid_icons_path+"_0.svg";
			else if(MTI_data.Tele_data.RX_Success_Ratio>0&&
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
function val_RFCH(elem)
{
	var min=parseInt(elem.min),
		max=parseInt(elem.max),
		val=parseInt(elem.value);
	if(val<min)
		elem.value=elem.min;
	else if(val>max)
		elem.value=elem.max;
	else if(val%2)
		elem.value-=1;
}
function radio_mode_init(MTI_data)
{
	var radio_mode=document.getElementById("radio_mode"),
		radio_channel=document.getElementById("radio_channel");
	radio_mode.value=MTI_data.MTI_status.Tele_Device_type;
	radio_channel.value=MTI_data.MTI_status.Radio_CH;
	switch(radio_mode.value)
	{
		case "TC16":
		case "TC8":
		case "TC4":
			document.getElementById("StV").value=MTI_data.Tele_data.Samples_toValid;
			document.getElementById("StF").value=MTI_data.Tele_data.samples_toInvalid;
			break;
		case "QUAD":
			break;
		case "RMSW/MUX":
			document.getElementById("G_SW").checked=MTI_data.MTI_status.MTI_Global_state.Global_ON_OFF;
			document.getElementById("G_SL").checked=MTI_data.MTI_status.MTI_Global_state.Global_Sleep;
			break;
	}
	radio_mode_show_hide_extra(radio_mode);
}
function radio_mode_show_hide_extra(sel)
{
	var RMSWs_extra=document.getElementById("RMSWs_extra"),
		TC_tele_extra=document.getElementById("TC_tele_extra"),
		QUAD_tele_extra=document.getElementById("QUAD_tele_extra");
	RMSWs_extra.style.display="none";
	TC_tele_extra.style.display="none";
	QUAD_tele_extra.style.display="none";
	document.getElementById("radio_channel").disabled=false;
	switch(sel.value)
	{
		case "TC16":
		case "TC8":
		case "TC4":
			TC_tele_extra.style.display="table-row";
			break;
		case "QUAD":
			QUAD_tele_extra.style.display="table-row";
			break;
		case "RMSW/MUX":
			RMSWs_extra.style.display="table-row";
			document.getElementById("radio_channel").disabled=true;
			break;
	}
}
function send_new_MTI_config()
{
	var msg_contents={};
	var radio_mode=document.getElementById("radio_mode"),
		radio_channel=document.getElementById("radio_channel");
	msg_contents.new_mode=radio_mode.value;
	msg_contents.new_RF_CH=parseInt(radio_channel.value);
	switch(radio_mode.value)
	{
		case "TC16":
		case "TC8":
		case "TC4":
			msg_contents.StV=parseInt(document.getElementById("StV").value);
			msg_contents.StF=parseInt(document.getElementById("StF").value);
			break;
		case "RMSW/MUX":
			msg_contents.new_RF_CH=0;
			msg_contents.G_SW=document.getElementById("G_SW").checked;
			msg_contents.G_SL=document.getElementById("G_SL").checked;
			break;
	}
	send_to_dbus_proxy(msg_contents,'new_MTI_config');
}
function send_to_dbus_proxy(contents, dbus_methode)
{
	if(!contents || !dbus_methode || typeof dbus_methode!='string')
		return;
	var dbus_proxy_arg={handler_type:"MTI"};
	dbus_proxy_arg.dev_name=document.getElementById("MTIDev_name_sel").value;
	dbus_proxy_arg.method=dbus_methode;
	dbus_proxy_arg.contents=contents;
	xhttp.open("POST", "/morfeas_php/morfeas_dbus_proxy.php", true);
	xhttp.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
	xhttp.send("arg="+compress(JSON.stringify(dbus_proxy_arg)));
	data_req = true;
}
function MTI_tele_dev(MTI_data)
{
	var tc=document.getElementById("TC"),
		quad=document.getElementById("QUAD"),
		rmsw_mux=document.getElementById("RMSW/MUX");
	tc.style.display='none';
	quad.style.display='none';
	rmsw_mux.style.display='none';
	switch(MTI_data.MTI_status.Tele_Device_type)
	{
		case "TC4":
		case "TC8":
		case "TC16":
			tc.style.display='flex';
			var CHs_lim, refs=[];
			switch(MTI_data.MTI_status.Tele_Device_type)
			{
				case "TC4":
					CHs_lim=4;
					refs[0]=MTI_data.Tele_data.CHs_refs[0];
					refs[1]=MTI_data.Tele_data.CHs_refs[0];
					refs[2]=MTI_data.Tele_data.CHs_refs[1];
					refs[3]=MTI_data.Tele_data.CHs_refs[1];
					break;
				case "TC8":
					CHs_lim=8;
					refs=MTI_data.Tele_data.CHs_refs;
					break;
				case "TC16":
					CHs_lim=16;
					refs=null;
					break;
			}
			tc.innerHTML='';
			var TC_tele_table = document.createElement('table');
			TC_tele_table.style.margin='auto';
			TC_tele_table.style.textalign='center';
			TC_tele_table.style.border='1px solid black';
			TC_tele_table.style.width='80%';
			if(MTI_data.Tele_data.IsValid)
			{
				let Title_row=TC_tele_table.insertRow();
				Title_row.insertCell().innerHTML = '<b>Meas</b>';
				if(refs)
					Title_row.insertCell().innerHTML = '<b>Refs</b>';
				for(let i=0;i<CHs_lim;i++)
				{
					let channel_row=TC_tele_table.insertRow();
					let meas_cell=channel_row.insertCell(0);
					meas_cell.innerHTML='CH_'+(i+1)+': ';
					if(typeof(MTI_data.Tele_data.CHs[i])==='number')
						meas_cell.innerHTML+=MTI_data.Tele_data.CHs[i].toPrecision(5)+'°C';
					else if(typeof(MTI_data.Tele_data.CHs[i])==='string')
						meas_cell.innerHTML+=MTI_data.Tele_data.CHs[i];
					if(refs)
					{
						let ref_cell=channel_row.insertCell(1);
						ref_cell.innerHTML='CH_'+(i+1)+'_Ref: ';
						ref_cell.innerHTML+=refs[i].toPrecision(5)+'°C';
					}
				}
			}
			else
			{
				let meas_cell=TC_tele_table.insertRow();
				meas_cell.innerHTML='<b>Invalid Data</b>';
			}
			tc.appendChild(TC_tele_table);
			break;
		case "QUAD":
			quad.style.display='flex';
			var QUAD_CHs=document.getElementsByName("QUAD_CHs"),
				QUAD_CNTs=document.getElementsByName("QUAD_CNTs");
			document.getElementById("Quad_valid_data").style.backgroundColor=MTI_data.Tele_data.IsValid?"green":"red";
			for(let i=0;i<QUAD_CHs.length;i++)
			{
				QUAD_CHs[i].value=MTI_data.Tele_data.CHs[i].toString();
				QUAD_CNTs[i].value=MTI_data.Tele_data.CNTs[i].toString();
			}
			break;
		case "RMSW/MUX":
			rmsw_mux.style.display='flex';
			let global_ctrl=document.getElementById('Global_ctrl');
			if(MTI_data.MTI_status.MTI_Global_state.Global_ON_OFF||MTI_data.MTI_status.MTI_Global_state.Global_Sleep)
			{
				if(!global_ctrl)
				{
					global_ctrl = document.createElement('table');
					global_ctrl.style.margin='auto';
					global_ctrl.style.textalign='center';
					global_ctrl.style.border='1px solid black';
					global_ctrl.style.width='80%';
					global_ctrl.id='Global_ctrl';
					let global_ctrl_row=global_ctrl.insertRow();
					global_ctrl_row.insertCell();
					
					rmsw_mux.appendChild(global_ctrl);
				}
			}
			else
			{
				if(global_ctrl)
					global_ctrl.remove();
			}
			break;
	}
}
//@license-end