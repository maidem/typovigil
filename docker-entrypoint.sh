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
# This has to happen before cache:flush, which needs a working bootstrap itself.
rm -rf /var/www/html/var/cache/* 2>/dev/null || true

cd /var/www/html && su -s /bin/bash www-data -c \
    "php vendor/bin/typo3 cache:flush 2>/dev/null || true"

# Adds missing tables and columns from extensions. Safe on every start: it only
# adds, never drops. stderr is kept so failures show up in the container logs.
cd /var/www/html && su -s /bin/bash www-data -c \
    "php vendor/bin/typo3 extension:setup 2>&1" || \
    echo "[docker-entrypoint] WARNING: extension:setup exited non-zero - check output above"

exec docker-php-entrypoint "$@"
