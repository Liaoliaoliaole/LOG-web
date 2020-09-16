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
td.bold{
    font-weight: bold;
}
input.global_style{
	width: 3em;
}
.content{
  top: 50%;
  left: 50%;
  margin: auto;
}
</style>
</head>
<body>
<div class="content">
	<table style="margin:auto;text-align:center;margin-bottom:.075in;width:7in;">
	  <tr>
		<th colspan="5" style="font-weight: bold; font-size: xx-large"><img src="./art/Morfeas_logo_yellow.png" width="100" height="100"></th>
	  </tr>
	  <tr>
		<th colspan="5" style="font-weight: bold; font-size: xx-large">Morfeas WEB<br>(<?php echo gethostname();?>)</th>
	  </tr>
	  <tr>
		<td><input type="button" value="SDAQs Portal" onclick='PopupCenter("/morfeas_SDAQ_web_if/"+"?q="+makeid(),"","1024","768")'></td>
		<td><input type="button" value="MDAQs Portal" onclick='PopupCenter("/morfeas_MDAQ_web_if/"+"?q="+makeid(),"","1024","768")'></td>
		<td><input type="button" value="IOBOXs Portal" onclick='PopupCenter("/morfeas_IOBOX_web_if/"+"?q="+makeid(),"","1024","768")'></td>
		<td><input type="button" value="MTIs Portal" onclick='PopupCenter("/morfeas_MTI_web_if/"+"?q="+makeid(),"","1024","768")'></td>
	  </tr>
	  <tr>
		<td><input type="button" value="ISOChannel Linker (Wapice)" onclick='PopupCenter("/ISOChannel_Linker/html/","","1280","1024")'></td>
		<td><input type="button" value="System Loggers" onclick='PopupCenter("/morfeas_Loggers/"+"?q="+makeid(),"","1024","768")'></td>
		<td><input type="button" value="System Components" onclick='PopupCenter("/Morfeas_configuration/Morfeas_System_config.html"+"?q="+makeid(),"","1024","768")'></td>
		<td><input type="button" value="Network Configuration" onclick='PopupCenter("/Morfeas_configuration/Network_config.html"+"?q="+makeid(),"","560","300")'></td>
		<!--<td><input type="button" value="Shell in a Box (Applet)" onclick='PopupCenter("https://"+window.location.hostname+":4200","","1024","768")'></td>-->
	  </tr>
	</table>
</div>
<footer>
  <p>Author: Sam Harry Tzavaras &#169; 12019-12020<br>
  <a href="LICENSE">License: AGPL v3</a><br>
</footer> 
</body>
<script src='../morfeas_ecma/common.js'></script>
<script>
	comp_check();
</script>
</html>