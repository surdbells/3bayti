#!/bin/sh
# =============================================================================
# 3bayti API — container entrypoint
# =============================================================================
#
# Runs at container start. Two jobs:
#
#   1. Substitute $PORT into nginx's default.conf (App Platform sets
#      PORT dynamically; we can't bake it into the config at build time).
#
#   2. Optionally wait for the database to be reachable. Useful in
#      docker-compose dev (where Postgres takes a few seconds to start)
#      and harmless in App Platform (DB is always up by the time the
#      container starts).
#
# After both jobs, exec the container's CMD (supervisord by default).
# =============================================================================

set -e

# ---- 1. Render nginx config with current $PORT ----
PORT="${PORT:-8080}"
NGINX_CONF=/etc/nginx/http.d/default.conf

# Replace literal '${PORT}' with the env value. We use envsubst, but
# only on the PORT variable so we don't accidentally interpolate other
# nginx variables that happen to use $-syntax.
if command -v envsubst >/dev/null 2>&1; then
    envsubst '$PORT' < "$NGINX_CONF" > "$NGINX_CONF.tmp" \
        && mv "$NGINX_CONF.tmp" "$NGINX_CONF"
else
    # envsubst not in alpine by default; sed fallback (only does PORT).
    sed -i "s/\${PORT}/${PORT}/g" "$NGINX_CONF"
fi
echo "[entrypoint] nginx configured to listen on port ${PORT}"

# ---- 2. Wait for DB (optional, on by default in dev) ----
if [ "${WAIT_FOR_DB:-1}" = "1" ] && [ -n "${DB_HOST:-}" ]; then
    echo "[entrypoint] waiting for database at ${DB_HOST}:${DB_PORT:-5432}..."
    # nc -z is the standard "is this TCP port open" check.
    # Loop with short sleeps; max 30 seconds total before giving up
    # (App Platform health check would catch it after that anyway).
    for i in $(seq 1 30); do
        if nc -z "$DB_HOST" "${DB_PORT:-5432}" 2>/dev/null; then
            echo "[entrypoint] database reachable"
            break
        fi
        if [ "$i" = "30" ]; then
            echo "[entrypoint] WARNING: database still unreachable after 30s"
            echo "[entrypoint] continuing anyway — /v3/health/ready will reflect this"
        fi
        sleep 1
    done
fi

# ---- 3. Hand off to CMD ----
echo "[entrypoint] starting: $@"
exec "$@"
