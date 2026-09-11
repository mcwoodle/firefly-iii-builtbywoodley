# Local self-managed install (Fedora, nginx + php-fpm, SQLite)

Runs Firefly III straight from this checkout and exposes it on the LAN, per the
[self-managed guide](https://docs.firefly-iii.org/how-to/firefly-iii/installation/self-managed/)
and the FAQ entry on building the release yourself. Current instance:
`http://192.168.0.238:6560`.

## One-time setup

```bash
# 1. Frontend (compiled JS is not in git). laravel-mix may auto-install
#    vue-loader on the first run and prune node_modules — if so, repeat this block.
npm install
npm run prod --workspace=v1
npm run build --workspace=v2

# 2. Root-level pieces: PHP 8.5 + extensions, composer, nginx, a php-fpm pool
#    running as your user, SELinux labels/port/booleans, firewalld port, services.
#    Idempotent; defaults to this checkout, $SUDO_USER, port 6560, 192.168.0.238.
sudo bash woodley-stuff/firefly-root-setup.sh
# override e.g.: sudo PORT=8080 LAN_IP=192.168.0.50 bash woodley-stuff/firefly-root-setup.sh

# 3. PHP dependencies
composer install --no-dev

# 4. Config — copy .env.example, then set at least:
#      APP_KEY (32 chars: head /dev/urandom | LC_ALL=C tr -dc 'A-Za-z0-9' | head -c 32)
#      SITE_OWNER, TZ, APP_URL=http://<LAN_IP>:<PORT>
#      DB_CONNECTION=sqlite  (and delete the other DB_* lines)
cp .env.example .env
touch storage/database/database.sqlite

# 5. Database + keys (from the guide)
php artisan firefly-iii:upgrade-database
php artisan firefly-iii:correct-database
php artisan firefly-iii:report-integrity
php artisan firefly-iii:laravel-passport-keys
```

Then open `http://<LAN_IP>:<PORT>/` and register the first user.

## What the root script changes

| Area | Change |
|---|---|
| Packages | `php-cli php-fpm php-{bcmath,intl,pecl-zip,sodium,gd,xml,mbstring,pdo,mysqlnd,process} composer nginx policycoreutils-python-utils` |
| php-fpm | `/etc/php-fpm.d/firefly-iii.conf`, pool runs as `$APP_USER`, socket `/run/php-fpm/firefly-iii.sock` |
| nginx | Minimal `/etc/nginx/nginx.conf` (original saved as `nginx.conf.orig`, stock port-80 server removed) + `/etc/nginx/conf.d/firefly-iii.conf` on `$PORT` |
| Home dir | `setfacl -m u:nginx:x /home/$APP_USER` (nginx only needs traverse) |
| SELinux | Checkout → `httpd_sys_content_t`; `storage/`, `bootstrap/cache` → `httpd_sys_rw_content_t`; `$PORT` added to `http_port_t`; booleans `httpd_enable_homedirs`, `httpd_can_network_connect` |
| Firewall | `firewall-cmd --permanent --add-port=$PORT/tcp` |
| Services | `php-fpm`, `nginx` enabled and started |

## Gotcha: podman `:Z` mounts

`woodley-dev-stuff/podman-container/podman-run.sh` mounts the workspace with
`:Z`, which relabels the whole tree to `container_file_t`. nginx/php-fpm can't
read that type, so the site returns 403. Plain `restorecon` skips
`container_file_t` (it is a "customizable" type); force it:

```bash
sudo restorecon -RF "$(git rev-parse --show-toplevel)"
```

Diagnose with `ls -Z public/index.php` and
`journalctl --since -10min | grep -i 'avc.*denied'`.

## Data Importer (rootless podman, Quadlet)

Follows the [Docker guide](https://docs.firefly-iii.org/how-to/data-importer/installation/docker/)'s
"Plain Docker" single-container path, run with podman instead. Current
instance: `http://192.168.0.238:6580`. Docker was **not** installed.

### Why podman, not docker

- Firefly III itself is already on the host (nginx/php-fpm above), so the
  guide's compose stack (Firefly + MariaDB + importer + cron) is the wrong
  shape; only the importer container is needed, and `podman run` takes the
  same flags as `docker run`.
- Fedora ships podman (5.8 here) and it is already used for the agent sandbox.
  Docker would add a root daemon and a second container runtime for one image.
- Rootless + SELinux + user-systemd (Quadlet) give `restart: always` semantics
  without root and without compose.
- One real difference: podman 5's default `pasta` networking hands the
  container the host's **own LAN IP**, so from inside the container
  `http://192.168.0.238:6560` loops back to the container and fails. The
  importer must call Firefly via `host.containers.internal` (podman also adds
  the docker-style alias `host.docker.internal`), while the browser keeps using
  the LAN URL. That is exactly the `FIREFLY_III_URL` / `VANITY_URL` split the
  importer already supports.

### Setup

```bash
# No root: firewalld here already opens 1025-65535/tcp.
bash woodley-stuff/firefly-importer-setup.sh
```

What it does (idempotent):

| Piece | Location |
|---|---|
| Env file (from `importer.env.example`, **may hold a token, mode 600, not in git**) | `~/.config/firefly-importer/importer.env` |
| Quadlet unit (copy of `firefly-importer.container`) | `~/.config/containers/systemd/firefly-importer.container` |
| Service | `systemctl --user {status,restart,stop} firefly-importer`; logs: `journalctl --user -u firefly-importer -f` |
| Boot without login | `loginctl enable-linger $USER` |
| Sanity check | `podman exec firefly-importer curl $FIREFLY_III_URL/login` must return 200 |

Then in Firefly III (Profile → OAuth) create an OAuth Client with callback
`http://192.168.0.238:6580/callback` and **"Confidential" unchecked**, open the
importer and enter the Client ID. (A Personal Access Token works too, but only
via `FIREFLY_III_ACCESS_TOKEN` in the env file — the browser form has no field
for it.)

Upgrade: `podman auto-update` (the unit carries `AutoUpdate=registry`), or
`podman pull docker.io/fireflyiii/data-importer:latest && systemctl --user restart firefly-importer`.

Directory import is off by default. To enable it, uncomment `Volume=` in the
`.container` file and `IMPORT_DIR_ALLOWLIST` in the env file, re-run the setup
script, and keep the host directory **outside this checkout** — the `:Z` mount
option relabels it to `container_file_t`, which is the same thing that 403s
nginx in the gotcha above.

## Not covered

- Firefly's daily cron (`php artisan firefly-iii:cron`) for recurring
  transactions and bills — add a crontab entry if you use those.
- `public/v2/i18n` (translations for the opt-in v2 layout) is produced by
  upstream release tooling, not by this repo's build.
