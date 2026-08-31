#!/bin/sh

set -eu

image=${1:-snippet-builder:smoke}
repository=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd -P)
workspace=$(mktemp -d "${repository}/.snippet-builder-smoke.XXXXXX")

cleanup() {
    rm -rf -- "${workspace}"
}

trap cleanup EXIT HUP INT TERM

run_builder() {
    docker run --rm \
        --network none \
        --read-only \
        --cap-drop ALL \
        --security-opt no-new-privileges \
        --user "$(id -u):$(id -g)" \
        --mount "type=bind,source=${workspace},destination=/workspace" \
        --tmpfs /tmp:rw,noexec,nosuid,nodev,size=16m \
        "${image}" "$@"
}

test "$(docker run --rm --entrypoint id "${image}" -u)" != 0
test "$(docker run --rm --entrypoint php "${image}" -r 'echo ini_get("memory_limit");')" = 512M
test "$(docker run --rm --entrypoint php "${image}" -r 'echo ini_get("max_execution_time");')" = 0
test "$(docker run --rm --entrypoint php "${image}" -r 'echo date_default_timezone_get();')" = UTC
test "$(docker run --rm --entrypoint php "${image}" -r 'echo ini_get("default_charset");')" = UTF-8
test "$(docker run --rm --entrypoint php "${image}" -r 'echo ini_get("allow_url_fopen");')" = ""
test "$(docker run --rm --entrypoint php "${image}" -r 'echo ini_get("display_errors");')" = stderr
test "$(docker run --rm --entrypoint php "${image}" -r 'echo ini_get("error_reporting");')" = "$(docker run --rm --entrypoint php "${image}" -r 'echo E_ALL;')"
test "$(docker run --rm --entrypoint php "${image}" -r 'echo ini_get("log_errors");')" = ""
test "$(docker run --rm --entrypoint php "${image}" -r 'echo ini_get("zend.assertions");')" = -1

extensions=$(docker run --rm --entrypoint php "${image}" -m 2>&1)
printf '%s\n' "${extensions}" | grep -Fxq mbstring
if printf '%s\n' "${extensions}" | grep -Fq 'Module "mbstring" is already loaded'; then
    echo 'Builder loads mbstring more than once.' >&2
    exit 1
fi

test "$(docker run --rm \
    --network none \
    --read-only \
    --cap-drop ALL \
    --security-opt no-new-privileges \
    --user 12345:23456 \
    --entrypoint php \
    "${image}" \
    -r 'require "/app/vendor/autoload.php"; echo Snippet\Support\ApplicationVersion::CURRENT;')" != ''

docker run --rm --entrypoint sh "${image}" -c '
    ! command -v composer >/dev/null 2>&1
    ! command -v git >/dev/null 2>&1
    ! command -v node >/dev/null 2>&1
    test ! -e /app/tests
    test ! -e /app/docs
    test -e /app/src/Authoring/DraftCreator.php
    test ! -e /app/src/Preview
    test ! -e /app/resources/preview-router.php
'

run_builder --version

if run_builder new article before-init --date=2026-08-17 >/dev/null 2>&1; then
    echo 'Builder created a draft before workspace initialization.' >&2
    exit 1
fi

test ! -e "${workspace}/content"

run_builder init
test "$(find "${workspace}/content/articles" -name article.md -type f | wc -l | tr -d ' ')" = 0
test "$(find "${workspace}/content/pages" -name page.md -type f | wc -l | tr -d ' ')" = 0
test -f "${workspace}/site/site.css"
test -f "${workspace}/site/assets/fonts/snippet-logo/snippet-logo.woff2"
run_builder validate
run_builder build

test -f "${workspace}/public/index.html"
test -f "${workspace}/public/assets/theme.css"
test -f "${workspace}/public/assets/theme.js"
test ! -e "${workspace}/resources/preview-router.php"

run_builder new page contact
run_builder new article first-post --date=2026-08-17

test -f "${workspace}/content/pages/contact/page.md"
test -f "${workspace}/content/pages/contact/meta.php"
test -f "${workspace}/content/articles/2026/08/17/first-post/article.md"
grep -Fq "'date' => '2026-08-17'" "${workspace}/content/articles/2026/08/17/first-post/meta.php"

if run_builder preview >/dev/null 2>&1; then
    echo 'Builder unexpectedly accepted preview.' >&2
    exit 1
fi
