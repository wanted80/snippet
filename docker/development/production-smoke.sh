#!/bin/sh
set -eu

# Keep the temporary checkout on the same Docker-shared filesystem as the repository.
fixture=$(mktemp -d "$PWD/.snippet-build-production-XXXXXX")
trap 'rm -rf -- "$fixture"' EXIT
trap 'exit 1' HUP INT TERM
mkdir "$fixture/site"
cp demo/site/config.php "$fixture/site/config.php"
chmod 600 "$fixture/site/config.php"
COPYFILE_DISABLE=1 tar -cf "$fixture/inputs.tar" -C "$PWD/demo" content -C "$fixture" site/config.php

docker run --rm -i --network none "$1" sh -ec '
    test "$(id -u):$(id -g)" = 1001:999
    # Only file contents and modes are needed from macOS archives.
    tar --warning=no-unknown-keyword -xf -
    test "$(stat -c %a site/config.php)" = 600
    chmod -R a-w content site
    bin/snippet validate
    bin/snippet build
    test -s public/index.html
' < "$fixture/inputs.tar"

# An older .env can pin the image account even when another user owns the checkout.
checkout=$fixture/checkout
sh docker/demo/workspace.sh "$checkout"
cp -R bin src resources "$checkout/"
chmod 0755 "$checkout"
# GNU chmod preserves a directory's inherited setgid bit with mode 0755.
chmod g-s "$checkout"
host_uid=$(id -u)
host_gid=$(id -g)
image_uid=1000
if [ "$host_uid" -eq "$image_uid" ]; then image_uid=1001; fi
printf 'LOCAL_UID=%s\nLOCAL_GID=%s\n' "$image_uid" "$image_uid" > "$checkout/.env"
cat > "$checkout/compose.yaml" <<'YAML'
services:
  app:
    image: ${SNIPPET_SMOKE_IMAGE}
    user: "${LOCAL_UID}:${LOCAL_GID}"
    network_mode: none
    entrypoint:
      - sh
      - -ec
      - |
        actual=$$(id -u):$$(id -g)
        if [ "$$actual" != "${SNIPPET_EXPECTED_OWNER}" ]; then
          echo "Expected build user ${SNIPPET_EXPECTED_OWNER}, got $$actual" >&2
          exit 1
        fi
        exec "$$@"
      - sh
    volumes:
      - .:/app
      - /app/vendor
YAML

# The packaged image already contains the installed production dependencies.
makefile=$PWD/Makefile
(
    cd "$checkout"
    unset LOCAL_UID LOCAL_GID MAKEFLAGS
    export SNIPPET_SMOKE_IMAGE="$1" SNIPPET_EXPECTED_OWNER="$host_uid:$host_gid"
    COMPOSE_PROJECT_NAME="snippet-ownership-$$" COMPOSE_FILE=compose.yaml \
        make -f "$makefile" --old-file=docker-install docker-build
)
test -s "$checkout/public/index.html"
wrong_owner=$(find "$checkout/public" ! \( -user "$host_uid" -group "$host_gid" \) -print)
if [ -n "$wrong_owner" ]; then
    printf 'Expected publication owner %s:%s for:\n%s\n' "$host_uid" "$host_gid" "$wrong_owner" >&2
    exit 1
fi
