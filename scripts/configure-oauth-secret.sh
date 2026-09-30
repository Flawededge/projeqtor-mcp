#!/bin/bash
set -euo pipefail
umask 077

root="${1:-$(pwd)}"
secrets="$root/secrets"
target="$secrets/entra-client-secret"

if [[ ! -d "$secrets" ]]; then
  echo "Run scripts/setup-host.sh first; $secrets does not exist" >&2
  exit 66
fi
if [[ ! -t 0 ]]; then
  echo "An interactive terminal is required so the secret is not exposed in arguments" >&2
  exit 64
fi

read -r -s -p "Microsoft Entra client secret: " first
printf '\n' >&2
read -r -s -p "Repeat Microsoft Entra client secret: " second
printf '\n' >&2
if [[ -z "$first" || "$first" != "$second" ]]; then
  echo "The non-empty secret values must match" >&2
  exit 65
fi

temporary="$(mktemp "$secrets/.entra-client-secret.XXXXXX")"
trap 'rm -f "$temporary"' EXIT
printf '%s\n' "$first" > "$temporary"
chmod 0444 "$temporary"
mv -f "$temporary" "$target"
trap - EXIT
unset first second
echo "Microsoft Entra client secret stored without displaying it."
