#!/bin/bash

# ========================================
# Morfeas Update Script
# ========================================

# Helper function for status messages
print_status() {
    echo -e "\n======================================"
    echo " $1"
    echo "======================================"
}

MORFEAS_WEB_DIR="/var/www/html/morfeas_web"
MORFEAS_CORE_DIR="/opt/Morfeas_project/Morfeas_core"

# ---------------------------
# Check Update
# ---------------------------

if [ "$1" == "--check-only" ]; then
    cd "$MORFEAS_WEB_DIR" || { echo "❌ Error: Cannot access $MORFEAS_WEB_DIR"; exit 1; }

    git fetch origin

    CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
    echo "Current Branch: $CURRENT_BRANCH"

    LOCAL_COMMIT=$(git rev-parse HEAD)
    REMOTE_COMMIT=$(git rev-parse "origin/$CURRENT_BRANCH")

    echo "Local Commit: $LOCAL_COMMIT"
    echo "Remote Commit: $REMOTE_COMMIT"

    if [ "$LOCAL_COMMIT" != "$REMOTE_COMMIT" ]; then
        echo "✅ Update available. Your branch is behind."
        exit 100  # Custom exit code to indicate update is available
    else
        echo "ℹ️ System is already up-to-date."
        exit 0  # No update needed
    fi
fi


# ---------------------------
# Update and build LOGCore
# ---------------------------
print_status "Updating LOG Core Source..."

if [ -d "$MORFEAS_CORE_DIR" ]; then
    cd "$MORFEAS_CORE_DIR" || { echo "❌ Error: Cannot access $MORFEAS_CORE_DIR"; exit 1; }
    git pull || { echo "❌ Error: Failed to update Morfeas Core source."; exit 1; }

    print_status "Compiling Morfeas Core..."
    make clean && make -j"$(nproc)" || { echo "❌ Error: Compilation failed."; exit 1; }

    print_status "Installing Morfeas Core..."
    sudo make install || { echo "❌ Error: Installation failed."; exit 1; }

    print_status "Restarting Morfeas Daemon..."
    sudo systemctl restart Morfeas_system || { echo "❌ Error: Failed to restart Morfeas daemon."; exit 1; }

else
    echo "❌ Error: Directory $MORFEAS_CORE_DIR does not exist."
    exit 1
fi

# ---------------------------
# Update Morfeas Web
# ---------------------------

print_status "Updating Morfeas Web Source..."

if [ -d "$MORFEAS_WEB_DIR" ]; then
    cd "$MORFEAS_WEB_DIR" || { echo "❌ Error: Cannot access $MORFEAS_WEB_DIR"; exit 1; }
    git pull || { echo "❌ Error: Failed to update Morfeas Web source."; exit 1; }
else
    echo "❌ Error: Directory $MORFEAS_WEB_DIR does not exist."
    exit 1
fi

# ---------------------------
# Restart Apache2 (Web server)
# ---------------------------
print_status "Restarting Apache2 Web Server..."
sudo systemctl restart apache2 || { echo "❌ Error: Failed to restart Apache2."; exit 1; }

# ---------------------------
# Update Complete
# ---------------------------
print_status "✅ Update Complete. Morfeas system and web are now up to date."
