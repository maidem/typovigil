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

# Hourly upstream check. Without this nothing ever queries Packagist and
# get.typo3.org: the agents keep reporting their versions, but no comparison
# happens and every data source stays "not queried yet" in the footer.
#
# A cron in the container rather than a scheduler task: it needs no record in
# the database and therefore survives a redeploy onto a fresh volume.
# A cron job inherits none of the container's environment, and the database
# credentials arrive exactly that way. Without this the check runs on schedule
# but cannot connect, failing silently every hour. Written 0600 and owned by
# www-data: it holds the database password.
# printf %q rather than wrapping in quotes by hand: a password containing a
# quote, $ or backslash would otherwise produce a broken file, and the job
# would fail silently every hour — the very bug this fixes.
: > /var/www/html/var/cron-env.sh
while IFS='=' read -r -d '' key value; do
    case "$key" in
        TYPO3_*) printf 'export %s=%q\n' "$key" "$value" >> /var/www/html/var/cron-env.sh ;;
    esac
done < <(env -0)
chmod 0600 /var/www/html/var/cron-env.sh
chown www-data:www-data /var/www/html/var/cron-env.sh

printf '%s\n' \
    'SHELL=/bin/bash' \
    'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin' \
    '17 * * * * www-data . /var/www/html/var/cron-env.sh && cd /var/www/html && php vendor/bin/typo3 typovigil:check >> /var/log/typovigil-check.log 2>&1' \
    '' \
    > /etc/cron.d/typovigil
chmod 0644 /etc/cron.d/typovigil
touch /var/log/typovigil-check.log
chown www-data:www-data /var/log/typovigil-check.log
cron

exec docker-php-entrypoint "$@"
