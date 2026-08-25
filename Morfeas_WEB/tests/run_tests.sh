#!/bin/bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

for script in "$REPO_ROOT"/deploy/*.sh "$REPO_ROOT"/cron/*.sh "$REPO_ROOT"/install.sh \
    "$REPO_ROOT"/deploy/morfeas-network-files; do
    bash -n "$script"
done

for test_file in "$SCRIPT_DIR"/php/*Test.php; do
    php "$test_file"
done

node --test "$SCRIPT_DIR"/js/*.test.js
