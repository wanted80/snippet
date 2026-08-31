#!/bin/sh

set -eu

if [ "$#" -ne 1 ]; then
    echo 'Usage: docker/demo/workspace.sh <destination>' >&2
    exit 2
fi

repository=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd -P)
destination=$1

if [ -e "${destination}" ] || [ -L "${destination}" ]; then
    echo "Demo workspace destination already exists: ${destination}" >&2
    exit 1
fi

mkdir -p -- "${destination}/site" "${destination}/resources" "${destination}/content"
cp -R -- "${repository}/site/." "${destination}/site/"
cp -R -- "${repository}/resources/." "${destination}/resources/"
cp -R -- "${repository}/demo/content/." "${destination}/content/"
cp -- "${repository}/demo/site/config.php" "${destination}/site/config.php"
