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
# Check Only Mode
# ---------------------------
if [ "$1" == "--check-only" ]; then
    web_update_needed=0
    core_update_needed=0

    # -- Check Web --
    if [ -d "$MORFEAS_WEB_DIR" ]; then
        cd "$MORFEAS_WEB_DIR" || { echo "Cannot access $MORFEAS_WEB_DIR"; exit 1; }
        git fetch origin
        WEB_CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
        WEB_LOCAL_COMMIT=$(git rev-parse HEAD)
        WEB_REMOTE_COMMIT=$(git rev-parse "origin/$WEB_CURRENT_BRANCH")
        echo "Web Branch: $WEB_CURRENT_BRANCH"
        echo "Web Local: $WEB_LOCAL_COMMIT"
        echo "Web Remote: $WEB_REMOTE_COMMIT"
        if [ "$WEB_LOCAL_COMMIT" != "$WEB_REMOTE_COMMIT" ]; then
            web_update_needed=1
        fi
    fi

    # -- Check Core --
    if [ -d "$MORFEAS_CORE_DIR" ]; then
        cd "$MORFEAS_CORE_DIR" || { echo "Cannot access $MORFEAS_CORE_DIR"; exit 1; }
        git fetch origin
        CORE_CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
        CORE_LOCAL_COMMIT=$(git rev-parse HEAD)
        CORE_REMOTE_COMMIT=$(git rev-parse "origin/$CORE_CURRENT_BRANCH")
        echo "Core Branch: $CORE_CURRENT_BRANCH"
        echo "Core Local: $CORE_LOCAL_COMMIT"
        echo "Core Remote: $CORE_REMOTE_COMMIT"
        if [ "$CORE_LOCAL_COMMIT" != "$CORE_REMOTE_COMMIT" ]; then
            core_update_needed=1
        fi
    fi

    # --- Final Decision ---
    if [ $web_update_needed -eq 1 ] || [ $core_update_needed -eq 1 ]; then
        echo "Update available (Core or Web)."
        exit 100
    else
        echo "System is already up-to-date."
        exit 0
    fi
fi


# ---------------------------
# Update WITHOUT Restarting Yet
# ---------------------------
print_status "Starting full update process..."

# Core
if [ -d "$MORFEAS_CORE_DIR" ]; then
    cd "$MORFEAS_CORE_DIR" || exit 1
    git fetch origin
    CORE_CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
    CORE_LOCAL_COMMIT=$(git rev-parse HEAD)
    CORE_REMOTE_COMMIT=$(git rev-parse "origin/$CORE_CURRENT_BRANCH")
    if [ "$CORE_LOCAL_COMMIT" != "$CORE_REMOTE_COMMIT" ]; then
        print_status "Updating Morfeas Core..."
        git pull || exit 1
        make clean && make -j"$(nproc)" || exit 1
        sudo make install || exit 1
        core_updated=1
    else
        echo "Morfeas Core already up-to-date."
    fi
fi

# Web
if [ -d "$MORFEAS_WEB_DIR" ]; then
    cd "$MORFEAS_WEB_DIR" || exit 1
    git fetch origin
    WEB_CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
    WEB_LOCAL_COMMIT=$(git rev-parse HEAD)
    WEB_REMOTE_COMMIT=$(git rev-parse "origin/$WEB_CURRENT_BRANCH")
    if [ "$WEB_LOCAL_COMMIT" != "$WEB_REMOTE_COMMIT" ]; then
        print_status "Updating Morfeas Web..."
        git pull || exit 1
        web_updated=1
    else
        echo "Morfeas Web already up-to-date."
    fi
fi

print_status "Update files done."

# ---------------------------
# Delay Restart in Background
# ---------------------------
if [ $core_updated ] || [ $web_updated ]; then
    print_status "Scheduling service restart in background..."
    (
        sleep 5
        echo "Restarting Morfeas and Apache..."
        sudo systemctl restart Morfeas_system
        sudo systemctl restart apache2
    ) &
    print_status "Services will restart shortly."
else
    print_status "No restart needed. System was already up-to-date."
fi

exit 0

