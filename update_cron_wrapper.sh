#!/bin/bash
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

/var/www/html/morfeas_web/update.sh --check-only > /tmp/daily_update_check.log 2>&1
exit_code=$?

cat /tmp/daily_update_check.log

if [ $exit_code -eq 100 ]; then
    touch /tmp/update_needed
else
    rm -f /tmp/update_needed
fi

exit $exit_code

