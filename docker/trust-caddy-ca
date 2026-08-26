#!/bin/sh

set -eu

ca_in_container=/data/caddy/pki/authorities/local/root.crt
ca_copy=$(mktemp "${TMPDIR:-/tmp}/snippet-caddy-root.XXXXXX")
ca_filename=snippet-caddy-local.crt

clean_up() {
	rm -f "$ca_copy"
}

trap clean_up EXIT HUP INT TERM

manual_install() {
	trap - EXIT HUP INT TERM
	printf '%s\n' 'Automatic certificate installation is not available on this host.' >&2
	printf 'Import this certificate into your browser or operating-system trust store: %s\n' "$ca_copy" >&2
	exit 1
}

run_as_root() {
	if [ "$(id -u)" -eq 0 ]; then
		"$@"
	elif command -v sudo >/dev/null 2>&1; then
		sudo "$@"
	else
		printf '%s\n' 'Installing a system certificate requires root access, but sudo is unavailable.' >&2
		manual_install
	fi
}

install_and_refresh() {
	anchors_directory=$1
	refresh_command=$2

	run_as_root install -m 0644 "$ca_copy" "${anchors_directory}/${ca_filename}"
	run_as_root "$refresh_command"
}

attempt=0
until docker compose --profile preview cp "caddy:${ca_in_container}" "$ca_copy" >/dev/null 2>&1; do
	attempt=$((attempt + 1))

	if [ "$attempt" -ge 30 ]; then
		printf '%s\n' 'Caddy did not create its local certificate authority in time.' >&2
		printf '%s\n' 'Inspect it with: docker compose --profile preview logs caddy' >&2
		exit 1
	fi

	sleep 1
done

kernel_name=$(uname -s)
kernel_release=$(uname -r)

case "$kernel_release" in
	*[Mm]icrosoft* | *WSL*)
		if ! command -v certutil.exe >/dev/null 2>&1 \
			|| ! command -v wslpath >/dev/null 2>&1; then
			manual_install
		fi

		ca_windows=$(wslpath -w "$ca_copy")
		certutil.exe -user -addstore Root "$ca_windows"
		;;
	*)
		case "$kernel_name" in
			Darwin)
				if ! command -v security >/dev/null 2>&1; then
					manual_install
				fi

				run_as_root security add-trusted-cert \
					-d \
					-r trustRoot \
					-k /Library/Keychains/System.keychain \
					"$ca_copy"
				;;
			Linux)
				if command -v update-ca-trust >/dev/null 2>&1 \
					&& [ -d /etc/ca-certificates/trust-source/anchors ]; then
					install_and_refresh /etc/ca-certificates/trust-source/anchors update-ca-trust
				elif command -v update-ca-trust >/dev/null 2>&1 \
					&& [ -d /etc/pki/ca-trust/source/anchors ]; then
					install_and_refresh /etc/pki/ca-trust/source/anchors update-ca-trust
				elif command -v update-ca-certificates >/dev/null 2>&1 \
					&& [ -d /etc/pki/trust/anchors ]; then
					install_and_refresh /etc/pki/trust/anchors update-ca-certificates
				elif command -v update-ca-certificates >/dev/null 2>&1 \
					&& [ -d /usr/local/share/ca-certificates ]; then
					install_and_refresh /usr/local/share/ca-certificates update-ca-certificates
				elif command -v trust >/dev/null 2>&1; then
					run_as_root trust anchor --store "$ca_copy"

					if command -v update-ca-trust >/dev/null 2>&1; then
						run_as_root update-ca-trust
					elif command -v update-ca-certificates >/dev/null 2>&1; then
						run_as_root update-ca-certificates
					fi
				else
					manual_install
				fi
				;;
			*)
				manual_install
				;;
		esac
		;;
esac

printf '\n%s\n' 'The Snippet local certificate is trusted.'
printf '%s\n' 'Close every browser window, reopen the browser, and visit the preview URL.'
