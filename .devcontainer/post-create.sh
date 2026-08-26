#!/usr/bin/env bash

set -euo pipefail

workspace=/app
codex_home=/home/snippet/.codex

sudo --non-interactive /usr/bin/chown -R snippet:snippet "${workspace}/vendor"
mkdir -p "${codex_home}" /home/snippet/.zfunc

if ! git config --global --get-all safe.directory | grep -Fxq "${workspace}"; then
    git config --global --add safe.directory "${workspace}"
fi

codex completion zsh > /home/snippet/.zfunc/_codex

if ! grep -Fq '[projects."/app"]' "${codex_home}/config.toml" 2>/dev/null; then
    {
        printf '\n[projects."/app"]\n'
        printf 'trust_level = "trusted"\n'
    } >> "${codex_home}/config.toml"
fi

cd "${workspace}"
composer install --no-interaction
