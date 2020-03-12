#!/bin/bash
if [[ $EUID -ne 0 ]]; then
   echo "This script must be run as root" 
   exit 1
fi
rm -rf /var/www/html/*
cp -r morfeas-ui/dist/morfeas-web/* /var/www/html/
echo "Frontend assets updated"

rm -rf /var/www/morfeas_web/morfeas/
cp -r morfeas/ /var/www/morfeas_web/morfeas/
cd /var/www/morfeas_web/morfeas/
rm -rf ./venv
echo "Installing virtual env..."
python3 -m venv ./venv
source ./venv/bin/activate
pip install wheel
pip install mod_wsgi
echo "Installing pip packages"
pip install -r requirements.txt
mod_wsgi-express module-config > /etc/apache2/mods-available/wsgi.load
deactivate
echo "Backend assets updated"

systemctl restart apache2
echo "Service restarted"
