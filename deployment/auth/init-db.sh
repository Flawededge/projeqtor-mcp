#!/bin/sh
set -eu

db_admin_password="$(tr -d '\r\n' < /run/secrets/db_password)"
keycloak_password="$(tr -d '\r\n' < /run/secrets/keycloak_db_password)"
if [ -z "$db_admin_password" ] || [ -z "$keycloak_password" ]; then
  echo "Database password files must not be empty" >&2
  exit 1
fi
export PGPASSWORD="$db_admin_password"

psql -v ON_ERROR_STOP=1 -h db -U projeqtor -d postgres -v keycloak_password="$keycloak_password" <<'SQL'
SELECT format('CREATE ROLE keycloak LOGIN PASSWORD %L', :'keycloak_password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'keycloak')
\gexec
SELECT format('ALTER ROLE keycloak WITH LOGIN PASSWORD %L', :'keycloak_password')
\gexec
SELECT 'CREATE DATABASE keycloak OWNER keycloak'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'keycloak')
\gexec
REVOKE ALL ON DATABASE keycloak FROM PUBLIC;
GRANT ALL PRIVILEGES ON DATABASE keycloak TO keycloak;
SQL
