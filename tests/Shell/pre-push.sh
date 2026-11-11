#!/bin/sh
set -eu

repository=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
hook_test_root=$(mktemp -d)
trap 'rm -rf "$hook_test_root"' EXIT HUP INT TERM
mkdir "$hook_test_root/bin"
export hook_test_log="$hook_test_root/commands"
export PATH="$hook_test_root/bin:$PATH"
cat > "$hook_test_root/bin/git" <<'MOCK'
#!/bin/sh
set -eu
case "$*" in
    'rev-parse --show-toplevel') printf '%s\n' "$hook_test_repository" ;;
esac
MOCK
cat > "$hook_test_root/bin/make" <<'MOCK'
#!/bin/sh
set -eu
printf '%s\n' "$*" >> "$hook_test_log"
exit "${hook_test_failure:-0}"
MOCK
chmod +x "$hook_test_root/bin/git" "$hook_test_root/bin/make"
export hook_test_repository="$repository"

sh "$repository/.githooks/pre-push" </dev/null
[ "$(cat "$hook_test_log")" = 'pre-push-check' ]
printf 'PASS: pre-push runs the shared quality gate\n'

: > "$hook_test_log"
if hook_test_failure=42 sh "$repository/.githooks/pre-push" </dev/null; then
    printf 'FAIL: pre-push allowed a failed quality gate\n' >&2
    exit 1
else
    [ "$?" = 42 ]
fi
printf 'PASS: a failed check blocks the push\n'
