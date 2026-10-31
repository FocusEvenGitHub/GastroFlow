#!/usr/bin/env bash
# Prints the CHANGELOG.md section of a release tag, for the GitHub Release (spec 072).
#
#   release-notes.sh vX.Y.Z [CHANGELOG.md]           body of the section (heading excluded)
#   release-notes.sh --title vX.Y.Z [CHANGELOG.md]   "vX.Y.Z — Title" for the release name
#
# Fails — and so stops the release before anything is published — when the tag isn't a
# release tag, the section is missing, its heading has no date (still "em andamento") or
# its body is empty. A release never goes out without notes.
set -euo pipefail

mode=body
if [ "${1:-}" = "--title" ]; then
    mode=title
    shift
fi
tag="${1:-}"
file="${2:-CHANGELOG.md}"

if ! [[ "$tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]]; then
    echo "error: '$tag' is not a release tag (vX.Y.Z or vX.Y.Z-suffix)" >&2
    exit 1
fi

heading=$(grep -m1 -E "^## ${tag//./\\.}( |$)" "$file" || true)
if [ -z "$heading" ]; then
    echo "error: $file has no section for $tag (expected a heading '## $tag (YYYY-MM-DD) — Title')" >&2
    exit 1
fi
if ! [[ "$heading" =~ ^"## $tag ("[0-9]{4}-[0-9]{2}-[0-9]{2}")" ]]; then
    echo "error: the $tag section of $file has no release date yet: '$heading'" >&2
    echo "       Rename it to '## $tag (YYYY-MM-DD) — Title' in the release PR before tagging." >&2
    exit 1
fi

if [ "$mode" = title ]; then
    title="${heading#*) }"
    title="${title#— }"
    if [ "$title" = "$heading" ] || [ -z "$title" ]; then
        printf '%s\n' "$tag"
    else
        printf '%s — %s\n' "$tag" "$title"
    fi
    exit 0
fi

# Lines after the heading up to the next "## " heading, without leading/trailing blank lines.
body=$(awk -v h="$heading" '
    $0 == h { found = 1; next }
    found && /^## / { exit }
    found { print }
' "$file" | sed -e '/./,$!d' | sed -e ':a' -e '/^\n*$/{$d;N;ba' -e '}')

if [ -z "$body" ]; then
    echo "error: the $tag section of $file is empty" >&2
    exit 1
fi
printf '%s\n' "$body"
