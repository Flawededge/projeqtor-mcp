#!/bin/bash
set -euo pipefail

[[ -f /tmp/oauth-profile-ready ]]

exec 3<>/dev/tcp/127.0.0.1/9000
printf 'GET /projeqtor-auth/health/ready HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n' >&3
status=''
IFS= read -r status <&3 || true
exec 3<&-
exec 3>&-
[[ "$status" == *" 200 "* ]]
