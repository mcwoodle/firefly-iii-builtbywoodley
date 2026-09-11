#!/usr/bin/env bash
# Root-level setup for a self-managed Firefly III install served straight from
# this git checkout (Fedora 44, nginx + php-fpm, SELinux enforcing).
# See woodley-stuff/README.md for the non-root steps that go with it.
# Idempotent: safe to re-run.
set -euo pipefail

# Defaults derive from where this script lives and who invoked sudo; override
# any of them via the environment, e.g. `sudo PORT=8080 bash woodley-stuff/firefly-root-setup.sh`.
APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
APP_USER="${APP_USER:-${SUDO_USER:-$(stat -c %U "$APP_DIR")}}"
LAN_IP="${LAN_IP:-192.168.0.238}"
PORT="${PORT:-6560}"

log() { printf '\n==> %s\n' "$*"; }

[[ $EUID -eq 0 ]] || { echo "run with sudo"; exit 1; }
[[ -d "$APP_DIR/public" ]] || { echo "APP_DIR not found: $APP_DIR"; exit 1; }

log "Installing PHP 8.5, extensions, composer, nginx, SELinux tools"
dnf install -y \
  php-cli php-fpm php-common php-bcmath php-intl php-pecl-zip php-sodium \
  php-gd php-xml php-mbstring php-pdo php-mysqlnd php-process \
  composer nginx policycoreutils-python-utils

log "php-fpm pool running as ${APP_USER}"
cat > /etc/php-fpm.d/firefly-iii.conf <<POOL
[firefly-iii]
user = ${APP_USER}
group = ${APP_USER}
listen = /run/php-fpm/firefly-iii.sock
listen.owner = nginx
listen.group = nginx
listen.mode = 0660
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 4
pm.max_requests = 500
php_admin_value[memory_limit] = 512M
php_admin_value[upload_max_filesize] = 64M
php_admin_value[post_max_size] = 64M
php_admin_value[max_execution_time] = 300
php_admin_value[error_log] = /var/log/php-fpm/firefly-iii-error.log
php_admin_flag[log_errors] = on
POOL

# systemd hardening on either unit could block access to /home
for unit in php-fpm nginx; do
  ph="$(systemctl show -p ProtectHome --value "$unit" 2>/dev/null || true)"
  if [[ -n "$ph" && "$ph" != "no" ]]; then
    log "Relaxing ProtectHome=$ph on $unit (app lives under /home)"
    mkdir -p "/etc/systemd/system/${unit}.service.d"
    printf '[Service]\nProtectHome=no\n' > "/etc/systemd/system/${unit}.service.d/firefly-iii.conf"
  fi
done
systemctl daemon-reload

log "nginx: minimal nginx.conf (stock port-80 welcome server removed; original kept as nginx.conf.orig)"
cp -n /etc/nginx/nginx.conf /etc/nginx/nginx.conf.orig
cat > /etc/nginx/nginx.conf <<'NGXMAIN'
user nginx;
worker_processes auto;
error_log /var/log/nginx/error.log notice;
pid /run/nginx.pid;

include /usr/share/nginx/modules/*.conf;

events {
    worker_connections 1024;
}

http {
    log_format main '$remote_addr - $remote_user [$time_local] "$request" '
                    '$status $body_bytes_sent "$http_referer" '
                    '"$http_user_agent" "$http_x_forwarded_for"';
    access_log /var/log/nginx/access.log main;

    sendfile            on;
    tcp_nopush          on;
    keepalive_timeout   65;
    types_hash_max_size 4096;

    include             /etc/nginx/mime.types;
    default_type        application/octet-stream;

    # Only servers defined in conf.d are loaded (firefly-iii on its own port).
    include /etc/nginx/conf.d/*.conf;
}
NGXMAIN

log "nginx server block on port ${PORT}"
cat > /etc/nginx/conf.d/firefly-iii.conf <<NGX
server {
    listen ${PORT} default_server;
    listen [::]:${PORT} default_server;
    server_name ${LAN_IP} localhost _;

    root ${APP_DIR}/public;
    index index.php;
    client_max_body_size 64M;

    access_log /var/log/nginx/firefly-iii.access.log;
    error_log  /var/log/nginx/firefly-iii.error.log;

    # prevent HTTPoxy vulnerability
    fastcgi_param HTTP_PROXY "";

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        try_files \$uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)\$;
        fastcgi_pass unix:/run/php-fpm/firefly-iii.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_read_timeout 300;
    }

    location ~ /\.(?!well-known) { deny all; }
}
NGX

log "Filesystem: let nginx traverse the home dir (ACL for the nginx user only)"
setfacl -m u:nginx:x "/home/${APP_USER}"

log "SELinux labels + booleans"
# An equivalence rule (-e /var/www) conflicts with per-subdir rules, so use
# explicit path rules: whole checkout read-only web content, storage/ and
# bootstrap/cache writable by php-fpm. Most-specific rule wins.
semanage fcontext -d -e /var/www "${APP_DIR}" 2>/dev/null || true
add_fcontext() { # <type> <path-regex>
  semanage fcontext -a -t "$1" "$2" 2>/dev/null || semanage fcontext -m -t "$1" "$2"
}
add_fcontext httpd_sys_content_t    "${APP_DIR}(/.*)?"
add_fcontext httpd_sys_rw_content_t "${APP_DIR}/storage(/.*)?"
add_fcontext httpd_sys_rw_content_t "${APP_DIR}/bootstrap/cache(/.*)?"
# -F: force relabel even for "customizable" types such as container_file_t (podman :Z)
restorecon -RF "${APP_DIR}"
# httpd_t must be able to traverse /home/<user> to reach the checkout.
setsebool -P httpd_enable_homedirs 1
# Firefly III makes outbound HTTP calls (update check, exchange rates, webhooks).
setsebool -P httpd_can_network_connect 1
# httpd_t may only bind ports labelled http_port_t; ${PORT} is not one by default.
if ! semanage port -l | grep -E '^http_port_t\s+tcp' | grep -qw "${PORT}"; then
  semanage port -a -t http_port_t -p tcp "${PORT}"
fi

log "Firewall: allow TCP ${PORT} in the active zone (port 80 stays closed)"
firewall-cmd --permanent --add-port="${PORT}/tcp" >/dev/null
firewall-cmd --reload >/dev/null

log "Enable + start services"
nginx -t
systemctl enable --now php-fpm nginx
systemctl restart php-fpm nginx

log "Done."
systemctl --no-pager --lines=0 status php-fpm nginx | grep -E 'Active'
php -v | head -1
composer --version 2>/dev/null | head -1
