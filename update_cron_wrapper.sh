#!/bin/bash
/var/www/html/morfeas_web/update.sh --check-only
RET=$?
cat /mnt/ramdisk/Morfeas_Loggers/Morfeas_update_*.log > /tmp/daily_update_check.log
if [ $RET -eq 100 ]; then
    touch /tmp/morfeas_update_needed
else
    rm -f /tmp/morfeas_update_needed
fi
