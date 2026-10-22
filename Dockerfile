FROM dunglas/frankenphp:latest-php8.3-bookworm

LABEL maintainer="Custom WordPress FrankenPHP"
LABEL description="High-performance WordPress image powered by FrankenPHP with .env support and Coolify reverse proxy compatibility"

# Set environment variables
ENV DEBIAN_FRONTEND=noninteractive \
    SERVER_NAME=":80" \
    PORT="80"

# Install system dependencies & recommended PHP extensions
# Reference: https://webaloha.co/php-extensions-for-wordpress/
# Core: curl, dom, fileinfo, hash, json, mbstring, mysqli, openssl, pcre, sodium, xml, zip
# Recommended: bcmath, exif, gd, imagick, intl, opcache, redis, igbinary, pdo_mysql
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
    ghostscript \
    less \
    curl \
    tar \
    bash \
    unzip \
    default-mysql-client \
    && install-php-extensions \
    bcmath \
    exif \
    gd \
    intl \
    mysqli \
    pdo_mysql \
    zip \
    imagick \
    opcache \
    igbinary \
    && curl -fsSL https://github.com/phpredis/phpredis/archive/refs/tags/6.1.0.tar.gz -o /tmp/redis.tar.gz \
    && mkdir -p /tmp/redis \
    && tar -xzf /tmp/redis.tar.gz -C /tmp/redis --strip-components=1 \
    && cd /tmp/redis \
    && phpize \
    && ./configure --enable-redis-igbinary \
    && make -j$(nproc) \
    && make install \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/redis* \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install WP-CLI for easy management & automation via terminal
RUN curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp

# Copy custom PHP configuration
COPY config/wordpress.ini $PHP_INI_DIR/conf.d/wordpress.ini
RUN cp $PHP_INI_DIR/php.ini-production $PHP_INI_DIR/php.ini

# Download and extract WordPress core to /usr/src/wordpress for persistent volume initialization
RUN mkdir -p /usr/src/wordpress /var/www/html \
    && curl -fsSL -o /tmp/wordpress.tar.gz https://wordpress.org/latest.tar.gz \
    && tar -xzf /tmp/wordpress.tar.gz -C /usr/src/ \
    && rm -f /tmp/wordpress.tar.gz \
    && cp -rn /usr/src/wordpress/. /var/www/html/

# Copy custom Caddyfile
COPY Caddyfile /etc/caddy/Caddyfile

# Copy custom wp-config.php with .env and Coolify proxy support
COPY wp-config.php /usr/src/wordpress/wp-config.php
COPY wp-config.php /var/www/html/wp-config.php

# Copy entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Fix ownership
RUN chown -R www-data:www-data /var/www/html /usr/src/wordpress

WORKDIR /var/www/html

# Expose standard web port for Coolify
EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
