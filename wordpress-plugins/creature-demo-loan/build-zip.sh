#!/bin/sh
# Build the wp-admin upload zip. The archive root is creature-demo-loan/.
# Commit this script. Do not commit the zip it writes.
set -eu
here=$(CDPATH= cd -- "$(dirname "$0")" && pwd)
parent=$(dirname "$here")
name=$(basename "$here")
out="$parent/$name.zip"
rm -f "$out"
(
	cd "$parent"
	zip -r "$out" "$name" -x "$name/*.zip" -x '*/.DS_Store' -x '*/.git/*'
)
echo "$out"
