# Stage 1: PHP dependencies
FROM php:8.4-cli-bookworm AS composer-builder
WORKDIR /app
COPY composer.json composer.lock ./
COPY packages ./packages

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions intl zip

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
RUN composer install --no-dev --optimize-autoloader --no-interaction \
    --ignore-platform-req=ext-gd --ignore-platform-req=ext-pdo_mysql --ignore-platform-req=ext-mysqli

# Stage 2: runtime
# No Node/Vite stage: this hub ships no bundled assets. No Chromium either —
# there is no PDF rendering here, unlike the portfolio project this is modelled on.
FROM php:8.4-apache-bookworm

WORKDIR /var/www/html

COPY --from=composer-builder /app/vendor ./vendor/

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf && \
    sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

RUN apt-get update && apt-get install -y --no-install-recommends \
        curl git zip unzip locales ca-certificates cron tzdata \
    && sed -i -e 's/# de_DE.UTF-8 UTF-8/de_DE.UTF-8 UTF-8/' /etc/locale.gen \
    && locale-gen \
    && ln -sf /usr/share/zoneinfo/Europe/Berlin /etc/localtime \
    && echo 'Europe/Berlin' > /etc/timezone \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

ENV LANG=de_DE.UTF-8
ENV LANGUAGE=de_DE:de
ENV LC_ALL=de_DE.UTF-8

# The container ran in UTC, so container logs, the cron schedule and PHP's
# date() were two hours off from the times shown in the interface (TYPO3
# renders in the user's timezone). Only the operator ever reads those, and
# they are all German — matching the wall clock beats explaining the offset.
ENV TZ=Europe/Berlin

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions intl \
    && install-php-extensions gd zip opcache pdo_mysql mysqli

RUN a2enmod rewrite headers expires

# The base image ships AllowOverride None, so TYPO3's public/.htaccess is never
# evaluated. Its rules are applied here instead.
#
# CGIPassAuth matters for the report endpoint: without it the Authorization
# header reaches apache_request_headers() but never $_SERVER, which is where
# PSR-7 — and therefore TYPO3 — looks for it. Every bearer token would be
# rejected with 401 while looking perfectly valid.
RUN printf '%s\n' \
    '<Directory /var/www/html/public>' \
    '    AllowOverride All' \
    '    Require all granted' \
    '    CGIPassAuth On' \
    '</Directory>' \
    > /etc/apache2/conf-available/typo3.conf \
    && a2enconf typo3

RUN { \
    # Without this PHP stays on UTC whatever TZ says: the base image pins
    # date.timezone, and an explicit ini setting wins over the environment.
    echo 'date.timezone=Europe/Berlin'; \
    echo 'opcache.memory_consumption=128'; \
    echo 'opcache.interned_strings_buffer=8'; \
    echo 'opcache.max_accelerated_files=4000'; \
    echo 'opcache.revalidate_freq=2'; \
    echo 'opcache.fast_shutdown=1'; \
    echo 'upload_max_filesize=32M'; \
    echo 'post_max_size=32M'; \
    echo 'memory_limit=512M'; \
    echo 'max_execution_time=240'; \
    } > /usr/local/etc/php/conf.d/typo3-recommendations.ini

# Application code — changes on almost every commit, so it stays last.
COPY --chown=www-data:www-data . .

# The COPY above carried broken symlinks from the gitignored vendor/ directory.
COPY --from=composer-builder --chown=www-data:www-data /app/vendor ./vendor

RUN mkdir -p var public/fileadmin public/uploads \
        public/typo3temp/assets/css public/typo3temp/assets/js \
        public/typo3temp/assets/images public/typo3temp/assets/_processed_ \
        config/system \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 var public/fileadmin public/uploads public/typo3temp config/system

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Probes the backend, which answers even with an empty page tree. / would return
# 404 until a site is configured, and curl treats that as failure — the container
# would stay unhealthy forever and Traefik would never route to it.
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl -fsS -o /dev/null -H "X-Forwarded-Proto: https" http://localhost/typo3/ || exit 1

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]

EXPOSE 80
