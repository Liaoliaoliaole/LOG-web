#!/bin/bash
set -e

function update_assets() {
    sudo rm -rf /var/www/*
    sudo mkdir /var/www/html && sudo mkdir /var/www/morfeas_web/
    sudo chown $USER /var/www/morfeas_web
    sudo chown $USER /var/www/html
    sudo chown -R $USER "$MORFEAS_CONFIG_FOLDER"
    sudo chown -R $USER "$MORFEAS_RAMDISK_PATH"

    cp -r morfeas-ui/dist/morfeas-web/* /var/www/html/
    echo "Frontend assets updated"

    rm -rf /var/www/morfeas_web/morfeas/
    cp -r morfeas/ /var/www/morfeas_web/morfeas/
    cd /var/www/morfeas_web/morfeas/
    rm -rf ./venv
    echo "Installing virtual env..."
    python3 -m venv ./venv
    # Enter virtual env
    source ./venv/bin/activate
    pip install wheel
    pip install mod_wsgi
    echo "Installing pip packages"
    pip install -r requirements.txt
    mod_wsgi-express module-config | sudo tee /etc/apache2/mods-available/wsgi.load
    # Exit virtual env
    deactivate
    echo "Backend assets updated"

    echo "Wrote Morfeas web config JSON"
    echo "$MORFEAS_WEB_CONFIG_JSON" > /var/www/morfeas_web/morfeas/config.json

    echo "Setting correct permissions for configuration and ramdisk.."
    sudo chown -R $USER:www-data "$MORFEAS_RAMDISK_PATH"
    sudo chown -R $USER:www-data "$MORFEAS_CONFIG_FOLDER"

    sudo chmod 775 "$MORFEAS_RAMDISK_PATH" -R
    sudo chmod 775 "$MORFEAS_CONFIG_FOLDER" -R

    echo "Restart apache.."
    sudo systemctl restart apache2
}

if [ "$EUID" == 0 ]
  then echo "Please do not run as root"
  exit
fi

echo "Select script mode: 1: First time install, 2: Update Morfeas UI (first time install has been performed before)"
read INSTALL_TYPE

if [ "$INSTALL_TYPE" != "1" ] && [ "$INSTALL_TYPE" != "2" ]
    then echo "Invalid install type. Must be either 1 or 2, exitting..."
    exit
fi

read -p "Morfeas config folder: " -e -i "/home/$USER/Morfeas_core/configuration/" MORFEAS_CONFIG_FOLDER
read -p "Ramdisk path: " -e -i "/mnt/ramdisk/" MORFEAS_RAMDISK_PATH

SUDOERS_FILE_CONTENT="Cmnd_Alias ALLOW_PASSWORDLESS = /bin/systemctl restart networking.service,\
                                /bin/systemctl restart systemd-timesyncd.service,\
                                /bin/systemctl restart Morfeas_system.service,\
                                /bin/hostname,\
                                /usr/sbin/poweroff,\
                                /sbin/reboot

www-data ALL = (ALL) NOPASSWD: ALLOW_PASSWORDLESS
"

APACHE_MORFEAS_CONFIG="<VirtualHost *:80>
    ServerName localhost
    ErrorLog \${APACHE_LOG_DIR}/error.log
    CustomLog \${APACHE_LOG_DIR}/access.log combined

    WSGIDaemonProcess morfeas_web user="$USER" group="$USER" 
    WSGIScriptAlias /api /var/www/morfeas_web/morfeas/morfeas.wsgi

    Alias /ramdisk /mnt/ramdisk/

    # Allow SPA url routing through angular (e.g. /logs path)
    <Directory ~ \"^/[\w+\d+-]+\">
        FallbackResource /index.html
    </Directory>

    <Directory /var/www/html>
        Require all granted
    </Directory>
    
    <Directory /mnt/ramdisk>
        Options Indexes
        Require all granted
    </Directory>
</VirtualHost>
"

MORFEAS_WEB_CONFIG_JSON='{
    "CONFIG_PATH": "'"$MORFEAS_CONFIG_FOLDER"'",
    "RAMDISK_PATH": "'"$MORFEAS_RAMDISK_PATH"'",
    "OPC_UA_CONFIG_FILE": "OPC_UA_Config.xml",
    "ISO_STANDARD_FILE": "iso_standards.xml",
    "MORFEAS_CONFIG_FILE": "Morfeas_config.xml",
    "OPC_UA_REQUIRED_FIELDS": [
        "ISO_CODE",
        "ANCHOR",
        "MIN_VALUE",
        "MAX_VALUE",
        "DESCRIPTION",
        "INTERFACE_TYPE",
        "UNIT"
    ],
    "DEBUG": false
}'


# installtype: Update
if [ "$INSTALL_TYPE" == "2" ] 
    then
    update_assets
    echo "Done!"
    exit
fi

sudo apt update && sudo apt upgrade -y
sudo apt install apache2 apache2-dev python3-dev python3-venv -y

update_assets

echo "Add www-data user to your group"
sudo usermod -aG $USER www-data

echo "Creating apache2 config.."
echo "$APACHE_MORFEAS_CONFIG" | sudo tee /etc/apache2/sites-available/morfeas_web.conf
# Enable wsgi module
sudo a2enmod wsgi
# Disable default config, enable morfeas config
sudo a2dissite 000-default.conf && sudo a2ensite morfeas_web.conf

echo "Give write privileges to 'other' for Interface, timesyncd.conf, hostname and hosts"
sudo chmod o+rw /etc/network/interfaces /etc/systemd/timesyncd.conf /etc/hostname /etc/hosts

echo "Writing sudoers file..."
echo "$SUDOERS_FILE_CONTENT" | sudo EDITOR='tee -a' visudo

sudo systemctl restart apache2

echo "Done!"
