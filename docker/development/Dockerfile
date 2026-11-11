FROM composer:2@sha256:ca11f643d7791ebdd90a34fc20caf11380d434007ae539a9ea75cd9212e13dca AS composer

FROM node:26-bookworm-slim@sha256:add5c17c14752c54d2f3d519ec46a3815684d41c33f198bcb09fb998f7dcbf68 AS node
RUN npm install --global npm@12.2.2

FROM php:8.5-cli@sha256:50956f094e90a716d43bea28087d447555ad80510e386ab8c9c10d8c70d781a9 AS php-base

ARG LOCAL_UID=1000
ARG LOCAL_GID=1000

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        ${PHPIZE_DEPS} \
    && docker-php-ext-install -j"$(nproc)" pcntl \
    && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false \
        ${PHPIZE_DEPS} \
    && groupadd --gid "${LOCAL_GID}" snippet \
    && useradd --uid "${LOCAL_UID}" --gid "${LOCAL_GID}" --create-home --shell /bin/sh snippet \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

FROM php-base AS production

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && chown -R snippet:snippet /app

COPY --chown=snippet:snippet . .

USER snippet

CMD ["bin/snippet", "validate"]

FROM php-base AS development

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
        jq \
        less \
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
        zsh \
        ${PHPIZE_DEPS} \
    && docker-php-ext-install -j"$(nproc)" sockets \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && printf '%s\n' 'pcov.enabled=0' > /usr/local/etc/php/conf.d/zz-pcov.ini \
    && npm install --global @openai/codex \
    && usermod --shell /usr/bin/zsh snippet \
    && printf '%s\n' 'snippet ALL=(root) NOPASSWD: /usr/bin/chown -R snippet\:snippet /app/vendor, /usr/bin/chown -R snippet\:snippet /app/node_modules, /usr/local/bin/npm install --global @openai/codex, /usr/local/bin/snippet-devcontainer-entrypoint' > /etc/sudoers.d/snippet \
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

COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --optimize-autoloader \
    && chown -R snippet:snippet /app

COPY --chown=snippet:snippet . .
COPY --chown=snippet:snippet docker/development/zshrc /home/snippet/.zshrc

USER snippet

CMD ["bin/snippet", "validate"]
