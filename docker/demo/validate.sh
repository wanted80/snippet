#!/bin/sh

set -eu

repository=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd -P)
workspace=$(mktemp -d /tmp/snippet-demo-validation.XXXXXX)

cleanup() {
    rm -rf -- "${workspace}"
}
trap cleanup EXIT HUP INT TERM

rmdir -- "${workspace}"
"${repository}/docker/demo/workspace.sh" "${workspace}"

SNIPPET_ENGINE_ROOT=${repository} \
SNIPPET_WORKSPACE=${workspace} \
php "${repository}/docker/builder/entrypoint.sh" validate
