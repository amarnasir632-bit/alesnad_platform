#!/usr/bin/env bash
set -euo pipefail

port="${PORT:-10000}"
if [[ ! "$port" =~ ^[0-9]{1,5}$ ]] || (( port < 1 || port > 65535 )); then
  echo "PORT must be a valid TCP port." >&2
  exit 1
fi
sed -i -E "s/:10000>/:${port}>/g" /etc/apache2/sites-available/000-default.conf
sed -i -E "s/^Listen 80$/Listen ${port}/" /etc/apache2/ports.conf

# Free Render has no pre-deploy command. The migration runner serializes concurrent starts.
php database/migrate.php
exec apache2-foreground
