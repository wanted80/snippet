#!/bin/sh
set -eu

# Read as the checkout owner and extract as the image user, including mode-0600 files.
fixture=$(mktemp "${TMPDIR:-/tmp}/snippet-production.XXXXXX")
trap 'rm -f -- "$fixture"' EXIT
trap 'exit 1' HUP INT TERM
COPYFILE_DISABLE=1 tar -cf "$fixture" -C demo content site/config.php

docker run --rm -i --network none "$1" sh -ec '
    test "$(id -u):$(id -g)" = 1001:999
    # Only file contents and modes are needed from macOS archives.
    tar --warning=no-unknown-keyword -xf -
    chmod -R a-w content site
    bin/snippet validate
    bin/snippet build
    test -s public/index.html
' < "$fixture"
