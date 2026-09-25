FROM alpine:3.22 AS source

RUN apk add --no-cache curl unzip \
 && curl -fL --retry 3 -o /tmp/projeqtor.zip \
      https://sourceforge.net/projects/projectorria/files/projeqtorV13.1.0.zip/download \
 && echo "221c2a0b2facbdfc0b5af9878e030cdd7ded6e3f989b1da9cd609eccebaa0a69  /tmp/projeqtor.zip" | sha256sum -c - \
 && unzip -q /tmp/projeqtor.zip -d /opt

FROM php:8.3-apache-bookworm

RUN apt-get update \
 && apt-get install -y --no-install-recommends curl libc-client-dev libfreetype6-dev libicu-dev libjpeg62-turbo-dev libkrb5-dev libldap2-dev libonig-dev libpng-dev libpq-dev libzip-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-configure imap --with-kerberos --with-imap-ssl \
 && docker-php-ext-configure ldap \
 && docker-php-ext-install -j"$(nproc)" gd imap intl ldap mbstring opcache pdo_pgsql pgsql zip \
 && a2enmod rewrite headers expires \
 && rm -rf /var/lib/apt/lists/*

COPY --from=source --chown=www-data:www-data /opt/projeqtor/ /var/www/html/
COPY deployment/app/php.ini /usr/local/etc/php/conf.d/projeqtor.ini
COPY deployment/app/parametersLocation.php /var/www/html/tool/parametersLocation.php
COPY deployment/app/api.htaccess /var/www/html/api/.htaccess
COPY bridge/ /var/www/html/mcp-api/
COPY deployment/app/mcp-api.htaccess /var/www/html/mcp-api/.htaccess
COPY deployment/app/api-apache.conf /etc/apache2/conf-available/projeqtor-api.conf
COPY deployment/app/entrypoint.sh /usr/local/bin/projeqtor-entrypoint
COPY worker/mcp-worker.php /usr/local/lib/projeqtor/mcp-worker.php
COPY worker/mcp-cron.php /usr/local/lib/projeqtor/mcp-cron.php
COPY worker/cron-control.php /usr/local/lib/projeqtor/cron-control.php
COPY worker/entrypoint.sh /usr/local/bin/projeqtor-worker-entrypoint
COPY worker/healthcheck.php /usr/local/lib/projeqtor/worker-healthcheck.php

RUN chmod 0755 /usr/local/bin/projeqtor-entrypoint /usr/local/bin/projeqtor-worker-entrypoint \
 && chmod 0644 /usr/local/lib/projeqtor/*.php \
 && sed -ri 's!^[[:space:]]*CustomLog .*!CustomLog /proc/self/fd/1 projeqtor_safe!' /etc/apache2/sites-available/000-default.conf \
 && a2enconf projeqtor-api \
 && find /var/www/html/mcp-api /usr/local/lib/projeqtor -type f -name '*.php' -exec php -l {} \;

ENTRYPOINT ["/usr/local/bin/projeqtor-entrypoint"]
CMD ["apache2-foreground"]
