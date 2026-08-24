#!/usr/bin/env bash
set -Eeuo pipefail

# Idempotent Ubuntu 24.04 bootstrap for the Agency PREPROD host.
# Secrets are generated on-host unless an isolated PREPROD access password is
# supplied by the governed bootstrap workflow. No production secret is used.

if [[ "${EUID}" -ne 0 ]]; then
  echo "Run this bootstrap as root." >&2
  exit 2
fi

source /etc/os-release
if [[ "${ID:-}" != "ubuntu" || "${VERSION_CODENAME:-}" != "noble" ]]; then
  echo "Ubuntu 24.04 LTS (noble) is required." >&2
  exit 2
fi

PREPROD_FQDN="${PREPROD_FQDN:-preprod.emergingdigital.be}"
DEPLOY_USER="${DEPLOY_USER:-agency-preprod}"
PROJECT_ROOT="${PROJECT_ROOT:-/var/www/agency-preprod}"
DEPLOY_PUBLIC_KEY="${DEPLOY_PUBLIC_KEY:-}"
ENABLE_TLS="${ENABLE_TLS:-0}"
TLS_EMAIL="${TLS_EMAIL:-}"
DB_NAME="${DB_NAME:-agency_preprod}"
DB_USER="${DB_USER:-agency_preprod}"
REQUESTED_ACCESS_PASSWORD="${ACCESS_PASSWORD:-}"
CREDENTIAL_FILE="/root/agency-preprod-bootstrap-credentials.txt"

