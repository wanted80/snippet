FROM composer/composer:2.11.3-bin@sha256:2990de2e55e336422625cdac47b76dcf66a19e5b624d9c8b2f2c30a016242a58 AS composer

FROM node:26.13.1-trixie-slim@sha256:abe7e5a82b61a8ef67b315b85497c9e65173387ccf2f4a029248184b8e020be5 AS node
RUN npm install --global npm@12.2.3

FROM php:8.5-cli-alpine3.24@sha256:b9d7db51a2b83c41420ba04de0b90e6078d4b6440e5cc480fc4dc51b18e0b6db AS production

RUN apk add --no-cache --virtual .snippet-build-dependencies ${PHPIZE_DEPS} \
    && docker-php-ext-install -j"$(nproc)" pcntl \
    && apk del .snippet-build-dependencies

ARG LOCAL_UID=1000
ARG LOCAL_GID=1000

RUN { getent group "${LOCAL_GID}" >/dev/null || addgroup -g "${LOCAL_GID}" snippet; } \
    && adduser -D -u "${LOCAL_UID}" -G "$(getent group "${LOCAL_GID}" | cut -d: -f1)" snippet \
    && mkdir /app \
    && chown "${LOCAL_UID}:${LOCAL_GID}" /app

COPY --from=composer /composer /usr/local/bin/composer
WORKDIR /app
USER snippet

COPY --chown=${LOCAL_UID}:${LOCAL_GID} composer.json composer.lock ./
COPY --chown=${LOCAL_UID}:${LOCAL_GID} src src
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
COPY --chown=${LOCAL_UID}:${LOCAL_GID} LICENSE LICENSE
COPY --chown=${LOCAL_UID}:${LOCAL_GID} bin bin
COPY --chown=${LOCAL_UID}:${LOCAL_GID} resources resources
COPY --chown=${LOCAL_UID}:${LOCAL_GID} site site

CMD ["bin/snippet", "validate"]

FROM php:8.5-cli-trixie@sha256:dbf32b33595283b79fd1a37ebc1614bdf1b83d3750daad9a57d67dbb9b96ec16 AS development-account

ARG LOCAL_UID=1000
ARG LOCAL_GID=1000

# Reserve the host IDs before installing packages. Debian already uses some
# workstation GIDs, including the macOS staff GID (20) for dialout.
RUN { getent group "${LOCAL_GID}" >/dev/null || groupadd --gid "${LOCAL_GID}" snippet; } \
    && useradd --no-log-init --uid "${LOCAL_UID}" --gid "${LOCAL_GID}" --create-home --shell /bin/sh snippet

FROM development-account AS development

ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /app
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules/npm /usr/local/lib/node_modules/npm
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bat \
        bindfs \
        bubblewrap \
        curl \
        fd-find \
        file \
        fzf \
        gh \
        git \
        jq \
        less \
        libatomic1 \
        lsof \
        openssh-client \
        procps \
        python3 \
        ripgrep \
        rsync \
        shellcheck \
        sudo \
        time \
        tree \
        unzip \
        zsh \
        ${PHPIZE_DEPS} \
    && docker-php-ext-install -j"$(nproc)" pcntl sockets \
    && pecl install pcov-1.0.12 \
    && docker-php-ext-enable pcov \
    && printf '%s\n' 'pcov.enabled=0' > /usr/local/etc/php/conf.d/zz-pcov.ini \
    && npm install --global @openai/codex@0.172.0 \
    && usermod --shell /usr/bin/zsh snippet \
    && printf '%s\n' 'snippet ALL=(root) NOPASSWD: /usr/bin/chown -R snippet\: /app/vendor, /usr/bin/chown -R snippet\: /app/node_modules, /usr/local/bin/npm install --global @openai/codex, /usr/local/bin/snippet-devcontainer-entrypoint' > /etc/sudoers.d/snippet \
    && chmod 0440 /etc/sudoers.d/snippet \
    && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false ${PHPIZE_DEPS} \
    && apt-get install -y --no-install-recommends file libatomic1 make \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

# Browser tooling is isolated from /app mounts and never enters production.
ENV SHELL=/usr/bin/zsh \
    PLAYWRIGHT_BROWSERS_PATH=/opt/snippet-browser/browsers

COPY package.json package-lock.json ./
COPY docker/development/install-node-dependencies.sh docker/development/install-node-dependencies.sh
RUN sh docker/development/install-node-dependencies.sh \
    && npx --no-install playwright install --with-deps chromium firefox webkit \
    && chmod -R a+rX /opt/snippet-browser /app/node_modules \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY docker/development/entrypoint.sh /usr/local/bin/snippet-devcontainer-entrypoint
RUN chmod 0755 /usr/local/bin/snippet-devcontainer-entrypoint

COPY --from=composer /composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --optimize-autoloader \
    && chown -R snippet: /app

COPY --chown=${LOCAL_UID}:${LOCAL_GID} . .
COPY --chown=${LOCAL_UID}:${LOCAL_GID} docker/development/zshrc /home/snippet/.zshrc

USER snippet

CMD ["bin/snippet", "validate"]
