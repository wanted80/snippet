#!/bin/sh
set -eu

repository=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
node_test_root=$(mktemp -d)
trap 'rm -rf "$node_test_root"' EXIT HUP INT TERM
mkdir "$node_test_root/bin" "$node_test_root/workspace"
export node_test_log="$node_test_root/commands"
export PATH="$node_test_root/bin:$PATH"

cat > "$node_test_root/bin/npm" <<'MOCK'
#!/bin/sh
set -eu
printf '%s\n' "$*" >> "$node_test_log"
if [ "${node_test_fail:-0}" = 1 ]; then exit 42; fi
mkdir -p node_modules/.bin
printf '#!/bin/sh\nexit 0\n' > node_modules/.bin/playwright
chmod +x node_modules/.bin/playwright
MOCK
chmod +x "$node_test_root/bin/npm"

cd "$node_test_root/workspace"
printf '{"private":true}\n' > package.json
printf '{"lockfileVersion":3}\n' > package-lock.json

sync_dependencies() {
    sh "$repository/docker/development/install-node-dependencies.sh"
}

sync_dependencies
[ "$(cat "$node_test_log")" = 'ci --no-audit --no-fund' ]
printf 'PASS: installs missing dependencies\n'

: > "$node_test_log"
printf 'keep installed files\n' > node_modules/sentinel
node_test_fail=1 sync_dependencies
[ ! -s "$node_test_log" ]
[ "$(cat node_modules/sentinel)" = 'keep installed files' ]
printf 'PASS: unchanged dependencies need no npm or network\n'

for manifest in package.json package-lock.json; do
    : > "$node_test_log"
    printf '\n' >> "$manifest"
    sync_dependencies
    [ "$(cat "$node_test_log")" = 'ci --no-audit --no-fund' ]
    : > "$node_test_log"
    node_test_fail=1 sync_dependencies
    [ ! -s "$node_test_log" ]
    printf 'PASS: synchronizes changes to %s once\n' "$manifest"
done

: > "$node_test_log"
rm node_modules/.bin/playwright
sync_dependencies
[ "$(cat "$node_test_log")" = 'ci --no-audit --no-fund' ]
printf 'PASS: restores a missing Playwright executable\n'

cp package-lock.json "$node_test_root/original-lock.json"
printf '\n' >> package-lock.json
if node_test_fail=1 sync_dependencies; then
    printf 'FAIL: swallowed npm installation failure\n' >&2
    exit 1
else
    [ "$?" = 42 ]
fi
cp "$node_test_root/original-lock.json" package-lock.json
: > "$node_test_log"
sync_dependencies
[ "$(cat "$node_test_log")" = 'ci --no-audit --no-fund' ]
printf 'PASS: failed installs cannot leave dependencies marked current\n'
