#!/bin/sh
set -eu
umask 077

root=${1:-$(pwd)}
secrets="$root/secrets"
env_file="$root/.env"
example="$root/.env.example"

command -v openssl >/dev/null 2>&1 || { echo "openssl is required" >&2; exit 64; }
command -v sha256sum >/dev/null 2>&1 || { echo "sha256sum is required" >&2; exit 64; }
test -f "$example" || { echo ".env.example is missing from $root" >&2; exit 66; }

if [ -e "$secrets" ] || [ -e "$env_file" ]; then
  echo "Refusing to overwrite an existing .env or secrets directory" >&2
  exit 73
fi

mkdir -p "$secrets"
openssl rand -base64 48 > "$secrets/db-password"
openssl rand -base64 48 > "$secrets/admin-password"
openssl rand -base64 48 > "$secrets/admin-mcp-token"
openssl rand -base64 48 > "$secrets/api-password"
openssl rand -hex 64 > "$secrets/mcp-signing-key"
openssl rand -hex 64 > "$secrets/mcp-cursor-key"
openssl rand -base64 48 > "$secrets/keycloak-db-password"
openssl rand -base64 48 > "$secrets/keycloak-admin-password"
openssl rand -base64 48 > "$secrets/claude-oauth-client-secret"
: > "$secrets/entra-client-secret"

token_digest=$(tr -d '\r\n' < "$secrets/admin-mcp-token" | sha256sum | awk '{print $1}')
printf '{\n  "version": 1,\n  "users": [\n    {"username": "admin", "tokenSha256": "%s"}\n  ]\n}\n' "$token_digest" > "$secrets/mcp-users.json"
api_hash=$(openssl passwd -apr1 -in "$secrets/api-password")
printf 'api:%s\n' "$api_hash" > "$secrets/api.htpasswd"
cp "$example" "$env_file"
chmod 0700 "$secrets"
chmod 0600 "$env_file" "$secrets"/*
# Compose implements file-backed secrets as bind mounts. Files consumed by
# unprivileged container users must be readable after mounting; the root-only
# parent directory still prevents host users from traversing to them.
chmod 0444 "$secrets/db-password" "$secrets/admin-password" "$secrets/api.htpasswd" "$secrets/mcp-signing-key" "$secrets/mcp-cursor-key" "$secrets/mcp-users.json"
chmod 0444 "$secrets/keycloak-db-password" "$secrets/keycloak-admin-password" "$secrets/claude-oauth-client-secret" "$secrets/entra-client-secret"

printf '%s\n' "Fresh hosting configuration created in $root"
printf '%s\n' "Administrator password: $secrets/admin-password"
printf '%s\n' "Administrator MCP token: $secrets/admin-mcp-token"
printf '%s\n' "OAuth is disabled until Auth0 is configured; follow docs/OAUTH.md."
printf '%s\n' "Keycloak secret files are dormant rollback materials; Auth0 uses a public client."
printf '%s\n' "Keep these files private; their values are intentionally not displayed."
