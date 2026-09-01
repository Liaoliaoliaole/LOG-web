#!/bin/bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
WEB_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

php -r '
require $argv[1];
if (backend_core_dtd_dir() === null) {
    fwrite(STDERR, "Core checkout or required Morfeas.dtd could not be resolved uniquely. Set MORFEAS_CORE_SRC_DIR.\n");
    exit(1);
}
' "$WEB_ROOT/backend/core/paths.php"

for script in "$REPO_ROOT"/deploy/*.sh "$REPO_ROOT"/cron/*.sh "$REPO_ROOT"/install.sh \
    "$REPO_ROOT"/deploy/morfeas-network-files; do
    bash -n "$script"
done

for test_file in "$SCRIPT_DIR"/php/*Test.php; do
    php "$test_file"
done

node --test "$SCRIPT_DIR"/js/*.test.js
