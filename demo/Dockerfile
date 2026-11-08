# Supertext Translation for AtroPIM: public demo (Railway). Build context = repository root:
#   docker build -f demo/Dockerfile -t supertext-atropim-demo .
# One container runs Apache with AtroPIM (AtroCore 2.4 + PIM 1.16) and AtroCore's job runner.
# MySQL 8.4 is a separate service; data/ and upload/ live on a volume at /data (demo/.env.example).
#
# Stages are ordered so that a change to the module only repeats the last stage, not the
# Composer install of AtroPIM.

# --- PHP 8.4 with AtroCore's extensions ----------------------------------------------------
FROM mirror.gcr.io/library/php:8.4-apache-bookworm AS php
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/download/2.13.2/install-php-extensions /usr/local/bin/
RUN install-php-extensions exif ftp gd imagick intl opcache pdo_mysql sockets zip \
 && apt-get update && apt-get install -y --no-install-recommends git unzip \
 && rm -rf /var/lib/apt/lists/* \
 && (a2dismod -f mpm_event mpm_worker >/dev/null 2>&1 || true) \
 && a2enmod mpm_prefork rewrite headers \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && printf 'memory_limit=-1\nmax_execution_time=0\nopcache.memory_consumption=192\nopcache.max_accelerated_files=40000\n' > "$PHP_INI_DIR/conf.d/atrocore.ini"
COPY --from=mirror.gcr.io/library/composer:2 /usr/bin/composer /usr/bin/composer
# PHP_PATH: AtroCore starts console subprocesses with it (otherwise it guesses from $_, which
# is runuser when the entrypoint runs PHP as www-data).
ENV COMPOSER_ALLOW_SUPERUSER=1 PHP_PATH=/usr/local/bin/php

# --- AtroPIM (Composer) ---------------------------------------------------------------------
FROM php AS atropim
WORKDIR /var/www/atro
COPY demo/project/composer.json /var/www/atro/composer.json
RUN composer install --no-dev --no-interaction --no-progress --no-scripts
# AtroCore's post-update script expects its installer next to composer.json.
ADD https://raw.githubusercontent.com/atrocore/skeleton-pim-no-demo/master/atrocore-installer.phar /var/www/atro/atrocore-installer.phar

# --- The Supertext module -------------------------------------------------------------------
FROM atropim AS build
COPY composer.json /opt/supertext-module/composer.json
COPY app /opt/supertext-module/app
COPY lib /opt/supertext-module/lib
RUN composer config repositories.supertext '{"type": "path", "url": "/opt/supertext-module", "options": {"symlink": false, "versions": {"supertext/atropim-supertext-translation": "dev-main"}}}' \
 && composer require 'supertext/atropim-supertext-translation:*@dev' --update-no-dev --no-interaction --no-progress --no-scripts \
 && composer dump-autoload --optimize --no-dev \
 && php -d error_reporting=24575 -r 'require "vendor/autoload.php"; \Atro\Composer\PostUpdate::postUpdate();' \
 && rm -rf data/cache data/logs && mkdir -p data/cache data/logs \
 && mv data data.dist && mv upload upload.dist \
 && chown -R www-data:www-data /var/www/atro

# --- Runtime -------------------------------------------------------------------------------
FROM php
COPY demo/docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY --from=build /var/www/atro /var/www/atro
COPY demo/docker/entrypoint.sh /usr/local/bin/demo-entrypoint
COPY demo/docker/console.sh /usr/local/bin/demo-console
COPY demo/docker/common.php demo/docker/install.php demo/docker/setup.php /usr/local/lib/supertext-demo/
RUN chmod +x /usr/local/bin/demo-entrypoint /usr/local/bin/demo-console \
 && echo 'Listen ${PORT}' > /etc/apache2/ports.conf \
 && sed -i 's/\*:8080/*:${PORT}/' /etc/apache2/sites-available/000-default.conf
WORKDIR /var/www/atro
ENV PORT=8080
EXPOSE 8080
CMD ["demo-entrypoint"]
