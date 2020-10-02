#!/bin/bash
mode_install()
{

	sudo usermod -a -G root,sudo www-data &&
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
		echo "Remove assdasd"
	fi
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
