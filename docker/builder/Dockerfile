FROM composer:2@sha256:d8f6343d3fae98107426bc49163ccad46ef85aabd4a27d80a74401fab4aba332 AS composer

FROM php:8.5-cli-alpine@sha256:aa66ee4275e0472bafcd18e35142499fcbda6ac90e641e751418e052e6a0d812 AS dependencies

WORKDIR /app

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN apk add --no-cache unzip
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

FROM php:8.5-cli-alpine@sha256:aa66ee4275e0472bafcd18e35142499fcbda6ac90e641e751418e052e6a0d812 AS builder

RUN apk add --no-cache --virtual .snippet-build-dependencies ${PHPIZE_DEPS} \
    && docker-php-ext-install -j"$(nproc)" pcntl \
    && apk del .snippet-build-dependencies \
    && addgroup -S snippet \
    && adduser -S -G snippet snippet \
    && mkdir /workspace \
    && chown snippet:snippet /workspace

COPY docker/builder/php.ini /usr/local/etc/php/conf.d/snippet.ini

WORKDIR /app

COPY --from=dependencies /app/vendor /app/vendor
COPY src/Application.php src/Application.php
COPY src/Authoring src/Authoring
COPY src/Cli src/Cli
COPY src/Content src/Content
COPY src/Exception src/Exception
COPY src/Markdown src/Markdown
COPY src/Publishing src/Publishing
COPY src/Preview src/Preview
COPY src/Rendering src/Rendering
COPY src/Scaffolding src/Scaffolding
COPY src/Site src/Site
COPY src/Support src/Support
COPY site site
COPY resources/theme.css resources/theme.css
COPY resources/theme.js resources/theme.js
COPY resources/preview-router.php resources/preview-router.php
COPY resources/templates resources/templates
COPY LICENSE LICENSE
COPY docker/builder/entrypoint.sh /usr/local/bin/snippet

RUN chmod -R a+rX /app \
    && chmod 0755 /usr/local/bin/snippet

WORKDIR /workspace

USER snippet

ENTRYPOINT ["snippet"]
CMD ["--version"]
