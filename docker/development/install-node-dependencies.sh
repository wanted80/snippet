#!/bin/sh
set -eu

# Run from the project root. Keep provisioned modules when both manifests match.
if [ -x node_modules/.bin/playwright ] \
    && cmp -s package.json node_modules/.snippet-package.json \
    && cmp -s package-lock.json node_modules/.snippet-package-lock.json; then
    exit 0
fi

# A failed install must not leave an older installation marked as current.
rm -f node_modules/.snippet-package.json node_modules/.snippet-package-lock.json
npm ci --no-audit --no-fund
cp package.json node_modules/.snippet-package.json
cp package-lock.json node_modules/.snippet-package-lock.json
