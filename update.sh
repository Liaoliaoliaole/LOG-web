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
# Check Update Mode
# ---------------------------

if [ "$1" == "--check-only" ]; then
    cd "$MORFEAS_WEB_DIR" || { echo "Error: Cannot access $MORFEAS_WEB_DIR"; exit 1; }

    git fetch origin

    CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
    echo "Current Branch: $CURRENT_BRANCH"

    LOCAL_COMMIT=$(git rev-parse HEAD)
    REMOTE_COMMIT=$(git rev-parse "origin/$CURRENT_BRANCH")

    echo "Local Commit: $LOCAL_COMMIT"
    echo "Remote Commit: $REMOTE_COMMIT"

    if [ "$LOCAL_COMMIT" != "$REMOTE_COMMIT" ]; then
        echo "Update available."
        exit 100  # Custom exit code to indicate update is available
    else
        echo "System is already up-to-date."
        exit 0  # No update needed
    fi
fi

# ---------------------------
# Update WITHOUT Restarting Yet
# ---------------------------
print_status "Updating Core and Web..."

# Core
if [ -d "$MORFEAS_CORE_DIR" ]; then
    cd "$MORFEAS_CORE_DIR" || exit 1
    git pull || exit 1
    make clean && make -j"$(nproc)" || exit 1
    sudo make install || exit 1
fi

# Web
cd "$MORFEAS_WEB_DIR" || exit 1
git pull || exit 1

print_status "✅ Update files done."

# ---------------------------
# Delay Restart in Background
# ---------------------------
(
    sleep 5
    echo "Restarting services..."
    sudo systemctl restart Morfeas_system
    sudo systemctl restart apache2
) &

print_status "✅ Scheduled service restart."
exit 0

