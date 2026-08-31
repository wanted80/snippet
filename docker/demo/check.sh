#!/bin/sh

set -eu

image=${1:-snippet-builder:smoke}
output=${2:-}
repository=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd -P)
workspace=$(mktemp -d "${repository}/.snippet-demo.XXXXXX")

cleanup() {
    rm -rf -- "${workspace}"
}
trap cleanup EXIT HUP INT TERM

rmdir -- "${workspace}"
"$(dirname -- "$0")/workspace.sh" "${workspace}"

run_builder() {
    docker run --rm \
        --network none \
        --read-only \
        --cap-drop ALL \
        --security-opt no-new-privileges \
        --user "$(id -u):$(id -g)" \
        --volume "${workspace}:/workspace" \
        --tmpfs /tmp:rw,noexec,nosuid,nodev,size=16m \
        "${image}" "$@"
}

run_builder validate
run_builder build

if [ -n "${output}" ]; then
    if [ -e "${output}" ] || [ -L "${output}" ]; then
        echo "Demo output destination already exists: ${output}" >&2
        exit 1
    fi

    cp -R -- "${workspace}/public" "${output}"
fi
