<?php
$d = new Dbus(Dbus::BUS_SYSTEM, false);
//$d->waitLoop(1);
$n = $d->createProxy( "Morfeas.MTI.DBus_Server", "/Morfeas/MTI/DBUS_server_app", "Morfeas.MTI.DBus_if");
$s = $n->test_method("teet");
echo $s;
die();
?>
