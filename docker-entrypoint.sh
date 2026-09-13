#!/bin/bash
set -e

# Coolify creates bind-mounted directories as root when they do not exist yet,
# so ownership is fixed on every start before Apache runs.
chown -R www-data:www-data \
    /var/www/html/var \
    /var/www/html/public/fileadmin \
    /var/www/html/public/typo3temp 2>/dev/null || true

# Stale serialized TCA can break the bootstrap outright ("Typed property must not
# be accessed before initialization") after an extension adds readonly properties.
# This has to happen before anything else, which needs a working bootstrap itself.
rm -rf /var/www/html/var/cache/* 2>/dev/null || true

cd /var/www/html

# Has the database been initialised at all? extension:setup assumes an existing
# schema and fails deep inside the bootstrap when the cache tables are missing,
# reporting a confusing TcaSchemaFactory error rather than "no tables".
DB_READY=$(php -r '
$host = getenv("TYPO3_DATABASE_HOST") ?: "";
$user = getenv("TYPO3_DATABASE_USERNAME") ?: "";
$pass = getenv("TYPO3_DATABASE_PASSWORD") ?: "";
$name = getenv("TYPO3_DATABASE_NAME") ?: "";
if ($host === "" || $name === "") { echo "unknown"; exit; }
$conn = @mysqli_connect($host, $user, $pass, $name);
if (!$conn) { echo "unreachable"; exit; }
$res = @mysqli_query($conn, "SHOW TABLES LIKE \"be_users\"");
echo ($res && mysqli_num_rows($res) > 0) ? "ready" : "empty";
' 2>/dev/null || echo unknown)

if [ "$DB_READY" = "empty" ]; then
    echo "[docker-entrypoint] Empty database detected - running initial setup"
    su -s /bin/bash www-data -c "php vendor/bin/typo3 setup \
        --driver=mysqli \
        --host=\"${TYPO3_DATABASE_HOST}\" \
        --port=\"${TYPO3_DATABASE_PORT:-3306}\" \
        --dbname=\"${TYPO3_DATABASE_NAME}\" \
        --username=\"${TYPO3_DATABASE_USERNAME}\" \
        --password=\"${TYPO3_DATABASE_PASSWORD}\" \
        --admin-username=\"${TYPO3_ADMIN_USERNAME:-admin}\" \
        --admin-user-password=\"${TYPO3_ADMIN_PASSWORD}\" \
        --admin-email=\"${TYPO3_ADMIN_EMAIL:-admin@example.com}\" \
        --project-name=\"TypoVigil\" \
        --no-interaction --force 2>&1" || \
        echo "[docker-entrypoint] WARNING: initial setup failed - check output above"
fi

su -s /bin/bash www-data -c \
    "php vendor/bin/typo3 cache:flush 2>/dev/null || true"

# Adds missing tables and columns from extensions. Safe on every start: it only
# adds, never drops. stderr is kept so failures show up in the container logs.
su -s /bin/bash www-data -c \
    "php vendor/bin/typo3 extension:setup 2>&1" || \
    echo "[docker-entrypoint] WARNING: extension:setup exited non-zero - check output above"

exec docker-php-entrypoint "$@"
