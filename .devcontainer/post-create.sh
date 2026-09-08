#!/usr/bin/env bash

set -euo pipefail

workspace=/app

sudo --non-interactive /usr/bin/chown -R snippet:snippet "${workspace}/vendor"

if ! git config --global --get-all safe.directory | grep -Fxq "${workspace}"; then
    git config --global --add safe.directory "${workspace}"
fi

cd "${workspace}"
composer install --no-interaction
