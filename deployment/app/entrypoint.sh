#!/bin/sh
set -eu

umask 077

if [ -n "${PROJEQTOR_DB_PASSWORD_FILE:-}" ]; then
  if [ ! -r "$PROJEQTOR_DB_PASSWORD_FILE" ]; then
    echo "Database password file is not readable" >&2
    exit 64
  fi
  PROJEQTOR_DB_PASSWORD=$(cat "$PROJEQTOR_DB_PASSWORD_FILE")
  export PROJEQTOR_DB_PASSWORD
fi
case "${PROJEQTOR_MCP_TRUSTED_IP:-}" in
  ''|*[!0-9.]*) echo "Trusted MCP IPv4 address is invalid" >&2; exit 64 ;;
esac
sed "s/@PROJEQTOR_MCP_TRUSTED_IP@/${PROJEQTOR_MCP_TRUSTED_IP}/g" \
  /etc/apache2/conf-available/projeqtor-api.conf \
  > /etc/apache2/conf-available/projeqtor-api.conf.new
mv /etc/apache2/conf-available/projeqtor-api.conf.new /etc/apache2/conf-available/projeqtor-api.conf

mkdir -p \
  /var/lib/projeqtor/config \
  /var/lib/projeqtor/attachments \
  /var/lib/projeqtor/documents \
  /var/lib/projeqtor/logs \
  /var/lib/projeqtor/reports \
  /var/lib/projeqtor/mcp-jobs \
  /var/lib/projeqtor/mcp-uploads \
  /var/www/html/cache

if [ ! -f /var/lib/projeqtor/config/parameters.php ]; then
  {
    printf '%s\n' '<?php'
    printf '%s\n' "\$paramDbType='pgsql';"
    printf '%s\n' "\$paramDbHost='${PROJEQTOR_DB_HOST}';"
    printf '%s\n' "\$paramDbPort='${PROJEQTOR_DB_PORT}';"
    printf '%s\n' "\$paramDbUser='${PROJEQTOR_DB_USER}';"
    printf '%s\n' "\$paramDbPassword='${PROJEQTOR_DB_PASSWORD}';"
    printf '%s\n' "\$paramDbName='${PROJEQTOR_DB_NAME}';"
    printf '%s\n' "\$paramDbPrefix='';"
    printf '%s\n' "\$paramDbDisplayName='${PROJEQTOR_DISPLAY_NAME}';"
    printf '%s\n' "\$paramDefaultLocale='en';"
    printf '%s\n' "\$paramDefaultTimezone='${PROJEQTOR_TIMEZONE}';"
    printf '%s\n' "\$paramAttachmentDirectory='/var/lib/projeqtor/attachments/';"
    printf '%s\n' "\$documentRoot='/var/lib/projeqtor/documents/';"
    printf '%s\n' "\$paramReportTempDirectory='/var/lib/projeqtor/reports/';"
    printf '%s\n' "\$logFile='/var/lib/projeqtor/logs/projeqtor_\${date}.log';"
    printf '%s\n' "\$paramMemoryLimitForPDF='512';"
  } > /var/lib/projeqtor/config/parameters.php
fi

chown -R www-data:www-data /var/lib/projeqtor /var/www/html/cache
chmod 0700 /var/lib/projeqtor/config
chmod 0600 /var/lib/projeqtor/config/parameters.php

exec "$@"
