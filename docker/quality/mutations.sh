#!/bin/sh

set -eu

source_root=$(pwd -P)
mutation_root=$(mktemp -d /tmp/snippet-mutations.XXXXXX)
before_status=$(mktemp /tmp/snippet-mutations-before.XXXXXX)
after_status=$(mktemp /tmp/snippet-mutations-after.XXXXXX)

cleanup() {
    result=$?
    trap - EXIT HUP INT TERM
    set +e

    git -C "${source_root}" status --porcelain=v1 --untracked-files=all > "${after_status}"
    if ! cmp -s "${before_status}" "${after_status}"; then
        echo 'Host checkout changed during isolated mutation testing.' >&2
        diff -u "${before_status}" "${after_status}" >&2
        result=1
    fi

    rm -rf -- "${mutation_root}" "${before_status}" "${after_status}"
    exit "${result}"
}

trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

git -C "${source_root}" status --porcelain=v1 --untracked-files=all > "${before_status}"
rsync -a \
    --exclude=.git/ \
    --exclude=public/ \
    --exclude='.snippet-build-*' \
    --exclude='.snippet-backup-*' \
    "${source_root}/" "${mutation_root}/"

cd "${mutation_root}"
php -d memory_limit=512M \
    -d pcov.enabled=1 \
    -d pcov.directory=src \
    vendor/bin/pest --no-tia --mutate --everything --covered-only --min=100
