# Morfeas Web

# Building the UI project

Pre-requisites:
- [NodeJS](https://nodejs.org/en/) and npm

1. Clone the repository and change directory to `morfeas-ui/`
2. Install the required dependencies by running `npm install`
3. Build the Angular project by running `npm run build-prod` inside the `morfeas-ui/` folder. The transpiled files will be in `morfeas-ui/dist/morfeas-web/` which can be then copied to the web server.

Additional useful commands:

`npm run start`, run the local development server
`npm run test`, run the unit tests. (Requires Chrome to be installed, as its using HeadlessChrome.)
`npm run lint`, run the linter

You can also install [Angular CLI](https://cli.angular.io/). Instructions to use that can be found in the README in `morfeas-ui/README.md`

# Running the backend

Pre-requisites
- python3
- python3-venv
- python3-pip

Python3 comes preinstalled with Debian. 

Instructions
1. Create virtualenv. In the repository root, run the following command `python3 -m venv ./morfeas/venv/`
2. Activate the virtual env with `source morfeas/venv/bin/activate`
3. Install wheel `pip install wheel`
4. Install dependencies with `pip install -r morfeas/requirements.txt`
5. Setup the `config.json` using the `config.template.json` (`cp morfeas/config.template.json morfeas/config.json`, adjust the contents as necessary)
6. Run the dev backend with `python morfeas/app.py`
7. Exit virtual env by issuing command `deactivate`

# Deploying the project
Expects ramdisk to be found in /mnt/ramdisk/ and access rights to the folder to be correct

Update system (`apt update && apt upgrade -y`)

**Run all commands as root**

_Install required dependencies_

```
apt install apache2 apache2-dev python3-dev python3-venv -y
```


_Setup artifacts_

Download latest artifact from Gitlab CI jobs


```
cd <downloads folder>
unzip artifacts.zip
mkdir /var/www/morfeas_web
rm /var/www/index.html
```

_move artifacts to /var/www/_
```
mv ./morfeas-ui/dist/morfeas-web/* /var/www/html/
mv morfeas /var/www/morfeas_web/
```

```
nano /etc/apache2/sites-available/morfeas_web.conf
```

**Paste following content to /etc/apache2/sites-available/morfeas_web.conf**: 
```
<VirtualHost *:80>
    ServerName localhost
    ErrorLog ${APACHE_LOG_DIR}/error.log
    CustomLog ${APACHE_LOG_DIR}/access.log combined

    WSGIDaemonProcess morfeas_web user=morfeas group=morfeas 
    WSGIScriptAlias /wsgi-scripts /var/www/morfeas_web/morfeas/morfeas.wsgi

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

Setup python virtual env
```
python3 -m venv /var/www/morfeas_web/morfeas/venv/
source /var/www/morfeas_web/morfeas/venv/bin/activate
pip install wheel
pip install mod-wsgi
cd /var/www/morfeas_web/morfeas 
pip install -r requirements.txt
```

Enable Apache modules and site
```
mod_wsgi-express module-config >> /etc/apache2/mods-available/wsgi.load
a2enmod wsgi
sudo a2dissite 000-default.conf
a2ensite morfeas_web.conf
systemctl restart apache2
```
