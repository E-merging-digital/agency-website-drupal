#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

HOSTNAME="${1:-preprod.emergingdigital.be}"
PROJECT_USER="agency-preprod"
PROJECT_GROUP="www-data"
PROJECT_ROOT="/var/www/agency-preprod"
SHARED_DIR="$PROJECT_ROOT/shared"
DB_NAME="agency_preprod"
DB_USER="agency_preprod"
DB_ACCOUNT_HOST="127.0.0.1"
PHP_VERSION="8.4"
PHP_SOCKET="/run/php/php8.4-fpm-agency-preprod.sock"
HTPASSWD_FILE="/etc/nginx/agency-preprod.htpasswd"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SETTINGS_TEMPLATE="$SCRIPT_DIR/settings.php.template"
NGINX_TEMPLATE="$SCRIPT_DIR/nginx-agency-preprod.conf.template"
RUNTIME_ENV="$SHARED_DIR/settings/runtime.env"
SETTINGS_FILE="$SHARED_DIR/settings/settings.php"
DEPLOY_PUBLIC_KEY="${AGENCY_PREPROD_DEPLOY_PUBLIC_KEY:-}"
BASIC_AUTH_USER="${PREPROD_BASIC_AUTH_USER:-}"
BASIC_AUTH_PASSWORD="${PREPROD_BASIC_AUTH_PASSWORD:-}"
TLS_EMAIL="${PREPROD_TLS_EMAIL:-}"

log() {
  printf '[bootstrap] %s\n' "$1"
}

fail() {
  printf '[bootstrap] ERROR: %s\n' "$1" >&2
  exit 1
}

[[ "$(id -u)" -eq 0 ]] || fail "Run as root."
[[ "$HOSTNAME" =~ ^[a-z0-9.-]+$ ]] || fail "Invalid PREPROD hostname."
[[ -n "$DEPLOY_PUBLIC_KEY" ]] || fail "AGENCY_PREPROD_DEPLOY_PUBLIC_KEY is required."
[[ -n "$BASIC_AUTH_USER" ]] || fail "PREPROD_BASIC_AUTH_USER is required."
[[ -n "$BASIC_AUTH_PASSWORD" ]] || fail "PREPROD_BASIC_AUTH_PASSWORD is required."
[[ -n "$TLS_EMAIL" ]] || fail "PREPROD_TLS_EMAIL is required."
[[ -f "$SETTINGS_TEMPLATE" ]] || fail "Missing settings template."
[[ -f "$NGINX_TEMPLATE" ]] || fail "Missing Nginx template."

# shellcheck disable=SC1091
source /etc/os-release
[[ "${ID:-}" == "ubuntu" ]] || fail "Ubuntu is required."
[[ "${VERSION_ID:-}" == "24.04" ]] || fail "Ubuntu 24.04 LTS is required."

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y \
  apache2-utils \
  ca-certificates \
  certbot \
  curl \
  git \
  gnupg \
  jq \
  nginx \
  openssl \
  python3-certbot-nginx \
  rsync \
  software-properties-common \
  ufw \
  unzip

if ! apt-cache show php8.4-fpm >/dev/null 2>&1; then
  log "Add maintained PHP PPA for Ubuntu Noble."
  add-apt-repository -y ppa:ondrej/php
  apt-get update
fi

if ! apt-cache policy mariadb-server | grep -q '11\.8\.'; then
  log "Configure the official MariaDB 11.8 repository."
  repo_setup="$(mktemp)"
  curl --fail --show-error --silent --location \
    https://r.mariadb.com/downloads/mariadb_repo_setup \
    --output "$repo_setup"
  bash "$repo_setup" \
    --mariadb-server-version=mariadb-11.8 \
    --os-type=ubuntu \
    --os-version=noble
  rm -f "$repo_setup"
  apt-get update
fi

apt-get install -y \
  mariadb-backup \
  mariadb-client \
  mariadb-server \
  php8.4-bcmath \
  php8.4-cli \
  php8.4-common \
  php8.4-curl \
  php8.4-fpm \
  php8.4-gd \
  php8.4-intl \
  php8.4-mbstring \
  php8.4-mysql \
  php8.4-opcache \
  php8.4-xml \
  php8.4-zip

