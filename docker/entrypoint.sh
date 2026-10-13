#!/bin/sh
# Boots the app with no manual setup: persisted APP_KEY, SQLite file, migrations, caches.
set -e

APP_ROOT=/var/www/html
KEY_FILE="${APP_ROOT}/storage/app/.app_key"

cd "$APP_ROOT"

# Volumes mount in owned by root; hand them to the app user before dropping privileges.
if [ "$(id -u)" = "0" ]; then
    mkdir -p storage/app storage/framework/cache storage/framework/sessions \
             storage/framework/views storage/logs bootstrap/cache
    chown -R app:app storage bootstrap/cache database
fi

# SU is a command prefix rather than a function so it also works with `exec`,
# which cannot invoke shell functions.
if [ "$(id -u)" = "0" ]; then
    SU="su-exec app"
else
    SU=""
fi

run() {
    # shellcheck disable=SC2086
    $SU "$@"
}

# APP_KEY survives container recreation so existing sessions and encrypted values stay valid.
if [ -z "${APP_KEY:-}" ]; then
    if [ ! -f "$KEY_FILE" ]; then
        run php -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;' > "$KEY_FILE"
        chown app:app "$KEY_FILE" 2>/dev/null || true
    fi
    APP_KEY="$(cat "$KEY_FILE")"
    export APP_KEY
fi

case "${DB_CONNECTION:-mysql}" in
sqlite)
    # Retained for host-side/offline runs; the Docker stack uses MySQL.
    DB_FILE="${DB_DATABASE:-${APP_ROOT}/database/database.sqlite}"
    if [ "$DB_FILE" != ":memory:" ] && [ ! -f "$DB_FILE" ]; then
        echo "==> Creating SQLite database at ${DB_FILE}"
        run touch "$DB_FILE"
    fi
    ;;
*)
    # Compose waits for the server's healthcheck, but the app user/database can
    # still be mid-initialisation, so block on a real connection+query.
    echo "==> Waiting for ${DB_CONNECTION} at ${DB_HOST}:${DB_PORT}..."
    waited=0
    until run php -r '
        $dsn = sprintf("%s:host=%s;port=%s;dbname=%s", getenv("DB_CONNECTION") === "mariadb" ? "mysql" : getenv("DB_CONNECTION"), getenv("DB_HOST"), getenv("DB_PORT") ?: 3306, getenv("DB_DATABASE"));
        try { (new PDO($dsn, getenv("DB_USERNAME"), getenv("DB_PASSWORD")))->query("SELECT 1"); }
        catch (Throwable $e) { fwrite(STDERR, $e->getMessage().PHP_EOL); exit(1); }
    ' 2>/dev/null; do
        waited=$((waited + 2))
        if [ "$waited" -ge 120 ]; then
            echo "!! Database at ${DB_HOST}:${DB_PORT} did not become ready within 120s" >&2
            run php -r '
                $dsn = sprintf("mysql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT") ?: 3306, getenv("DB_DATABASE"));
                try { new PDO($dsn, getenv("DB_USERNAME"), getenv("DB_PASSWORD")); }
                catch (Throwable $e) { fwrite(STDERR, "   last error: ".$e->getMessage().PHP_EOL); }
            ' || true
            exit 1
        fi
        sleep 2
    done
    echo "==> Database is ready"
    ;;
esac

case "$1" in
serve|queue|scheduler)
    echo "==> Running migrations"
    run php artisan migrate --force --no-interaction

    echo "==> Caching configuration, routes and views"
    run php artisan config:cache --no-interaction
    run php artisan route:cache --no-interaction
    run php artisan view:cache --no-interaction
    ;;
esac

case "$1" in
serve)
    echo "==> Serving on 0.0.0.0:${PORT:-8000}"
    exec $SU php artisan serve --host=0.0.0.0 --port="${PORT:-8000}"
    ;;
queue)
    exec $SU php artisan queue:work --tries=3 --max-time=3600
    ;;
scheduler)
    exec $SU php artisan schedule:work
    ;;
artisan)
    shift
    exec $SU php artisan "$@"
    ;;
*)
    exec $SU "$@"
    ;;
esac
