#!/bin/sh
set -eu

repository=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
trust_test_root=$(mktemp -d)
trap 'rm -rf "$trust_test_root"' EXIT HUP INT TERM
mkdir "$trust_test_root/bin"
export trust_test_log="$trust_test_root/commands"
export PATH="$trust_test_root/bin:$PATH"

cat > "$trust_test_root/bin/docker" <<'MOCK'
#!/bin/sh
if [ "${1:-}" = compose ] && [ "${2:-}" = version ]; then
    [ "$trust_test_variant" = plugin ]
    exit
fi
if [ "${0##*/}" = docker ] && [ "$trust_test_variant" != plugin ]; then
    exit 1
fi
printf '%s %s\n' "${0##*/}" "$*" >> "$trust_test_log"
for destination do :; done
printf 'test certificate\n' > "$destination"
MOCK
cp "$trust_test_root/bin/docker" "$trust_test_root/bin/docker-compose"
cp "$trust_test_root/bin/docker" "$trust_test_root/bin/custom-compose"
cat > "$trust_test_root/bin/uname" <<'MOCK'
#!/bin/sh
if [ "$trust_test_os" = WSL ]; then
    if [ "$1" = -r ]; then printf 'microsoft-standard-WSL2\n'; else printf 'Linux\n'; fi
else
    printf '%s\n' "$trust_test_os"
fi
MOCK
cat > "$trust_test_root/bin/id" <<'MOCK'
#!/bin/sh
printf '0\n'
MOCK
cat > "$trust_test_root/bin/security" <<'MOCK'
#!/bin/sh
printf 'security %s\n' "$*" >> "$trust_test_log"
for certificate do :; done
[ "$(cat "$certificate")" = 'test certificate' ]
MOCK
cat > "$trust_test_root/bin/sleep" <<'MOCK'
#!/bin/sh
exit 0
MOCK
cat > "$trust_test_root/bin/trust" <<'MOCK'
#!/bin/sh
printf 'trust %s\n' "$*" >> "$trust_test_log"
MOCK
cat > "$trust_test_root/bin/install" <<'MOCK'
#!/bin/sh
printf 'install %s\n' "$*" >> "$trust_test_log"
MOCK
cat > "$trust_test_root/bin/update-ca-certificates" <<'MOCK'
#!/bin/sh
printf 'refresh certificates\n' >> "$trust_test_log"
MOCK
cp "$trust_test_root/bin/update-ca-certificates" "$trust_test_root/bin/update-ca-trust"
cat > "$trust_test_root/bin/wslpath" <<'MOCK'
#!/bin/sh
printf '%s\n' "$2"
MOCK
cat > "$trust_test_root/bin/certutil.exe" <<'MOCK'
#!/bin/sh
printf 'certutil %s\n' "$*" >> "$trust_test_log"
for certificate do :; done
[ "$(cat "$certificate")" = 'test certificate' ]
MOCK
chmod +x "$trust_test_root/bin/"*

for trust_test_os in Darwin Linux WSL; do
export trust_test_os
for trust_test_variant in plugin standalone custom; do
    export trust_test_variant
    : > "$trust_test_log"
    if [ "$trust_test_variant" = custom ]; then
        sh "$repository/docker/preview/trust-caddy-ca.sh" custom-compose --context local >/dev/null
        expected='custom-compose --context local --profile preview cp'
    else
        sh "$repository/docker/preview/trust-caddy-ca.sh" >/dev/null
        if [ "$trust_test_variant" = plugin ]; then
            expected='docker compose --profile preview cp'
        else
            expected='docker-compose --profile preview cp'
        fi
    fi
    grep -F "$expected" "$trust_test_log" >/dev/null
    case "$trust_test_os" in
        Darwin) grep -F 'security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain' "$trust_test_log" >/dev/null ;;
        Linux) grep -E 'trust anchor --store|refresh certificates' "$trust_test_log" >/dev/null ;;
        WSL) grep -F 'certutil -user -addstore Root' "$trust_test_log" >/dev/null ;;
    esac
    printf 'PASS: %s certificate trust using %s\n' "$trust_test_os" "$trust_test_variant"
done
done
