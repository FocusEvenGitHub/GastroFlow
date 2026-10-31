#!/usr/bin/env bash
# Image tags to publish for a release tag (spec 072), one per line.
#
#   image-tags.sh vX.Y.Z [existing release tags...]   (defaults to `git tag -l 'v*'`)
#
# Always the exact version (vX.Y.Z → X.Y.Z, vX.Y.Z-rc.1 → X.Y.Z-rc.1). A stable tag also
# gets X.Y when it is the highest stable vX.Y.* tag, and X when it is the highest stable
# vX.* tag — so a fix released on an older line never moves `1` backwards. Pre-releases
# get nothing floating, and nothing ever gets `latest`.
set -euo pipefail

tag="${1:-}"
shift || true
if ! [[ "$tag" =~ ^v([0-9]+)\.([0-9]+)\.([0-9]+)(-[0-9A-Za-z.]+)?$ ]]; then
    echo "error: '$tag' is not a release tag (vX.Y.Z or vX.Y.Z-suffix)" >&2
    exit 1
fi
major="${BASH_REMATCH[1]}"
minor="${BASH_REMATCH[2]}"
pre="${BASH_REMATCH[4]}"

echo "${tag#v}"
[ -n "$pre" ] && exit 0

if [ "$#" -gt 0 ]; then
    existing=$(printf '%s\n' "$@")
else
    existing=$(git tag -l 'v*')
fi
# Stable tags only, plus this one (it may not exist locally yet), highest first.
stable=$(printf '%s\n%s\n' "$existing" "$tag" | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' | sort -u -V -r)

highest_minor=$(printf '%s\n' "$stable" | grep -E "^v${major}\.${minor}\." | head -n1)
highest_major=$(printf '%s\n' "$stable" | grep -E "^v${major}\." | head -n1)

[ "$highest_minor" = "$tag" ] && echo "${major}.${minor}"
[ "$highest_major" = "$tag" ] && echo "${major}"
exit 0
