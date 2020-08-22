# Morfeas Web

## Deploying the project

### Deploy using install script

DURING THE INSTALLATION THE TARGET DEVICE NEEDS TO BE CONNECTED TO THE INTERNET

1. Download latest version from https://gitlab.com/fantomsam/morfeas_web/-/jobs/artifacts/master/download?job=build+prod
2. Unzip the file `unzip artifacts.zip`
3. Run the install.sh `bash install.sh`

**Expects ramdisk to be found in /mnt/ramdisk/ and access rights to the folder to be correct**

## Clone project and Install Dependencies
```
$ #Clone Project's repository and change directory
$ git clone https://gitlab.com/fantomsam/morfeas_web.git Morfeas_web && cd Morfeas_web
$ #Update/Upgrade GNU distro
$ sudo apt update && sudo apt upgrade
$ #Install apache 2 and dependencies
$ sudo apt install apache2 apache2-dev python3-dev python3-venv php libapache2-mod-php npm
$ npm install -g npm
$ npm install nodejs
$ #Install Dependences for Python 3
$ sudo pip3 install wheel mod-wsgi
$ sudo pip3 install -r ./morfeas/requirements.txt
$ #Add "www-data" user to your group
$ sudo usermod -aG $USER www-data
```

# Building the UI project
1. Install the required dependencies by running `npm install`
1. Build the Angular project by running `npm run build-prod` inside the `morfeas-ui/` folder. The transpiled files will be in `morfeas-ui/dist/morfeas-web/` which can be then copied to the web server.

## Copy the builded UI project and the backend application to "www" Directory

```
$ cd Morfeas_web
$ sudo mkdir /var/www/morfeas_web
$ sudo chown $USER /var/www/morfeas_web
$ sudo chown $USER /var/www/html
$ sudo rm /var/www/html/* && cp -r ./morfeas-ui/dist/morfeas-web/* /var/www/html/
$ cp -r morfeas /var/www/morfeas_web/
```
## Configure Morfeas_Web Backend and setup site-config for Apache 2
```
$ cp morfeas/config.template.json /var/www/morfeas_web/config.json
$ #Modify config.json accordingly with Morfeas_system paths and files
$ nano /var/www/morfeas_web/config.json
$ #Make Morfeas_web site's configuration
$ sudo nano /etc/apache2/sites-available/morfeas_web.conf
```

### Use the following content as config template
**Create and Place the site's Configuration at /etc/apache2/sites-available/morfeas_web.conf**
```
<VirtualHost *:80>
    ServerName localhost
    ErrorLog ${APACHE_LOG_DIR}/error.log
    CustomLog ${APACHE_LOG_DIR}/access.log combined

    WSGIDaemonProcess morfeas_web user=#YOUR_USER_NAME group=#YOUR_USER_GROUP
    WSGIScriptAlias /api /var/www/morfeas_web/morfeas/morfeas.wsgi

    Alias /ramdisk /mnt/ramdisk/

    <Directory /var/www/html>
        Require all granted
    </Directory>

    <Directory /mnt/ramdisk>
        Options Indexes
        Require all granted
    </Directory>
</VirtualHost>

```

## Enable Apache modules and site
```
$ #Config apache's module wsgi
$ sudo sh -c "mod_wsgi-express module-config > /etc/apache2/mods-available/wsgi.load"
$ #Enable module wsgi
$ sudo a2enmod wsgi
$ #Disable default and enable morfeas_web site
$ sudo a2dissite 000-default.conf && sudo a2ensite morfeas_web.conf
$ #Restart apache
$ sudo systemctl restart apache2
```

## Give access privileges to "others" and allow specific Passwordless calls for "www-data" user
```
$ #Give write privileges to "other" for Interface, timesyncd.conf, hostname and hosts
$ sudo chmod o+rw /etc/network/interfaces /etc/systemd/timesyncd.conf /etc/hostname /etc/hosts
$ #Create specific passwordless access file
$ sudo visudo -f /etc/sudoers.d/Morfeas_web_allow
```
### Copy the following to "/etc/sudoers.d/Morfeas_web_allow" file
```
Cmnd_Alias ALLOW_PASSWORDLESS = /bin/systemctl restart networking.service,\
                                /bin/systemctl restart systemd-timesyncd.service,\
                                /bin/systemctl restart Morfeas_system.service,\
                                /bin/hostname,\
                                /usr/sbin/poweroff,\
                                /sbin/reboot

www-data ALL = (ALL) NOPASSWD: ALLOW_PASSWORDLESS
```

