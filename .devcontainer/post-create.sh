#!/usr/bin/env bash

set -euo pipefail

workspace=/app

sudo --non-interactive /usr/bin/chown -R snippet:snippet "${workspace}/vendor"
sudo --non-interactive /usr/bin/chown -R snippet:snippet "${workspace}/node_modules"

if ! git config --global --get-all safe.directory | grep -Fxq "${workspace}"; then
    git config --global --add safe.directory "${workspace}"
fi

cd "${workspace}"
composer install --no-interaction
sh docker/development/install-node-dependencies.sh
