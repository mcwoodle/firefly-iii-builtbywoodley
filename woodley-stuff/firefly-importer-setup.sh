#!/usr/bin/env bash
# User-level setup for the Firefly III Data Importer as a rootless podman
# container managed by user systemd (Quadlet). No root needed: firewalld on this
# box already opens 1025-65535/tcp and the port is >1024.
# See woodley-stuff/README.md ("Data Importer"). Idempotent: safe to re-run.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_DIR="${ENV_DIR:-$HOME/.config/firefly-importer}"
ENV_FILE="$ENV_DIR/importer.env"
QUADLET_DIR="$HOME/.config/containers/systemd"
UNIT=firefly-importer

log() { printf '\n==> %s\n' "$*"; }

[[ $EUID -ne 0 ]] || { echo "run as your normal user, not root/sudo"; exit 1; }
command -v podman >/dev/null || { echo "podman not installed (dnf install podman)"; exit 1; }

log "Env file: $ENV_FILE"
mkdir -p "$ENV_DIR"
if [[ -f "$ENV_FILE" ]]; then
  echo "exists, leaving as-is (diff against $HERE/importer.env.example if unsure)"
else
  install -m 600 "$HERE/importer.env.example" "$ENV_FILE"
  echo "created from importer.env.example — edit URLs/token if your LAN IP or port differ"
fi
chmod 600 "$ENV_FILE"

log "Quadlet unit: $QUADLET_DIR/$UNIT.container"
mkdir -p "$QUADLET_DIR"
install -m 644 "$HERE/$UNIT.container" "$QUADLET_DIR/$UNIT.container"
systemctl --user daemon-reload

log "Starting $UNIT.service (first run pulls ~830 MB)"
systemctl --user restart "$UNIT.service"
# Wait for the container's web server rather than just the unit.
for _ in $(seq 1 60); do
  if curl -fsS -o /dev/null --max-time 2 http://127.0.0.1:6580/ 2>/dev/null; then break; fi
  sleep 2
done
systemctl --user --no-pager --lines=0 status "$UNIT.service" || true

log "Linger (so the service runs at boot without a login session)"
if [[ "$(loginctl show-user "$USER" -p Linger --value 2>/dev/null)" == "yes" ]]; then
  echo "already enabled"
elif loginctl enable-linger "$USER" 2>/dev/null; then
  echo "enabled"
else
  echo "could not enable without auth — run: sudo loginctl enable-linger $USER"
fi

log "Sanity: can the container reach Firefly III?"
FF_URL="$(sed -n 's/^FIREFLY_III_URL=//p' "$ENV_FILE")"
podman exec "$UNIT" curl -fsS -o /dev/null -w "  $FF_URL -> HTTP %{http_code}\n" --max-time 5 "$FF_URL/login" \
  || echo "  FAILED: check FIREFLY_III_URL in $ENV_FILE and that nginx is up on the host"

APP_URL="$(sed -n 's/^APP_URL=//p' "$ENV_FILE")"
log "Done. Importer: ${APP_URL:-http://127.0.0.1:6580}"
cat <<MSG
Next: in Firefly (Profile → OAuth) create an OAuth Client with callback
${APP_URL:-http://<host>:6580}/callback and "Confidential" UNCHECKED, then enter
its Client ID in the importer. (Or pin FIREFLY_III_ACCESS_TOKEN in $ENV_FILE.)
MSG
