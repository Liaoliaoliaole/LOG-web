#!/bin/bash
mode_install()
{

	sudo usermod -a -G morfeas www-data &&
	sudo mkdir -p /mnt/ramdisk/Morfeas_Loggers &&
	sudo chgrp morfeas /mnt/ramdisk/Morfeas_Loggers &&
	sudo chmod 2775 /mnt/ramdisk/Morfeas_Loggers &&
	sudo touch /mnt/ramdisk/Morfeas_Loggers/morfeas_web_api.log &&
	sudo chown www-data:morfeas /mnt/ramdisk/Morfeas_Loggers/morfeas_web_api.log &&
	sudo chmod 664 /mnt/ramdisk/Morfeas_Loggers/morfeas_web_api.log &&
	sudo cp "$(dirname "$0")/sudoers/Morfeas_update_allow" /etc/sudoers.d/Morfeas_update_allow &&
	sudo cp "$(dirname "$0")/sudoers/Morfeas_web_allow" /etc/sudoers.d/Morfeas_web_allow &&
	sudo cp "$(dirname "$0")/sudoers/Morfeas_web_journal_allow" /etc/sudoers.d/Morfeas_web_journal_allow &&
	sudo chmod 440 /etc/sudoers.d/Morfeas_update_allow /etc/sudoers.d/Morfeas_web_allow /etc/sudoers.d/Morfeas_web_journal_allow &&
	sudo visudo -cf /etc/sudoers.d/Morfeas_update_allow &&
	sudo visudo -cf /etc/sudoers.d/Morfeas_web_allow &&
	sudo visudo -cf /etc/sudoers.d/Morfeas_web_journal_allow &&
	sudo cp "$(dirname "$0")/logrotate/morfeas-loggers" /etc/logrotate.d/morfeas-loggers &&
	sudo chmod 644 /etc/logrotate.d/morfeas-loggers &&
	sudo chmod g+w /etc/network/interfaces.d \
	               /etc/network/interfaces.d/*\
		       /etc/systemd/timesyncd.conf \
				   /etc/hostname \
				   /etc/hosts&&
	sudo service apache2 restart
}
mode_uninstall()
{
	if groups www-data | grep -q '\b root \b'; then
		sudo gpasswd -d www-data root
	fi
	if groups www-data | grep -q '\b sudo \b'; then
		sudo gpasswd -d www-data sudo
	fi
	if groups www-data | grep -q '\b morfeas \b'; then
		sudo gpasswd -d www-data morfeas
		echo "Removed www-data from morfeas group"
	fi
	sudo rm -f /etc/sudoers.d/Morfeas_update_allow /etc/sudoers.d/Morfeas_web_allow /etc/sudoers.d/Morfeas_web_journal_allow
	sudo rm -f /etc/logrotate.d/morfeas-loggers
	sudo chmod g-w /etc/network/interfaces.d \
		       /etc/network/interfaces.d/*\
	               /etc/systemd/timesyncd.conf \
				   /etc/hostname \
				   /etc/hosts&&
	sudo service apache2 restart
}
echo 'Welcome to Morfeas WEB Installation script'
PS3='Select: '
modes=("Install" "Uninstall" "Quit")
select fav in "${modes[@]}"; do
	case $fav in
		"Install")
			mode_install
			break
            ;;
        "Uninstall")
        	mode_uninstall
        	break
			;;
		"Quit")
			exit
			;;
		*) echo "invalid option $REPLY";;
	esac
done
