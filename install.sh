#!/bin/bash
set -euo pipefail

# Compatibility entry point for manual first installation.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [ "$(id -u)" -ne 0 ]; then
    exec sudo "$SCRIPT_DIR/deploy/post_deploy.sh"
fi
exec "$SCRIPT_DIR/deploy/post_deploy.sh"
