#!/bin/bash
set -eo pipefail

echo "==> Starting WordPress FrankenPHP Container..."

# 1. Check if WordPress core is already present in persistent storage
if [ ! -e /var/www/html/index.php ] && [ ! -e /var/www/html/wp-includes ]; then
    echo ">> [Init] Empty volume detected at /var/www/html. Initializing fresh WordPress core from /usr/src/wordpress..."
    cp -rn /usr/src/wordpress/. /var/www/html/
    echo ">> [Init] WordPress core initialized successfully."
else
    echo ">> [Persistent] Existing WordPress files detected. Preserving all existing data (no files overwritten)."
fi

# 2. Check if only wp-content is mounted as a persistent volume and is fresh
if [ ! -e /var/www/html/wp-content/themes ] && [ -d /usr/src/wordpress/wp-content ]; then
    echo ">> [Init] Fresh wp-content volume detected. Copying default themes & plugins..."
    cp -rn /usr/src/wordpress/wp-content/. /var/www/html/wp-content/
fi

# 3. Ensure wp-config.php exists without touching any existing customized configuration
if [ ! -e /var/www/html/wp-config.php ] && [ -e /usr/src/wordpress/wp-config.php ]; then
    echo ">> [Config] Copying default wp-config.php..."
    cp /usr/src/wordpress/wp-config.php /var/www/html/wp-config.php
else
    echo ">> [Config] Existing wp-config.php preserved."
fi

# 4. Safe & Fast Permissions Management
# Ensure critical directories exist for downloads, uploads, plugins, and upgrades
mkdir -p /var/www/html/wp-content/uploads \
         /var/www/html/wp-content/upgrade \
         /var/www/html/wp-content/plugins \
         /var/www/html/wp-content/themes 2>/dev/null || true

# Ensure temporary directory is fully writable
chmod 1777 /tmp 2>/dev/null || true

# Only perform recursive chown if ownership is not already www-data (prevents boot lag on large uploads)
CURRENT_OWNER=$(stat -c '%U:%G' /var/www/html/wp-content 2>/dev/null || true)
if [ "$CURRENT_OWNER" != "www-data:www-data" ]; then
    echo ">> [Permissions] Fixing ownership for wp-content to www-data..."
    chown -R www-data:www-data /var/www/html/wp-content 2>/dev/null || true
fi

# 5. Optional: Wait for database if WAIT_FOR_DB is enabled
if [ "${WAIT_FOR_DB:-false}" = "true" ] && [ -n "${DB_HOST:-${WORDPRESS_DB_HOST:-}}" ]; then
    TARGET_HOST="${DB_HOST:-${WORDPRESS_DB_HOST}}"
    HOST="${TARGET_HOST%%:*}"
    PORT="${TARGET_HOST##*:}"
    if [ "$HOST" = "$PORT" ]; then
        PORT=3306
    fi
    echo ">> [Database] Waiting for database at $HOST:$PORT to be ready..."
    while ! timeout 2 bash -c "cat < /dev/null > /dev/tcp/$HOST/$PORT" 2>/dev/null; do
        sleep 2
    done
    echo ">> [Database] Connected successfully!"
fi

echo ">> [Ready] Handing over to FrankenPHP..."
exec "$@"
