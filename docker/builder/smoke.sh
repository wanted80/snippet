#!/bin/sh

set -eu

image=${1:-snippet-builder:smoke}
repository=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd -P)
workspace=$(mktemp -d "${repository}/.snippet-builder-smoke.XXXXXX")
preview_container=

cleanup() {
    if [ -n "${preview_container}" ] && docker inspect "${preview_container}" >/dev/null 2>&1; then
        docker rm --force "${preview_container}" >/dev/null 2>&1
    fi
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

preview_request() {
    docker exec "${preview_container}" php -r '
        $socket = @stream_socket_client("tcp://127.0.0.1:8080", $errorCode, $errorMessage, 1);
        if (!is_resource($socket)) {
            exit(1);
        }
        fwrite($socket, "GET {$argv[1]} HTTP/1.0\r\nHost: localhost\r\nConnection: close\r\n\r\n");
        $response = stream_get_contents($socket);
        fclose($socket);
        if (!is_string($response)) {
            exit(1);
        }
        echo $response;
    ' "$1"
}

wait_for_preview() {
    preview_path=$1
    preview_expected=$2
    preview_attempt=0
    while [ "${preview_attempt}" -lt 50 ]; do
        preview_response=$(preview_request "${preview_path}" 2>/dev/null || true)
        if printf '%s' "${preview_response}" | grep -Fq "${preview_expected}"; then
            return 0
        fi
        preview_attempt=$((preview_attempt + 1))
        sleep 0.1
    done

    echo "Builder preview did not serve '${preview_expected}' at '${preview_path}'." >&2
    docker logs "${preview_container}" >&2
    return 1
}

wait_for_preview_log() {
    preview_expected=$1
    preview_attempt=0
    while [ "${preview_attempt}" -lt 50 ]; do
        if docker logs "${preview_container}" 2>&1 | grep -Fq "${preview_expected}"; then
            return 0
        fi
        preview_attempt=$((preview_attempt + 1))
        sleep 0.1
    done

    echo "Builder preview did not log '${preview_expected}'." >&2
    docker logs "${preview_container}" >&2
    return 1
}

replace_workspace_text() {
    docker exec "${preview_container}" php -r '
        $path = "/workspace/" . $argv[1];
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            exit(1);
        }
        $updated = str_replace($argv[2], $argv[3], $contents, $replacements);
        if ($replacements === 0 || file_put_contents($path, $updated) === false) {
            exit(1);
        }
    ' "$1" "$2" "$3"
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
test "$(docker run --rm --entrypoint php "${image}" -r 'echo function_exists("proc_open") ? "yes" : "no";')" = yes
test "$(docker run --rm --entrypoint php "${image}" -r 'echo function_exists("pcntl_signal") ? "yes" : "no";')" = yes

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
    test ! -e /app/.git
    test ! -e /app/composer.json
    test ! -e /app/composer.lock
    test ! -e /app/README.md
    test ! -e /app/INSTALL.md
    test ! -e /app/tests
    test ! -e /app/docs
    test ! -e /app/vendor/bin/pest
    test -e /app/src/Authoring/DraftCreator.php
    test -e /app/src/Preview/Previewer.php
    test -e /app/src/Preview/PreviewServer.php
    test "$(find /app/src/Preview -type f | wc -l | tr -d " ")" = 2
    test -f /app/resources/preview-router.php
    test ! -L /app/resources/preview-router.php
    test -r /app/resources/preview-router.php
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
test ! -e "${workspace}/public/assets/theme.css"
test ! -e "${workspace}/public/assets/theme.js"
set -- "${workspace}"/public/assets/theme.*.css
test "$#" -eq 1
test -f "$1"
basename "$1" | grep -Eq '^theme\.[0-9a-f]{16}\.css$'
set -- "${workspace}"/public/assets/theme.*.js
test "$#" -eq 1
test -f "$1"
basename "$1" | grep -Eq '^theme\.[0-9a-f]{16}\.js$'
test ! -e "${workspace}/resources/preview-router.php"

if run_builder preview --port=0 >/dev/null 2>&1; then
    echo 'Builder accepted an invalid preview port.' >&2
    exit 1
fi

preview_container="snippet-builder-preview-smoke-$$"
docker run --detach \
    --name "${preview_container}" \
    --init \
    --read-only \
    --cap-drop ALL \
    --security-opt no-new-privileges \
    --pids-limit 64 \
    --cpus 2 \
    --user "$(id -u):$(id -g)" \
    --publish 127.0.0.1::8080 \
    --mount "type=bind,source=${workspace},destination=/workspace" \
    --tmpfs /tmp:rw,noexec,nosuid,nodev,size=16m \
    "${image}" preview --host=0.0.0.0 --port=8080 >/dev/null

docker port "${preview_container}" 8080/tcp | grep -Eq '^127\.0\.0\.1:[0-9]+$'
wait_for_preview / '<title>My Snippet</title>'
preview_response=$(preview_request /missing/)
printf '%s' "${preview_response}" | grep -Eq '^HTTP/[0-9.]+ 404 '
printf '%s' "${preview_response}" | grep -Fq '<h1 id="not-found-title">Page not found</h1>'
printf '%s' "${preview_response}" | grep -Fq '/.snippet-preview-reload.js'
preview_response=$(preview_request /.snippet-preview-version)
printf '%s' "${preview_response}" | grep -Eq '[a-f0-9]{16}'
preview_response=$(preview_request /.snippet-preview-reload.js)
printf '%s' "${preview_response}" | grep -Fq '.snippet-preview-version'

replace_workspace_text site/config.php 'My Snippet' 'Preview Updated'
wait_for_preview / '<title>Preview Updated</title>'

replace_workspace_text site/config.php "'language' => 'en'" "'language' => ''"
wait_for_preview_log 'Keeping the last valid site.'
preview_response=$(preview_request /)
printf '%s' "${preview_response}" | grep -Fq '<title>Preview Updated</title>'

replace_workspace_text site/config.php 'Preview Updated' 'Preview Recovered'
replace_workspace_text site/config.php "'language' => ''" "'language' => 'en'"
wait_for_preview / '<title>Preview Recovered</title>'

replace_workspace_text site/config.php 'https://example.com' 'https://example.com/snippet'
wait_for_preview /snippet/ '<title>Preview Recovered</title>'
wait_for_preview_log 'Restarting preview for the updated deployment path.'

docker stop --time 10 "${preview_container}" >/dev/null
test "$(docker inspect --format '{{.State.Running}}' "${preview_container}")" = false
test "$(docker inspect --format '{{.State.ExitCode}}' "${preview_container}")" = 143
docker rm "${preview_container}" >/dev/null
preview_container=

run_builder build
test ! -e "${workspace}/public/.snippet-preview-version"
if grep -R -Fq '.snippet-preview-reload.js' "${workspace}/public"; then
    echo 'Production output contains preview-only live reload support.' >&2
    exit 1
fi
test "$(docker run --rm \
    --network none \
    --read-only \
    --cap-drop ALL \
    --security-opt no-new-privileges \
    --user "$(id -u):$(id -g)" \
    --mount "type=bind,source=${workspace},destination=/workspace" \
    --entrypoint php \
    "${image}" \
    -r 'echo fileowner("/workspace/public/index.html");')" = "$(id -u)"

run_builder new page contact
run_builder new article first-post --date=2026-08-17

test -f "${workspace}/content/pages/contact/page.md"
test -f "${workspace}/content/pages/contact/meta.php"
test -f "${workspace}/content/articles/2026/08/17/first-post/article.md"
grep -Fq "'date' => '2026-08-17'" "${workspace}/content/articles/2026/08/17/first-post/meta.php"
