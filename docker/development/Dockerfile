FROM composer:2 AS composer

FROM php:8.5-cli AS php-base

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
        nodejs \
        npm \
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
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && printf '%s\n' 'pcov.enabled=0' > /usr/local/etc/php/conf.d/zz-pcov.ini \
    && npm install --global @openai/codex \
    && usermod --shell /usr/bin/zsh snippet \
    && printf '%s\n' 'snippet ALL=(root) NOPASSWD: /usr/bin/chown -R snippet\:snippet /app/vendor, /usr/bin/npm install --global @openai/codex, /usr/local/bin/snippet-devcontainer-entrypoint' > /etc/sudoers.d/snippet \
    && chmod 0440 /etc/sudoers.d/snippet \
    && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false ${PHPIZE_DEPS} \
    && apt-get install -y --no-install-recommends file \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

ENV SHELL=/usr/bin/zsh

COPY docker/development/entrypoint.sh /usr/local/bin/snippet-devcontainer-entrypoint
RUN chmod 0755 /usr/local/bin/snippet-devcontainer-entrypoint

COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --optimize-autoloader \
    && chown -R snippet:snippet /app

COPY --chown=snippet:snippet . .
COPY --chown=snippet:snippet docker/development/zshrc /home/snippet/.zshrc

USER snippet

CMD ["bin/snippet", "validate"]