[[ "$PREPROD_FQDN" =~ ^[A-Za-z0-9.-]+$ ]] || { echo "Invalid PREPROD_FQDN." >&2; exit 2; }
[[ "$DEPLOY_USER" =~ ^[a-z_][a-z0-9_-]*$ ]] || { echo "Invalid DEPLOY_USER." >&2; exit 2; }
[[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || { echo "Invalid DB_NAME." >&2; exit 2; }
[[ "$DB_USER" =~ ^[A-Za-z0-9_]+$ ]] || { echo "Invalid DB_USER." >&2; exit 2; }
if [[ -z "$DEPLOY_PUBLIC_KEY" ]]; then
  echo "DEPLOY_PUBLIC_KEY is required and must be the dedicated PREPROD public SSH key." >&2
  exit 2
fi
if [[ "$ENABLE_TLS" == "1" && -z "$TLS_EMAIL" ]]; then
  echo "TLS_EMAIL is required when ENABLE_TLS=1." >&2
  exit 2
fi

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y \
  ca-certificates curl gnupg lsb-release software-properties-common \
  nginx ufw apache2-utils openssl rsync jq unzip certbot python3-certbot-nginx \
  postfix
# Only smtp-sink is used. The Postfix MTA itself must never relay PREPROD mail.
systemctl disable --now postfix 2>/dev/null || true

# Ubuntu 24.04 ships PHP 8.3. Install the explicitly required PHP 8.4 runtime.
if ! grep -Rqs '^deb .*ondrej/php' /etc/apt/sources.list /etc/apt/sources.list.d 2>/dev/null; then
  add-apt-repository -y ppa:ondrej/php
fi
apt-get update
apt-get install -y \
  php8.4-cli php8.4-fpm php8.4-common php8.4-opcache \
  php8.4-mysql php8.4-xml php8.4-curl php8.4-mbstring php8.4-gd \
  php8.4-zip php8.4-intl php8.4-bcmath

# MariaDB's official repository setup supports the 11.8 series on noble.
if ! grep -Rqs 'mariadb.*11.8' /etc/apt/sources.list /etc/apt/sources.list.d 2>/dev/null; then
  curl -LsS https://r.mariadb.com/downloads/mariadb_repo_setup \
    | bash -s -- --mariadb-server-version="mariadb-11.8"
fi
apt-get update
apt-get install -y mariadb-server mariadb-client mariadb-backup
systemctl enable --now nginx php8.4-fpm mariadb

if ! id "$DEPLOY_USER" >/dev/null 2>&1; then
  useradd --create-home --shell /bin/bash "$DEPLOY_USER"
fi
usermod -a -G www-data "$DEPLOY_USER"
install -d -m 0700 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "/home/$DEPLOY_USER/.ssh"
printf '%s\n' "$DEPLOY_PUBLIC_KEY" > "/home/$DEPLOY_USER/.ssh/authorized_keys"
chown "$DEPLOY_USER:$DEPLOY_USER" "/home/$DEPLOY_USER/.ssh/authorized_keys"
chmod 0600 "/home/$DEPLOY_USER/.ssh/authorized_keys"

for path in \
  "$PROJECT_ROOT/releases" \
  "$PROJECT_ROOT/shared/files" \
  "$PROJECT_ROOT/shared/private" \
  "$PROJECT_ROOT/shared/settings" \
  "$PROJECT_ROOT/shared/backups" \
  "$PROJECT_ROOT/shared/deploy-jobs"; do
  install -d -m 0750 -o "$DEPLOY_USER" -g www-data "$path"
done
touch "$PROJECT_ROOT/shared/deploy.lock" "$PROJECT_ROOT/shared/deployments.log"
chown "$DEPLOY_USER:www-data" "$PROJECT_ROOT/shared/deploy.lock" "$PROJECT_ROOT/shared/deployments.log"
chmod 0640 "$PROJECT_ROOT/shared/deploy.lock" "$PROJECT_ROOT/shared/deployments.log"
chmod 2770 "$PROJECT_ROOT/shared/files" "$PROJECT_ROOT/shared/private"

cat > /etc/mysql/mariadb.conf.d/99-agency-preprod.cnf <<'EOF_MARIADB'
[mariadb]
bind-address = 127.0.0.1
max_allowed_packet = 64M
EOF_MARIADB
systemctl restart mariadb

DB_PASSWORD=''
HASH_SALT=''
ACCESS_PASSWORD=''
if [[ -f "$CREDENTIAL_FILE" ]]; then
  # shellcheck disable=SC1090
  source "$CREDENTIAL_FILE"
fi
DB_PASSWORD="${DB_PASSWORD:-$(openssl rand -hex 24)}"
HASH_SALT="${HASH_SALT:-$(openssl rand -hex 32)}"
if [[ -n "$REQUESTED_ACCESS_PASSWORD" ]]; then
  ACCESS_PASSWORD="$REQUESTED_ACCESS_PASSWORD"
else
  ACCESS_PASSWORD="${ACCESS_PASSWORD:-$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | head -c 24)}"
fi

umask 077
cat > "$CREDENTIAL_FILE" <<EOF_CREDS
DB_PASSWORD='$DB_PASSWORD'
HASH_SALT='$HASH_SALT'
ACCESS_USERNAME='preprod'
ACCESS_PASSWORD='$ACCESS_PASSWORD'
EOF_CREDS
chmod 0600 "$CREDENTIAL_FILE"

mariadb --protocol=socket <<EOF_SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
EOF_SQL

cat > "$PROJECT_ROOT/shared/settings/settings.php" <<EOF_SETTINGS
<?php

declare(strict_types=1);

\$databases['default']['default'] = [
  'database' => '$DB_NAME',
  'username' => '$DB_USER',
  'password' => '$DB_PASSWORD',
  'host' => '127.0.0.1',
  'port' => '3306',
  'driver' => 'mysql',
  'prefix' => '',
];
\$settings['hash_salt'] = '$HASH_SALT';
\$settings['config_sync_directory'] = dirname(DRUPAL_ROOT) . '/config/sync';
\$settings['file_private_path'] = '$PROJECT_ROOT/shared/private';
\$settings['trusted_host_patterns'] = ['^' . preg_quote('$PREPROD_FQDN', '/') . '$'];
\$config['config_split.config_split.production']['status'] = FALSE;
\$config['config_split.config_split.preproduction']['status'] = TRUE;
// PREPROD is intentionally isolated from production AI credentials.
putenv('OPENAI_API_KEY');
unset(\$_ENV['OPENAI_API_KEY'], \$_SERVER['OPENAI_API_KEY']);
EOF_SETTINGS
chown "$DEPLOY_USER:www-data" "$PROJECT_ROOT/shared/settings/settings.php"
chmod 0640 "$PROJECT_ROOT/shared/settings/settings.php"

# Local SMTP sink: messages are accepted on loopback and discarded. There is no
# relay and therefore no path to production recipients.
cat > /etc/systemd/system/agency-preprod-smtp-sink.service <<'EOF_SINK'
[Unit]
Description=Agency PREPROD local SMTP sink
After=network.target

[Service]
Type=simple
ExecStart=/usr/sbin/smtp-sink -h agency-preprod 127.0.0.1:1025 100
Restart=on-failure
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true

[Install]
WantedBy=multi-user.target
EOF_SINK
systemctl daemon-reload
systemctl enable --now agency-preprod-smtp-sink.service

htpasswd -bc /etc/nginx/agency-preprod.htpasswd preprod "$ACCESS_PASSWORD" >/dev/null
chown root:www-data /etc/nginx/agency-preprod.htpasswd
chmod 0640 /etc/nginx/agency-preprod.htpasswd

cat > /etc/nginx/sites-available/agency-preprod.conf <<EOF_NGINX
server {
    listen 80;
    listen [::]:80;
    server_name $PREPROD_FQDN;
    root $PROJECT_ROOT/current/web;
    index index.php;

    add_header X-Robots-Tag "noindex, nofollow, noarchive" always;
    auth_basic "Agency PREPROD";
    auth_basic_user_file /etc/nginx/agency-preprod.htpasswd;

    location / {
        try_files \$uri /index.php?\$query_string;
    }

    location ~ \.php$ {
        try_files \$uri =404;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    location ~ /\. {
        deny all;
    }
}
EOF_NGINX
ln -sfn /etc/nginx/sites-available/agency-preprod.conf /etc/nginx/sites-enabled/agency-preprod.conf
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable

if [[ "$ENABLE_TLS" == "1" ]]; then
  certbot --nginx --non-interactive --agree-tos --redirect \
    --email "$TLS_EMAIL" -d "$PREPROD_FQDN"
fi

# No Drupal cron/queue systemd unit is installed here. External side effects
# remain disabled until the real PREPROD validation explicitly enables them.
php -v | head -n 1
mariadb --version
nginx -v
systemctl is-active php8.4-fpm mariadb nginx agency-preprod-smtp-sink.service
systemctl is-enabled postfix 2>/dev/null | grep -Eq 'disabled|masked' || true
mariadb --protocol=socket -Nse 'SELECT @@max_allowed_packet' | grep -qx '67108864'

cat <<EOF_DONE
PREPROD_BOOTSTRAP=PASS
PREPROD_FQDN=$PREPROD_FQDN
PROJECT_ROOT=$PROJECT_ROOT
DEPLOY_USER=$DEPLOY_USER
CREDENTIAL_FILE=$CREDENTIAL_FILE
TLS_ENABLED=$ENABLE_TLS
EOF_DONE
