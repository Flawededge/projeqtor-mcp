#!/bin/bash
set -euo pipefail

read_secret() {
  local variable="$1"
  local path="$2"
  if [[ ! -r "$path" ]]; then
    echo "$path is required and must be readable" >&2
    exit 1
  fi
  local value
  value="$(tr -d '\r\n' < "$path")"
  if [[ -z "$value" ]]; then
    echo "$path must not be empty" >&2
    exit 1
  fi
  printf -v "$variable" '%s' "$value"
  export "$variable"
}

: "${ENTRA_TENANT_ID:?ENTRA_TENANT_ID is required}"
: "${ENTRA_CLIENT_ID:?ENTRA_CLIENT_ID is required}"
: "${CLAUDE_OAUTH_REDIRECT_URI:?CLAUDE_OAUTH_REDIRECT_URI is required}"

read_secret KC_DB_PASSWORD /run/secrets/keycloak_db_password
read_secret KC_BOOTSTRAP_ADMIN_PASSWORD /run/secrets/keycloak_admin_password
read_secret ENTRA_CLIENT_SECRET /run/secrets/entra_client_secret
read_secret CLAUDE_OAUTH_CLIENT_SECRET /run/secrets/claude_oauth_client_secret

rm -f /tmp/oauth-profile-ready /tmp/kcadm.config
/opt/keycloak/bin/kc.sh start --import-realm &
keycloak_pid=$!

stop_keycloak() {
  kill -TERM "$keycloak_pid" 2>/dev/null || true
  wait "$keycloak_pid" 2>/dev/null || true
}
trap stop_keycloak TERM INT

keycloak_ready() {
  local status=''
  if ! { exec 3<>/dev/tcp/127.0.0.1/9000; } 2>/dev/null; then return 1; fi
  printf 'GET /projeqtor-auth/health/ready HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n' >&3
  IFS= read -r status <&3 || true
  exec 3<&-
  exec 3>&-
  [[ "$status" == *" 200 "* ]]
}

ready=false
for ((_attempt=1; _attempt<=90; _attempt++)); do
  if ! kill -0 "$keycloak_pid" 2>/dev/null; then
    wait "$keycloak_pid"
    exit $?
  fi
  if keycloak_ready; then
    ready=true
    break
  fi
  sleep 2
done
if [[ "$ready" != true ]]; then
  echo "Keycloak did not become ready for OAuth profile configuration" >&2
  stop_keycloak
  exit 1
fi

/opt/keycloak/bin/kcadm.sh config credentials --config /tmp/kcadm.config \
  --server http://127.0.0.1:8080/projeqtor-auth --realm master \
  --user "$KC_BOOTSTRAP_ADMIN_USERNAME" --password "$KC_BOOTSTRAP_ADMIN_PASSWORD" >/dev/null
/opt/keycloak/bin/kcadm.sh update users/profile --config /tmp/kcadm.config \
  -r projeqtor -f /opt/keycloak/conf/projeqtor-user-profile.json >/dev/null
rm -f /tmp/kcadm.config
: > /tmp/oauth-profile-ready

wait "$keycloak_pid"
