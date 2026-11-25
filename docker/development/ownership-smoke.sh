#!/bin/sh
set -eu

# Exercise the same account stage the full image inherits, including a GID
# already in Debian, an ordinary Linux/WSL account, and a runner account.
for identity in 501:20 1000:1000 1001:999; do
    image=$(docker build --quiet --load "$@" --target development-account \
        --file docker/development/Dockerfile \
        --build-arg LOCAL_UID="${identity%:*}" \
        --build-arg LOCAL_GID="${identity#*:}" .)
    docker run --rm --network none --read-only --user snippet "$image" sh -ec '
        test "$(id -u):$(id -g)" = "$1"
        test "$(stat -c %u:%g /home/snippet)" = "$1"
    ' sh "$identity"
    printf 'Verified development UID:GID %s\n' "$identity"
done
