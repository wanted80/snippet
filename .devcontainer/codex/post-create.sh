#!/usr/bin/env bash

set -euo pipefail

/app/.devcontainer/post-create.sh

codex_home=/home/snippet/.codex
mkdir -p "${codex_home}" /home/snippet/.zfunc
codex completion zsh > /home/snippet/.zfunc/_codex

if ! grep -Fq '[projects."/app"]' "${codex_home}/config.toml" 2>/dev/null; then
    {
        printf '\n[projects."/app"]\n'
        printf 'trust_level = "trusted"\n'
    } >> "${codex_home}/config.toml"
fi
