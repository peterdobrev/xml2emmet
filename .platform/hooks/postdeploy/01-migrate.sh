#!/usr/bin/env bash
set -euo pipefail
# EB postdeploy cwd is the deployed app dir (/var/app/current).
# Environment properties set in the EB console are exported here.
echo "Running database migrations against ${XML2EMMET_DB_HOST}:${XML2EMMET_DB_PORT}/${XML2EMMET_DB_NAME}"
php bin/migrate.php
