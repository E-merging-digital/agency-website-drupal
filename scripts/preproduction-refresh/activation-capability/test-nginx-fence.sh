#!/usr/bin/env bash
set -Eeuo pipefail
base="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
tmp="$(mktemp -d)"
cleanup() {
  set +e
  [[ -f "$tmp/nginx.pid" ]] && nginx -p "$tmp" -c nginx.conf -s quit >/dev/null 2>&1
  rm -rf "$tmp"
}
trap cleanup EXIT HUP INT TERM
mkdir -p "$tmp/logs"
chmod 0711 "$tmp"
cp /etc/nginx/fastcgi_params "$tmp/fastcgi_params"
marker="$tmp/refresh-maintenance.flag"
sed "s|/var/lib/agency-preprod-refresh/refresh-maintenance.flag|$marker|g" \
  "$base/nginx/agency-preprod-refresh-fence.conf" > "$tmp/fence.conf"
cat > "$tmp/nginx.conf" <<EOF_CONF
pid $tmp/nginx.pid;
error_log $tmp/error.log notice;
events { worker_connections 32; }
http {
  access_log off;
  server {
    listen 127.0.0.1:18088;
    include $tmp/fence.conf;
    location / { return 204; }
  }
}
EOF_CONF
printf '%s\n' 'nginx_public_syntax_check=START'
nginx -p "$tmp" -c nginx.conf -t
printf '%s\n' 'nginx_public_syntax_check=PASS'
nginx -p "$tmp" -c nginx.conf
for _ in {1..40}; do
  code="$(curl -sS -o /dev/null -w '%{http_code}' http://127.0.0.1:18088/ || true)"
  [[ "$code" == 204 ]] && break
  sleep 0.05
done
printf 'public_open_http_code=%s\n' "$code"
[[ "$code" == 204 ]]
: > "$marker"
code="$(curl -sS -o /dev/null -w '%{http_code}' http://127.0.0.1:18088/)"
printf 'public_fenced_http_code=%s\n' "$code"
[[ "$code" == 503 ]]
rm -f "$marker"
code="$(curl -sS -o /dev/null -w '%{http_code}' http://127.0.0.1:18088/)"
printf 'public_reopened_http_code=%s\n' "$code"
[[ "$code" == 204 ]]
# Syntax-check the actual loopback listener source by using a temporary current
# web root and the system fastcgi_params. The PHP-FPM socket need not exist for -t.
mkdir -p "$tmp/current/web"
sed \
  -e "s|/var/www/agency-preprod/current/web|$tmp/current/web|g" \
  -e 's|include fastcgi_params;|include /etc/nginx/fastcgi_params;|g' \
  "$base/nginx/agency-preprod-refresh-internal-readiness.conf" > "$tmp/internal.conf"
cat > "$tmp/internal-nginx.conf" <<EOF_CONF
pid $tmp/internal.pid;
error_log $tmp/internal-error.log notice;
events { worker_connections 32; }
http {
  access_log off;
  include $tmp/internal.conf;
}
EOF_CONF
printf '%s\n' 'nginx_internal_syntax_check=START'
nginx -p "$tmp" -c internal-nginx.conf -t
printf '%s\n' 'nginx_internal_syntax_check=PASS'
printf '%s\n' \
  'public_open_behavior=204_PASS' \
  'public_fenced_behavior=503_PASS' \
  'fence_reopen_after_marker_removal=204_PASS' \
  'internal_health_ready_nginx_syntax=PASS' \
  'public_bypass=NONE'
