# LOG Web - Installation and Deploy Guide

## Prerequisite

### Install Required Dependencies

``` bash
sudo apt install -y apache2 php libapache2-mod-php php-dev php-xml php-mbstring
```

### Create Required Directories

``` bash
sudo mkdir -p /mnt/ramdisk/Morfeas_Loggers/
sudo chmod -R 775 /mnt/ramdisk
sudo usermod -aG morfeas www-data
sudo chmod 775 /mnt/ramdisk/Morfeas_Loggers
```

### Clone the Source Code

Fetch the latest version of the LOG Web application along with its submodules:

``` bash
git clone https://git.devops.wartsila.com/scm/log/log_web.git
cd log_web
git submodule update --init --recursive --remote --merge
```

### Compile and Install the pecl-dbus Submodule

Compilation must be in LOG device.

``` bash
./build_submodule.sh
```

## Deploy the Application on LOG Device

### Copy the Web Application to Apache’s Standard Directory

``` bash
cp -r log_web /var/www/html/
```

Set the Correct Ownership and Permissions.
Replace `<user in LOG device>` with **`<LOG_USER>`**

``` bash
sudo chown -R <LOG_USER>:<LOG_USER> /var/www/html
sudo chmod -R 755 /var/www/html
```

## Configure the Environment File

The application requires an environment configuration file. Copy and edit it as needed:

``` bash
sudo cp /var/www/html/log_web/Morfeas_WEB/Morfeas_env.php.template /var/www/html/log_web/Morfeas_WEB/Morfeas_env.php
sudo nano /var/www/html/log_web/Morfeas_WEB/Morfeas_env.php
```

Modify the file with appropriate values.

### Configure Apache Virtual Host

Copy the provided Apache configuration file:

``` bash
sudo cp log_web/apache_site_conf/Morfeas_web.conf /etc/apache2/sites-available/Morfeas_web.conf
```

Edit the configuration if needed:

``` bash
sudo nano /etc/apache2/sites-available/Morfeas_web.conf
```

### Setup passwordless operation for web server using scripts in sudoers directory

``` bash
sudo visudo -f /etc/sudoers.d/Morfeas_web_update_allow
```

``` bash
sudo visudo -f /etc/sudoers.d/Morfeas_web_allow
```

### Enable and Restart Apache

Disable the default Apache site and Enable the new site configuration.

``` bash
sudo a2dissite 000-default.conf
sudo a2ensite Morfeas_web.conf
```

Reload and restart the Apache service:

``` bash
sudo systemctl reload apache2
sudo systemctl restart apache2
sudo systemctl status apache2
```

### Access the Web Application

Once the setup is complete, open a browser and go to:

```text
http://localhost
```

 or, if accessing remotely:

```text
http://<LOG_DEVICE_IP>
```

---
## **Original README from Morfeas Web Repository**
> *This section contains the original documentation from the Morfeas Web repository, kept for reference.*
---


<div align="center"> <img src="./Morfeas_WEB/art/Morfeas_logo_yellow.png" width="150"> </div>

# Morfeas Web
This is the Repository for the Morfeas Web, sub-project of the Morfeas Project.

### Requirements
For compilation and usage of this project the following dependencies required.
* [Morfeas_core](https://gitlab.com/fantomsam/morfeas_project) - The core of the Morfeas project.
* [Apache2](https://www.apache.org/) - The Apache WEB server.
* [PHP](https://www.php.net/) - The PHP scripting language.
* [libapache2-mod-php](https://packages.debian.org/stretch/libapache2-mod-php) - The PHP module for the Apache 2 webserver.
* [php-dev](https://packages.debian.org/sid/php/php-dev) - Collection of Headers and other PHP needed for compiling additional modules.
* [php-xml](https://sourceforge.net/projects/xmlphp) -  A class written in php to create, edit, modify and read XML documents.
* [php-mbstring](https://packages.debian.org/stretch/php-mbstring) - PHP module for manipulation of Multibyte String (UNICODE, etc).

The Morfeas_core must spit the logstats at the `/mnt/ramdisk`. Where is mounted a dedicated `tmpfs`.

### Get the Source
```
$ # Clone the project's source code
$ git clone https://gitlab.com/fantomsam/morfeas_web Morfeas_web
$ cd Morfeas_web
$ # Get Source of the submodules
$ git submodule update --init --recursive --remote --merge
```
### Compilation and installation of the submodules
#### pecl-dbus
```
$ cd pecl-dbus
$ phpize
$ ./configure
$ make -j$(nproc)
$ sudo make install
```
To enable php-dbus:
Add `extension=dbus` at the extensions section of php.ini file for apache. Usually located at `/etc/php/X.XX/apache2/`

<!-- ### Installation of the Morfeas-Web Project
```
$ sudo chmod +x ./install.sh
$ ./install.sh
``` -->
## Documentation
The documentation of the project located at [Morfeas_WEB_Docs](./Docs/Morfeas_WEB_Docs).

# License
The subproject license under [AGPLv3](./Morfeas_WEB/LICENSE) or later
