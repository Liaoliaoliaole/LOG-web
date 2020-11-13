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
			msg_contents.G_SL=false;
			break;
	}
	send_to_dbus_proxy(msg_contents,'new_MTI_config');
}
function Global_switch(new_status)
{
	var msg_contents={};
	msg_contents.G_P_state=new_status;
	msg_contents.G_S_state=0;
	send_to_dbus_proxy(msg_contents,'MTI_Global_SWs');
}
function MUX_Sel(sw_name, new_state, mem_pos)
{
	var msg_contents={};
	msg_contents.mem_pos=mem_pos;
	msg_contents.tele_type='MUX';
	msg_contents.sw_name=sw_name;
	msg_contents.new_state=new_state;
	send_to_dbus_proxy(msg_contents,'ctrl_tele_SWs');
}
function RMSW_ctrl(sw_name, new_state, mem_pos, tele_type)
{
	var msg_contents={};
	msg_contents.mem_pos=mem_pos;
	msg_contents.tele_type=tele_type;
	msg_contents.sw_name=sw_name;
	msg_contents.new_state=new_state;
	send_to_dbus_proxy(msg_contents,'ctrl_tele_SWs');
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
			let global_ctrl_cells=document.getElementsByName('Global_SW');
			if(MTI_data.MTI_status.MTI_Global_state.Global_ON_OFF)
			{
				for(let i=0; i<global_ctrl_cells.length; i++)
					global_ctrl_cells[i].style.display='table-cell';
				document.getElementById('G_SW_value').style.backgroundColor=MTI_data.MTI_status.MTI_Global_state.Global_Power_state?'green':'red';
			}
			else
			{
				for(let i=0; i<global_ctrl_cells.length; i++)
					global_ctrl_cells[i].style.display='none';
			}
			let RMSWs_MUXs_data_div = document.getElementById('RMSWs_MUXs_data');
			if(MTI_data.Tele_data.length)
			{
				if(RMSWs_MUXs_data_div)
					update_RMSWs_MUXs_data(MTI_data);
				else
					create_RMSWs_MUXs_data_div(MTI_data, rmsw_mux);
			}
			else if(RMSWs_MUXs_data_div)
				RMSWs_MUXs_data_div.remove();
			break;
	}
}
function populate_RMSW_MUX_table(Tele_data, MUX_table)
{
	let row=MUX_table.insertRow();
	row.insertCell().innerHTML='<b>Dev_Type</b>';
	row.insertCell().innerHTML='<b>Dev_ID</b>';
	row.insertCell().innerHTML='<b>Last_RX</b>';
	row.insertCell().innerHTML='<b>Dev_temp</b>';
	row.insertCell().innerHTML='<b>Supply_Voltage</b>';
	row.insertCell().innerHTML='<b>Mem_offset</b>';
	row=MUX_table.insertRow();
	for(let i=0;i<MUX_table.rows[0].cells.length;i++)
		row.insertCell().setAttribute('name',Tele_data.Dev_type+'_'+Tele_data.Dev_ID+'_data');
	switch(Tele_data.Dev_type)
	{
		case 'MUX':
			row=MUX_table.insertRow();
			for(let i=1;i<=4;i++)
			{
				let ch_cell=row.insertCell();
				ch_cell.innerHTML='<b>CH'+i+':A</b>';
				ch_cell.setAttribute('name',Tele_data.Dev_type+'_'+Tele_data.Dev_ID+'_sel_ch');
			}
			row=MUX_table.insertRow();
			for(let i=1;i<=4;i++)
			{
				let ch_cell=row.insertCell();
				let elem=document.createElement('button');
				elem.innerHTML='A';
				elem.onclick=function(){MUX_Sel('Sel_'+i, false,  parseInt(MUX_table.rows[1].cells[MUX_table.rows[1].cells.length-1].innerHTML))};
				ch_cell.appendChild(elem);
				elem=document.createElement('button');
				elem.innerHTML='B';
				elem.onclick=function(){MUX_Sel('Sel_'+i, true, parseInt(MUX_table.rows[1].cells[MUX_table.rows[1].cells.length-1].innerHTML))};
				ch_cell.appendChild(elem);
			}
			break;
		case 'RMSW':
			row=MUX_table.insertRow();
			row.insertCell().innerHTML='<b>CH1_Volt</b>';
			row.insertCell().innerHTML='<b>CH1_Curr</b>';
			row.insertCell().innerHTML='<b>CH2_Volt</b>';
			row.insertCell().innerHTML='<b>CH2_Curr</b>';
			row=MUX_table.insertRow();
			for(let i=1;i<=4;i++)
			{
				let meas_cell=row.insertCell();
				meas_cell.setAttribute('name',Tele_data.Dev_type+'_'+Tele_data.Dev_ID+'_meas');
			}
			row=MUX_table.insertRow();
			row.setAttribute('name','GL_check');
			let ctrl_cells_name=['<b>Main:</b>','<b>CH1:</b>','<b>CH2:</b>'],
				ctrl_sw_name=['Main_SW','SW_1','SW_2'];
			for(let i=0;i<ctrl_cells_name.length;i++)
			{
				let sw=row.insertCell();
				sw.innerHTML=ctrl_cells_name[i];
				sw.colSpan='2';
				let elem=document.createElement('button');
				elem.innerHTML='ON';
				elem.onclick=function(){RMSW_ctrl(ctrl_sw_name[i], 
												  true, 
												  parseInt(MUX_table.rows[1].cells[MUX_table.rows[1].cells.length-1].innerHTML), 
												  'RMSW')};
				let led=document.createElement('dev');
				led.className='led';
				led.setAttribute('name', Tele_data.Dev_type+'_'+Tele_data.Dev_ID+'_ind');
				elem.appendChild(led);
				sw.appendChild(elem);
				elem=document.createElement('button');
				elem.innerHTML='OFF';
				elem.onclick=function(){RMSW_ctrl(ctrl_sw_name[i], 
												  false, 
												  parseInt(MUX_table.rows[1].cells[MUX_table.rows[1].cells.length-1].innerHTML), 
												  'RMSW')};
				led=document.createElement('dev');
				led.className='led';
				led.setAttribute('name', Tele_data.Dev_type+'_'+Tele_data.Dev_ID+'_ind');
				elem.appendChild(led);
				sw.appendChild(elem);
			}
			break;
		case 'Mini_RMSW':
			row=MUX_table.insertRow();
			for(let i=1;i<=4;i++)
				row.insertCell().innerHTML='<b>TC_CH'+i+'</b>';
			row=MUX_table.insertRow();
			for(let i=1;i<=4;i++)
			{
				let meas_cell=row.insertCell();
				meas_cell.setAttribute('name',Tele_data.Dev_type+'_'+Tele_data.Dev_ID+'_meas');
			}
			row=MUX_table.insertRow();
			row.setAttribute('name','GL_check');
			let Main_sw=row.insertCell();
			Main_sw.innerHTML='<b>Main:</b>';
			Main_sw.colSpan='2';
			let elem=document.createElement('button');
			elem.innerHTML='ON';
			elem.onclick=function(){RMSW_ctrl('Main_SW', 
											  true, 
											  parseInt(MUX_table.rows[1].cells[MUX_table.rows[1].cells.length-1].innerHTML), 
											  'Mini_RMSW')};
			let led=document.createElement('dev');
			led.className='led';
			led.setAttribute('name', Tele_data.Dev_type+'_'+Tele_data.Dev_ID+'_main_sw');
			elem.appendChild(led);
			Main_sw.appendChild(elem);
			elem=document.createElement('button');
			elem.innerHTML='OFF';
			elem.onclick=function(){RMSW_ctrl('Main_SW', 
											  false, 
											  parseInt(MUX_table.rows[1].cells[MUX_table.rows[1].cells.length-1].innerHTML), 
											  'Mini_RMSW')};
			led=document.createElement('dev');
			led.className='led';
			led.setAttribute('name', Tele_data.Dev_type+'_'+Tele_data.Dev_ID+'_main_sw');
			elem.appendChild(led);
			Main_sw.appendChild(elem);
			break;
	}
}

