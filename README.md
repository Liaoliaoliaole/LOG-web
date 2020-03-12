# Morfeas Web

## Deploying the project
**Expects ramdisk to be found in /mnt/ramdisk/ and access rights to the folder to be correct**
<!--
## Pre-requisites:
- [NodeJS](https://nodejs.org/en/)
- [npm](https://www.npmjs.com/)
-->
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

<!--
_Setup artifacts_

Download latest artifact from Gitlab CI jobs

```
cd <downloads folder>
unzip artifacts.zip
mkdir /var/www/morfeas_web
rm /var/www/index.html
```

_move artifacts to /var/www/_
-->
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
<!-- #Commented By Sam
## Add www-data (apache user) to sudoers in order to be able to restart the networking 
```
$ sudo nano /etc/sudoers/
$ #Add the following under user privilege specification
$ # www-data  ALL=(ALL:ALL) NOPASSWD: ALL
$ #This can be later changed to something more suitable so apache user doesnt have full rights to the system
```

## Give www-data (apache user) rights to read and write to /etc/network/interfaces
-->

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
                                /sbin/reboot

www-data ALL = (ALL) NOPASSWD: ALLOW_PASSWORDLESS
```

# Usefull:
## Running the backend
_Pre-requisites:_
- python3
- python3-venv
- python3-pip

***Python3 comes preinstalled with most GNU/Linux Distros.***

Instructions
1. Create virtualenv. In the repository root, run the following command `python3 -m venv ./morfeas/venv/`
2. Activate the virtual env with `source morfeas/venv/bin/activate`
3. Install wheel `pip3 install wheel`
4. Install dependencies with `pip3 install -r morfeas/requirements.txt`
5. Setup the `config.json` using the `config.template.json` (`cp morfeas/config.template.json morfeas/config.json`, adjust the contents as necessary)
6. Run the dev backend with `python3 morfeas/app.py`
7. Exit virtual env by issuing command `deactivate`

#### Additional useful commands:

`npm run start`, run the local development server
`npm run test`, run the unit tests. (Requires Chromium to be installed, as its using HeadlessChrome.)
`npm run lint`, run the linter

You can also install [Angular CLI](https://cli.angular.io/). Instructions to use that can be found in the README in `morfeas-ui/README.md`