if ! id "$PROJECT_USER" >/dev/null 2>&1; then
  useradd --create-home --shell /bin/bash "$PROJECT_USER"
fi
usermod -a -G "$PROJECT_GROUP" "$PROJECT_USER"

install -d -m 700 -o "$PROJECT_USER" -g "$PROJECT_USER" "/home/$PROJECT_USER/.ssh"
authorized_keys="/home/$PROJECT_USER/.ssh/authorized_keys"
touch "$authorized_keys"
chown "$PROJECT_USER:$PROJECT_USER" "$authorized_keys"
chmod 600 "$authorized_keys"
if ! grep -Fqx "$DEPLOY_PUBLIC_KEY" "$authorized_keys"; then
  printf '%s\n' "$DEPLOY_PUBLIC_KEY" >> "$authorized_keys"
fi

install -d -m 750 -o "$PROJECT_USER" -g "$PROJECT_GROUP" \
  "$PROJECT_ROOT" \
  "$PROJECT_ROOT/releases" \
  "$SHARED_DIR" \
  "$SHARED_DIR/artifacts" \
  "$SHARED_DIR/backups" \
  "$SHARED_DIR/logs" \
  "$SHARED_DIR/settings"
for jobs_dir in "$SHARED_DIR/deploy-jobs" "$SHARED_DIR/refresh-jobs"; do
  if [[ -e "$jobs_dir" || -L "$jobs_dir" ]]; then
    [[ -d "$jobs_dir" && ! -L "$jobs_dir" ]] || fail "Unsafe shared jobs path: $jobs_dir"
  fi
done
install -d -m 700 -o "$PROJECT_USER" -g "$PROJECT_USER" \
  "$SHARED_DIR/deploy-jobs" \
  "$SHARED_DIR/refresh-jobs"
for jobs_dir in "$SHARED_DIR/deploy-jobs" "$SHARED_DIR/refresh-jobs"; do
  [[ "$(stat -c '%U:%G:%a' "$jobs_dir")" == "$PROJECT_USER:$PROJECT_USER:700" ]] || \
    fail "Shared jobs directory contract mismatch: $jobs_dir"
done
install -d -m 2770 -o "$PROJECT_USER" -g "$PROJECT_GROUP" \
  "$SHARED_DIR/files" \
  "$SHARED_DIR/private"

if [[ ! -f "$RUNTIME_ENV" ]]; then
  db_password="$(openssl rand -hex 32)"
  hash_salt="$(openssl rand -hex 32)"
  drupal_admin_password="$(openssl rand -base64 30 | tr -d '\n')"
  runtime_tmp="${RUNTIME_ENV}.tmp.$$"
  {
    printf 'DB_PASSWORD=%q\n' "$db_password"
    printf 'HASH_SALT=%q\n' "$hash_salt"
    printf 'DRUPAL_ADMIN_PASSWORD=%q\n' "$drupal_admin_password"
  } > "$runtime_tmp"
  chown "$PROJECT_USER:$PROJECT_USER" "$runtime_tmp"
  chmod 600 "$runtime_tmp"
  mv -f "$runtime_tmp" "$RUNTIME_ENV"
fi

# shellcheck disable=SC1090
source "$RUNTIME_ENV"
[[ -n "${DB_PASSWORD:-}" ]] || fail "DB_PASSWORD is missing from runtime.env."
[[ -n "${HASH_SALT:-}" ]] || fail "HASH_SALT is missing from runtime.env."
[[ -n "${DRUPAL_ADMIN_PASSWORD:-}" ]] || fail "DRUPAL_ADMIN_PASSWORD is missing from runtime.env."

mariadb_conf="/etc/mysql/mariadb.conf.d/99-agency-preprod.cnf"
cat > "$mariadb_conf" <<'CNF'
[mariadb]
max_allowed_packet=64M
CNF
chmod 644 "$mariadb_conf"
systemctl enable --now mariadb
systemctl restart mariadb