function create_RMSWs_MUXs_data_div(MTI_data, rmsw_mux_div)
{
	let RMSWs_MUXs_data_div = document.getElementById('RMSWs_MUXs_data');
	if(!MTI_data.Tele_data.length||!rmsw_mux_div)
		return;
	if(!RMSWs_MUXs_data_div)
	{
		RMSWs_MUXs_data_div=document.createElement('div');
		RMSWs_MUXs_data_div.style.margin='auto';
		RMSWs_MUXs_data_div.style.textalign='center';
		RMSWs_MUXs_data_div.id='RMSWs_MUXs_data';
		rmsw_mux_div.appendChild(RMSWs_MUXs_data_div);
	}
	for(let i=0;i<MTI_data.Tele_data.length;i++)
	{
		switch(MTI_data.Tele_data[i].Dev_type)
		{
			case 'MUX':
			case 'RMSW':
			case 'Mini_RMSW':
				if(document.getElementById(MTI_data.Tele_data[i].Dev_type+'_'+MTI_data.Tele_data[i].Dev_ID))
					break;
				var new_RMSW_MUX_data_table=document.createElement('table');
				new_RMSW_MUX_data_table.id=MTI_data.Tele_data[i].Dev_type+'_'+MTI_data.Tele_data[i].Dev_ID;
				new_RMSW_MUX_data_table.style.margin='auto';
				new_RMSW_MUX_data_table.style.textalign='center';
				new_RMSW_MUX_data_table.style.border='1px solid black';
				new_RMSW_MUX_data_table.style.width='6in';
				new_RMSW_MUX_data_table.style.marginBottom ='.1in';
				RMSWs_MUXs_data_div.appendChild(new_RMSW_MUX_data_table);
				populate_RMSW_MUX_table(MTI_data.Tele_data[i], new_RMSW_MUX_data_table)
				break;
		}
	}
}
function update_RMSWs_MUXs_data(MTI_data)
{
	var html_tele_data;
	//Check and remove tables for dead RMSW/MUX
	let RMSWs_MUXs_data=document.getElementById('RMSWs_MUXs_data');
	if(RMSWs_MUXs_data)
	{
		for(let i=0;i<RMSWs_MUXs_data.childNodes.length;i++)
		{
			for(var j=0;j<MTI_data.Tele_data.length;j++)
				if(RMSWs_MUXs_data.childNodes[i].id===(MTI_data.Tele_data[j].Dev_type+'_'+MTI_data.Tele_data[j].Dev_ID))
					break;
			if(j==MTI_data.Tele_data.length)
				RMSWs_MUXs_data.childNodes[i].remove();
		}
	}
	//Check Global_ON_OFF mode and disable RMSW control button
	let GL_check = document.getElementsByName('GL_check');
	for(let i=0;i<GL_check.length;i++)
		GL_check[i].style.display=MTI_data.MTI_status.MTI_Global_state.Global_ON_OFF?'none':'table-row';
	for(let i=0;i<MTI_data.Tele_data.length;i++)
	{	
		//Check for existence and update
		if((html_tele_data=document.getElementsByName(MTI_data.Tele_data[i].Dev_type+'_'+MTI_data.Tele_data[i].Dev_ID+'_data')).length)
		{
			html_tele_data[0].innerHTML=MTI_data.Tele_data[i].Dev_type;
			html_tele_data[1].innerHTML=MTI_data.Tele_data[i].Dev_ID;
			html_tele_data[2].innerHTML=MTI_data.Tele_data[i].Time_from_last_msg+' sec';
			html_tele_data[3].innerHTML=MTI_data.Tele_data[i].Dev_temp+'°C';
			html_tele_data[4].innerHTML=MTI_data.Tele_data[i].Supply_volt+'V';
			html_tele_data[5].innerHTML=MTI_data.Tele_data[i].Mem_offset;
			switch(MTI_data.Tele_data[i].Dev_type)
			{
				case 'MUX':
					let html_MUX_data = document.getElementsByName(MTI_data.Tele_data[i].Dev_type+'_'+MTI_data.Tele_data[i].Dev_ID+'_sel_ch');
					for(let j=0; j<html_MUX_data.length; j++)
						html_MUX_data[j].innerHTML='<b>CH'+(j+1)+':'+eval('MTI_data.Tele_data['+i+'].Controls.CH'+(j+1))+'</b>';
					break;
				case 'RMSW':
					let html_RMSW_data = document.getElementsByName(MTI_data.Tele_data[i].Dev_type+'_'+MTI_data.Tele_data[i].Dev_ID+'_meas');
					for(let j=0; j<html_RMSW_data.length; j++)
						html_RMSW_data[j].innerHTML=MTI_data.Tele_data[i].CHs_meas[j]+(j%2?'A':'V');
					let html_RMSW_ind = document.getElementsByName(MTI_data.Tele_data[i].Dev_type+'_'+MTI_data.Tele_data[i].Dev_ID+'_ind');
					if(html_RMSW_ind.length)
					{
						html_RMSW_ind[0].style.backgroundColor=MTI_data.Tele_data[i].Controls.Main?'Chartreuse':'DarkGreen';
						html_RMSW_ind[1].style.backgroundColor=MTI_data.Tele_data[i].Controls.Main?'DarkGreen':'Chartreuse';
						html_RMSW_ind[2].style.backgroundColor=MTI_data.Tele_data[i].Controls.CH1?'Chartreuse':'DarkGreen';
						html_RMSW_ind[3].style.backgroundColor=MTI_data.Tele_data[i].Controls.CH1?'DarkGreen':'Chartreuse';
						html_RMSW_ind[4].style.backgroundColor=MTI_data.Tele_data[i].Controls.CH2?'Chartreuse':'DarkGreen';
						html_RMSW_ind[5].style.backgroundColor=MTI_data.Tele_data[i].Controls.CH2?'DarkGreen':'Chartreuse';
					}
					break;
				case 'Mini_RMSW':
					let html_Mini_RMSW_data = document.getElementsByName(MTI_data.Tele_data[i].Dev_type+'_'+MTI_data.Tele_data[i].Dev_ID+'_meas');
					for(let j=0; j<html_Mini_RMSW_data.length; j++)
						html_Mini_RMSW_data[j].innerHTML=MTI_data.Tele_data[i].CHs_meas[j]+(typeof(MTI_data.Tele_data[i].CHs_meas[j])=='number'?'°C':'');
					let html_main_sw_ind = document.getElementsByName(MTI_data.Tele_data[i].Dev_type+'_'+MTI_data.Tele_data[i].Dev_ID+'_main_sw');
					if(html_main_sw_ind.length)
					{
						html_main_sw_ind[0].style.backgroundColor=MTI_data.Tele_data[i].Controls.Main?'Chartreuse':'DarkGreen';
						html_main_sw_ind[1].style.backgroundColor=MTI_data.Tele_data[i].Controls.Main?'DarkGreen':'Chartreuse';
					}
					break;
			}
		}
		else //Add new Tele table 
		{
			let RMSWs_MUXs_data_div = document.getElementById('RMSWs_MUXs_data');
			let new_RMSW_MUX_data_table=document.createElement('table');
			new_RMSW_MUX_data_table.id=MTI_data.Tele_data[i].Dev_type+'_'+MTI_data.Tele_data[i].Dev_ID;
			new_RMSW_MUX_data_table.style.margin='auto';
			new_RMSW_MUX_data_table.style.textalign='center';
			new_RMSW_MUX_data_table.style.border='1px solid black';
			new_RMSW_MUX_data_table.style.width='6in';
			new_RMSW_MUX_data_table.style.marginBottom ='.1in';
			RMSWs_MUXs_data_div.appendChild(new_RMSW_MUX_data_table);
			populate_RMSW_MUX_table(MTI_data.Tele_data[i], new_RMSW_MUX_data_table)
		}
	}
}
//@license-end