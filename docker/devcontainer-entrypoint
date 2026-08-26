#!/bin/sh

set -eu

codex_home=/home/snippet/.codex
codex_tmp=/tmp/snippet-codex-tmp
host_codex_home=/mnt/snippet-host-codex

if [ "$(/usr/bin/id --user)" -ne 0 ]; then
    exec /usr/bin/sudo --non-interactive /usr/local/bin/snippet-devcontainer-entrypoint "$@"
fi

if [ ! -d "${host_codex_home}" ] \
    || [ -z "$(/usr/bin/find "${host_codex_home}" -mindepth 1 -maxdepth 1 -print -quit)" ]; then
    printf '%s\n' \
        "The Docker engine cannot read the host Codex home at ${host_codex_home}." \
        'Share ~/.codex with the Docker VM, then rebuild the devcontainer.' \
        "For Colima, add ~/.codex as a writable mount with 'colima start --edit'." >&2
    exit 1
fi

if [ ! -w "${host_codex_home}" ]; then
    printf '%s\n' \
        "The host Codex home at ${host_codex_home} is not writable." \
        'Expose ~/.codex to Docker as a writable host path, then rebuild the devcontainer.' >&2
    exit 1
fi

/usr/bin/mkdir --parents "${codex_home}"
/usr/bin/mkdir --parents "${codex_tmp}"
/usr/bin/chown snippet:snippet "${codex_tmp}"
/usr/bin/chmod 0700 "${codex_tmp}"

source_uid=$(/usr/bin/stat --format='%u' "${host_codex_home}")
source_gid=$(/usr/bin/stat --format='%g' "${host_codex_home}")

/usr/bin/bindfs \
    --force-user=snippet \
    --force-group=snippet \
    --create-for-user="${source_uid}" \
    --create-for-group="${source_gid}" \
    -o allow_other \
    "${host_codex_home}" \
    "${codex_home}"

# Codex's arg0 janitor recursively removes stale helper directories. Keep this
# ephemeral subtree off the bindfs view so host and container cleanup cannot
# race through the FUSE mount.
/usr/bin/mkdir --parents "${codex_home}/tmp"
if ! /usr/bin/mountpoint --quiet "${codex_home}/tmp"; then
    /usr/bin/mount --bind "${codex_tmp}" "${codex_home}/tmp"
fi

export HOME=/home/snippet

exec /usr/sbin/runuser --user snippet -- "$@"
