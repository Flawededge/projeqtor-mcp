#!/bin/sh
set -eu

umask 077
mkdir -p /var/lib/projeqtor/mcp-jobs /var/lib/projeqtor/mcp-uploads /var/www/html/cache
cd /var/www/html/mcp-api
exec php /usr/local/lib/projeqtor/mcp-worker.php
