.DEFAULT_GOAL := help

COMPOSE ?= $(shell if docker compose version >/dev/null 2>&1; then printf '%s' 'docker compose'; elif docker-compose version >/dev/null 2>&1; then printf '%s' 'docker-compose'; else printf '%s' 'docker compose'; fi)
PULL ?= 0
NO_CACHE ?= 0
REMOVE_ORPHANS ?= 1

truthy = $(filter 1 true yes on,$(strip $(1)))
falsy = $(filter 0 false no off,$(strip $(1)))
BUILD_OPTIONS = $(if $(call truthy,$(PULL)),--pull) $(if $(call truthy,$(NO_CACHE)),--no-cache)
ORPHAN_OPTION = $(if $(call falsy,$(REMOVE_ORPHANS)),,--remove-orphans)

.PHONY: help docker-image docker-install docker-validate docker-build docker-preview docker-preview-trust docker-preview-down docker-shell docker-config docker-test docker-mutations docker-analyse docker-audit docker-lint docker-fix docker-check

help:
	@echo 'Snippet Docker commands'
	@echo
	@echo '  make docker-image          Build the selected application image'
	@echo '  make docker-install        Synchronize its isolated vendor volume'
	@echo '  make docker-validate       Validate site configuration and content'
	@echo '  make docker-build          Build host public/'
	@echo '  make docker-preview        Preview at https://localhost:$${PREVIEW_PORT:-8443}'
	@echo '  make docker-preview-trust  Trust local HTTPS once, then start preview'
	@echo '  make docker-preview-down   Stop preview; retain volumes and local CA'
	@echo '  make docker-shell          Open the development environment shell'
	@echo '  make docker-config         Render and validate the Compose configuration'
	@echo '  make docker-test           Run tests in development'
	@echo '  make docker-mutations      Require a 100% full-project mutation score'
	@echo '  make docker-analyse        Run PHPStan in development'
	@echo '  make docker-audit          Audit locked Composer dependencies'
	@echo '  make docker-lint           Check Pint and Rector in development'
	@echo '  make docker-fix            Apply Rector and Pint in development'
	@echo '  make docker-check          Run the complete gate in development'
	@echo
	@echo 'Copy .env.example to .env for local defaults. Shell and Make command-line'
	@echo 'variables override .env, for example:'
	@echo '  ENVIRONMENT=production make docker-build'
	@echo '  make docker-preview PREVIEW_PORT=9443'
	@echo '  make docker-image PULL=1 NO_CACHE=1'
	@echo
	@echo 'PULL=1 refreshes base images (and Caddy for preview); NO_CACHE=1 bypasses'
	@echo 'the application image build cache. Preview removes orphan containers by'
	@echo 'default; pass REMOVE_ORPHANS=0 to retain them.'

docker-image:
	$(COMPOSE) build $(BUILD_OPTIONS) app

docker-install: docker-image
	$(COMPOSE) run --rm --no-deps app sh -c 'if [ "$$ENVIRONMENT" = production ]; then composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader; else composer install --no-interaction --prefer-dist --optimize-autoloader; fi'

docker-validate: docker-install
	$(COMPOSE) run --rm --no-deps app bin/snippet validate

docker-build: docker-install
	$(COMPOSE) run --rm --no-deps --user "$$(id -u):$$(id -g)" app bin/snippet build

docker-preview: docker-install
	$(if $(call truthy,$(PULL)),$(COMPOSE) --profile preview pull caddy)
	$(COMPOSE) --profile preview up $(ORPHAN_OPTION)

docker-preview-trust: docker-install
	$(if $(call truthy,$(PULL)),$(COMPOSE) --profile preview pull caddy)
	$(COMPOSE) --profile preview up -d $(ORPHAN_OPTION)
	sh docker/trust-caddy-ca
	$(COMPOSE) --profile preview up $(ORPHAN_OPTION)

docker-preview-down:
	$(COMPOSE) --profile preview down $(ORPHAN_OPTION)

docker-shell:
	$(MAKE) ENVIRONMENT=development docker-install
	ENVIRONMENT=development $(COMPOSE) run --rm --no-deps app zsh

docker-config:
	$(COMPOSE) config

docker-test:
	$(MAKE) ENVIRONMENT=development docker-install
	ENVIRONMENT=development $(COMPOSE) run --rm --no-deps app composer app:test

docker-mutations:
	$(MAKE) ENVIRONMENT=development docker-install
	ENVIRONMENT=development $(COMPOSE) run --rm --no-deps app composer app:test:mutations

docker-analyse:
	$(MAKE) ENVIRONMENT=development docker-install
	ENVIRONMENT=development $(COMPOSE) run --rm --no-deps app composer app:analyse

docker-audit:
	$(MAKE) ENVIRONMENT=development docker-install
	ENVIRONMENT=development $(COMPOSE) run --rm --no-deps app composer app:audit

docker-lint:
	$(MAKE) ENVIRONMENT=development docker-install
	ENVIRONMENT=development $(COMPOSE) run --rm --no-deps app composer app:lint

docker-fix:
	$(MAKE) ENVIRONMENT=development docker-install
	ENVIRONMENT=development $(COMPOSE) run --rm --no-deps app composer app:fix

docker-check:
	$(MAKE) ENVIRONMENT=development docker-install
	ENVIRONMENT=development $(COMPOSE) run --rm --no-deps app composer app:check
	ENVIRONMENT=development $(COMPOSE) run --rm --no-deps app shellcheck .devcontainer/post-create.sh docker/devcontainer-entrypoint docker/trust-caddy-ca
	ENVIRONMENT=development $(COMPOSE) run --rm --no-deps app node --check resources/theme.js
