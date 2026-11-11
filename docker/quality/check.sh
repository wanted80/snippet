#!/bin/sh
set -eu

# Shared by Docker, devcontainer pushes, and CI. Run from the repository root.
composer app:check
shellcheck .githooks/pre-push .devcontainer/post-create.sh docker/builder/smoke.sh docker/demo/check.sh docker/demo/validate.sh docker/demo/workspace.sh docker/development/entrypoint.sh docker/preview/trust-caddy-ca.sh docker/quality/mutations.sh docker/development/install-node-dependencies.sh docker/quality/check.sh tests/Shell/preview-trust.sh tests/Shell/node-dependencies.sh tests/Shell/pre-push.sh
sh tests/Shell/preview-trust.sh
sh tests/Shell/node-dependencies.sh
sh tests/Shell/pre-push.sh
node --check resources/theme.js
composer app:test:assets
