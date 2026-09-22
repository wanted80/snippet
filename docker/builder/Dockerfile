FROM composer:2@sha256:a5f59b9fd2faf31218632be4809dc6491761085e8064c31dc3b84378c48c248b AS composer

FROM php:8.5-cli-alpine@sha256:dae77e6aa4934d22b903da93e0e506c34032f5d8f8f91693d2cbf6e2724ddf73 AS dependencies

WORKDIR /app

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN apk add --no-cache unzip
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

FROM php:8.5-cli-alpine@sha256:dae77e6aa4934d22b903da93e0e506c34032f5d8f8f91693d2cbf6e2724ddf73 AS builder

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
COPY src/Inspection src/Inspection
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
COPY resources/workspace resources/workspace
COPY LICENSE LICENSE
COPY docker/builder/entrypoint.sh /usr/local/bin/snippet

RUN chmod -R a+rX /app \
    && chmod 0755 /usr/local/bin/snippet

WORKDIR /workspace

USER snippet

ENTRYPOINT ["snippet"]
CMD ["--version"]
