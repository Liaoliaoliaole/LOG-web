#!/bin/bash

# Helper function for status messages
print_status() {
    echo
    echo "======================================"
    echo "$1"
    echo "======================================"
    echo
}

# ---------------------------
# Update and build LOGCore
# ---------------------------
MORFEAS_CORE_DIR="/opt/Morfeas_project/Morfeas_core"

print_status "Updating LOG Core Source..."

if [ -d "$MORFEAS_CORE_DIR" ]; then
    cd "$MORFEAS_CORE_DIR" || { echo "Error: Failed to access $MORFEAS_CORE_DIR"; exit 1; }
    git pull || { echo "Error: Failed to update Morfeas Core source."; exit 1; }

    print_status "Compiling Morfeas Core..."

    make clean && make -j$(nproc) || { echo "Error: Compilation failed."; exit 1; }

    print_status "Installing Morfeas Core..."

    sudo make install || { echo "Error: Installation failed."; exit 1; }

    print_status "Restarting Morfeas Daemon..."

    sudo service Morfeas_system restart || { echo "Error: Failed to restart Morfeas daemon."; exit 1; }
else
    echo "Error: Directory $MORFEAS_CORE_DIR does not exist."
    exit 1
fi

# ---------------------------
# Update Morfeas Web
# ---------------------------
MORFEAS_WEB_DIR="/var/www/html/morfeas_web"

print_status "Updating Morfeas Web Source..."

if [ -d "$MORFEAS_WEB_DIR" ]; then
    cd "$MORFEAS_WEB_DIR" || { echo "Error: Failed to access $MORFEAS_WEB_DIR"; exit 1; }
    git pull || { echo "Error: Failed to update Morfeas Web source."; exit 1; }
else
    echo "Error: Directory $MORFEAS_WEB_DIR does not exist."
    exit 1
fi

print_status "Update Complete. Morfeas system and web are now up to date."

