#!/usr/bin/env bash
# setup_hubriscup.sh
# Usage: Run this script from the hubriscup project directory
# bash setup_hubriscup.sh <DOMAIN> <EMAIL>
# Example: bash setup_hubriscup.sh hubriscup.ch you@example.com
set -euo pipefail

DOMAIN="${1:-}"
EMAIL="${2:-}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"  # Directory where this script is located
APP_ROOT="/var/www/hubriscup"
WEB_ROOT="${APP_ROOT}/public"
APP_USER="www-data"
NGINX_SITE="/etc/nginx/sites-available/${DOMAIN}"
NGINX_LINK="/etc/nginx/sites-enabled/${DOMAIN}"

if [[ -z "${DOMAIN}" || -z "${EMAIL}" ]]; then
  echo "Usage: $0 <DOMAIN> <EMAIL>"
  exit 1
fi

if [[ ! -d "${SCRIPT_DIR}" ]]; then
  echo "Cannot determine script directory. Ensure the script is in the hubriscup project root."
  exit 1
fi

echo "[1/9] Updating apt and installing packages..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y nginx ufw software-properties-common rsync

# Install PHP + SQLite; use distro default PHP (22.04=8.1, 24.04=8.3)
apt-get install -y php-fpm php-cli php-sqlite3 php-zip php-curl php-mbstring

echo "[2/9] Copying app from ${SCRIPT_DIR} to ${APP_ROOT}..."
rm -rf "${APP_ROOT}"
mkdir -p "${APP_ROOT}"

# Copy all files from the script directory (current project directory) to APP_ROOT
rsync -a "${SCRIPT_DIR}/" "${APP_ROOT}/" --exclude='.git' --exclude='*.zip' --exclude='setup_hubriscup.sh'

# Ensure docroot exists
mkdir -p "${WEB_ROOT}"

echo "[3/9] Create public/api symlink (as app expects)..."
if [[ -L "${WEB_ROOT}/api" || -e "${WEB_ROOT}/api" ]]; then
  rm -rf "${WEB_ROOT}/api"
fi
ln -s ../api "${WEB_ROOT}/api"

echo "[4/9] Fix ownership/permissions (SQLite needs to write)..."
# Ensure data/ exists and is writable by php-fpm user
mkdir -p "${APP_ROOT}/data"
chown -R "${APP_USER}:${APP_USER}" "${APP_ROOT}"
find "${APP_ROOT}" -type d -exec chmod 755 {} \;
find "${APP_ROOT}" -type f -exec chmod 644 {} \;
# data dir + db need write
chmod 775 "${APP_ROOT}/data" || true
if [[ -f "${APP_ROOT}/data/app.db" ]]; then
  chmod 664 "${APP_ROOT}/data/app.db" || true
fi

echo "[5/9] Detecting PHP-FPM socket..."
PHP_SOCK="$(ls -1 /run/php/php*-fpm.sock 2>/dev/null | head -n1 || true)"
if [[ -z "${PHP_SOCK}" ]]; then
  # Fallback to default path used by many Ubuntu builds
  PHP_SOCK="/run/php/php-fpm.sock"
fi
echo "Using PHP-FPM socket: ${PHP_SOCK}"

echo "[6/9] Writing NGINX server block for ${DOMAIN} ..."
cat > "${NGINX_SITE}" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN} www.${DOMAIN};

    root ${WEB_ROOT};
    index index.php index.html;

    # Serve static files directly
    location / {
        try_files \$uri \$uri/ /index.html;
    }

    # PHP handling (for /api/*.php and any index.php)
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_pass unix:${PHP_SOCK};
    }

    # Security headers (basic)
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;

    # Allow larger payloads if needed
    client_max_body_size 20m;
}
EOF

ln -sf "${NGINX_SITE}" "${NGINX_LINK}"

echo "[7/9] Test and reload NGINX..."
nginx -t
systemctl enable nginx
systemctl restart nginx

# Try to find the active php-fpm service name and restart it
PHPFPM_SERVICE="$(systemctl list-units --type=service --all | awk '/php[0-9.]*-fpm\.service/ {print $1; exit}')"
if [[ -n "${PHPFPM_SERVICE}" ]]; then
  systemctl enable "${PHPFPM_SERVICE}" || true
  systemctl restart "${PHPFPM_SERVICE}" || true
fi

echo "[8/9] Firewall (UFW): allow OpenSSH and Nginx Full..."
ufw allow OpenSSH || true
ufw allow "Nginx Full" || true
yes | ufw enable || true

echo "[9/9] Obtain and install Let’s Encrypt cert via certbot..."
apt-get install -y certbot python3-certbot-nginx
# Non-interactive certbot run
certbot --nginx -d "${DOMAIN}" -d "www.${DOMAIN}" --redirect --non-interactive --agree-tos -m "${EMAIL}" || {
  echo "Certbot failed (maybe DNS not ready). You can re-run:"
  echo "  certbot --nginx -d ${DOMAIN} -d www.${DOMAIN} --redirect -m ${EMAIL}"
}

echo "------------------------------------------------------------"
echo "Done! Visit: https://${DOMAIN}  (admin at /admin)"
echo "App root: ${APP_ROOT}"
echo "Web root: ${WEB_ROOT}"
echo "Nginx site: ${NGINX_SITE}"
echo "SQLite dir: ${APP_ROOT}/data (owned by ${APP_USER})"
echo "------------------------------------------------------------"