mariadb --protocol=socket <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'$DB_ACCOUNT_HOST' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER '$DB_USER'@'$DB_ACCOUNT_HOST' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'$DB_ACCOUNT_HOST';
FLUSH PRIVILEGES;
SQL

trusted_host_regex="${HOSTNAME//./\\.}"
sed \
  -e "s|@@DB_NAME@@|$DB_NAME|g" \
  -e "s|@@DB_USER@@|$DB_USER|g" \
  -e "s|@@DB_PASSWORD@@|$DB_PASSWORD|g" \
  -e "s|@@HASH_SALT@@|$HASH_SALT|g" \
  -e "s|@@PROJECT_ROOT@@|$PROJECT_ROOT|g" \
  -e "s|@@TRUSTED_HOST_REGEX@@|$trusted_host_regex|g" \
  "$SETTINGS_TEMPLATE" > "${SETTINGS_FILE}.tmp.$$"
chown "$PROJECT_USER:$PROJECT_GROUP" "${SETTINGS_FILE}.tmp.$$"
chmod 640 "${SETTINGS_FILE}.tmp.$$"
mv -f "${SETTINGS_FILE}.tmp.$$" "$SETTINGS_FILE"

printf '%s\n' "$BASIC_AUTH_PASSWORD" | \
  htpasswd -B -i -c "$HTPASSWD_FILE" "$BASIC_AUTH_USER" >/dev/null
chown root:www-data "$HTPASSWD_FILE"
chmod 640 "$HTPASSWD_FILE"

fpm_pool="/etc/php/8.4/fpm/pool.d/agency-preprod.conf"
cat > "$fpm_pool" <<EOF_POOL
[agency-preprod]
user = $PROJECT_USER
group = $PROJECT_GROUP
listen = $PHP_SOCKET
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 8
pm.process_idle_timeout = 10s
pm.max_requests = 500
clear_env = yes
php_admin_value[sendmail_path] = /bin/true
EOF_POOL
chmod 644 "$fpm_pool"

cli_safety="/etc/php/8.4/cli/conf.d/99-agency-preprod-safety.ini"
cat > "$cli_safety" <<'EOF_INI'
sendmail_path = /bin/true
EOF_INI
chmod 644 "$cli_safety"

nginx_site="/etc/nginx/sites-available/agency-preprod"
sed \
  -e "s|@@HOSTNAME@@|$HOSTNAME|g" \
  -e "s|@@PROJECT_ROOT@@|$PROJECT_ROOT|g" \
  -e "s|@@HTPASSWD_FILE@@|$HTPASSWD_FILE|g" \
  -e "s|@@PHP_SOCKET@@|$PHP_SOCKET|g" \
  "$NGINX_TEMPLATE" > "$nginx_site"
chmod 644 "$nginx_site"
ln -sfn "$nginx_site" /etc/nginx/sites-enabled/agency-preprod
rm -f /etc/nginx/sites-enabled/default

systemctl enable --now php8.4-fpm nginx
systemctl restart php8.4-fpm
nginx -t
systemctl reload nginx

ufw default deny incoming
ufw default allow outgoing
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable

log "Request TLS certificate for $HOSTNAME."
certbot --nginx \
  --non-interactive \
  --agree-tos \
  --redirect \
  --email "$TLS_EMAIL" \
  -d "$HOSTNAME"

packet_bytes="$(mariadb --protocol=socket -Nse 'SELECT @@GLOBAL.max_allowed_packet;')"
[[ "$packet_bytes" == "67108864" ]] || fail "MariaDB max_allowed_packet is not 64M."
php8.4 -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 4 ? 0 : 1);'
mariadb --version | grep -q '11\.8\.' || fail "MariaDB 11.8 is not active."
nginx -t
systemctl is-active --quiet nginx
systemctl is-active --quiet php8.4-fpm
systemctl is-active --quiet mariadb

log "Bootstrap converged for $HOSTNAME. Secrets were not printed."