<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
<meta http-equiv="Pragma" content="no-cache" />
<meta http-equiv="Expires" content="0" />
<link rel="shortcut icon" type="image/x-icon" href="/art/Morfeas_logo_yellow.ico"/>

<title>Morfeas WEB</title>
<style>
#footer{
   position:absolute;
   bottom:2%;
   width:99%;
}
img.bsize{
	width:35px;
	height:40px;
}
td.bold{
    font-weight: bold;
}
.content{
  top: 50%;
  left: 50%;
  margin: auto;
}
</style>
</head>
<body>
<span style="margin:auto; float:right;">
	<a style="cursor:pointer;color:blue;"
	onclick='PopupCenter("/Help_and_manuals/Help/"+"?q="+makeid(),"","1480","800")'
	onMouseOver="this.style.color='red'"
	onMouseOut="this.style.color='blue'">Morfeas-WEB Docs</a>
</span>
<br>
<div class="content">
	<table style="margin:auto;text-align:center;margin-bottom:.075in;width:7in;">
	  <tr>
		<th colspan="4" style="font-weight: bold; font-size: xx-large">
			<img src="./art/Morfeas_logo_yellow.png" width="100" height="100">
		</th>
	  </tr>
	  <tr>
		<th colspan="4" style="font-weight: bold; font-size: xx-large">Morfeas WEB<br>(<?php echo gethostname();?>)</th>
	  </tr>
	  <tr>
		<td><button type="button" onclick='PopupCenter("/morfeas_SDAQ_web_if/"+"?q="+makeid(),"","1024","768")'>SDAQs<br>Portal</td>
		<td><button type="button" onclick='PopupCenter("/morfeas_MDAQ_web_if/"+"?q="+makeid(),"","1024","768")'>MDAQs<br>Portal</td>
		<td><button type="button" onclick='PopupCenter("/morfeas_IOBOX_web_if/"+"?q="+makeid(),"","1024","768")'>IOBOXs<br>Portal</td>
		<td><button type="button" onclick='PopupCenter("/morfeas_MTI_web_if/"+"?q="+makeid(),"","1024","768")'>MTIs<br>Portal</td>
	  </tr>
	  <tr>
		<td><button type="button" onclick='PopupCenter("/ISOChannel_Linker/html/","","1280","1024")'>
			<span title="ISOChannel Linker">
				<img src="./art/anchor.png" class="bsize">
			</span>
		</td>
		<td><button type="button" onclick='PopupCenter("/morfeas_Loggers/"+"?q="+makeid(),"","1024","768")'>
			<span title="System Loggers">
				<img src="./art/logger.svg" class="bsize">
			</span>
		</td>
		<td><button type="button" onclick='PopupCenter("/Morfeas_configuration/Morfeas_System_config.html"+"?q="+makeid(),"","1024","768")'>
			<span title="System Configuration">
				<img src="./art/morfeas_gear.png" class="bsize">
			</span>
		</td>
		<td><button type="button" onclick='PopupCenter("/Morfeas_configuration/Network_config.html"+"?q="+makeid(),"","560","300")'>
			<span title="Network Configuration">
				<img src="./art/eth.png" class="bsize">
			</span>
		</td>
	  </tr>
	</table>
</div>
<footer id="footer">
	<div style="float:left;"> 
		Author: Sam Harry Tzavaras &#169; 12019-12020<br>
		<a href="LICENSE">License: AGPLv3</a>
	</div>
	<div style="float:right;"> 
		<a style="color:white;" onclick='PopupCenter("https://"+window.location.hostname+":4200","","1024","768")'>π</a>
		<img src="./art/debian.png">
	</div>
</footer>
</body>
<script src='../morfeas_ecma/common.js'></script>
<script>
	comp_check();
</script>
</html>