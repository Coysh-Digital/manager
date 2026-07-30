#!/bin/sh
#
# Builds the release artifacts for a tag.
#
# Invariant 17: connector and platform updates must use verifiable release artifacts. "Verifiable"
# needs three things, and this produces all three:
#
#   1. An artifact whose contents are fixed by a signed tag.
#   2. A checksum an operator can compare against what they downloaded.
#   3. A bill of materials, so what is inside is a matter of record rather than inspection.
#
# The tarball is **reproducible**: `git archive` writes entry timestamps from the commit rather than
# from the clock, and gzip is told not to stamp its header. Two people building the same tag on
# different machines get byte-identical output, which is what lets somebody other than us confirm that
# a published artifact really came from the published source. Without that, a checksum only proves the
# download was not corrupted.
#
# Usage:  bin/build-release.sh <tag> [output-directory]
set -eu

TAG="${1:-}"
OUT="${2:-dist}"

if [ -z "$TAG" ]; then
    echo "usage: bin/build-release.sh <tag> [output-directory]" >&2
    exit 64
fi

if ! git rev-parse "$TAG" >/dev/null 2>&1; then
    echo "build-release: '$TAG' is not a tag or revision in this repository." >&2
    exit 65
fi

# Strip a leading "v" for the version inside filenames: tags carry it, versions do not.
VERSION=$(printf '%s' "$TAG" | sed 's/^v//')
NAME="manager-${VERSION}"

mkdir -p "$OUT"
rm -f "$OUT/${NAME}.tar.gz" "$OUT/SHA256SUMS" "$OUT/sbom.json"

# Only what is committed at that tag. Nothing from the working tree, so an artifact cannot contain a
# local edit somebody forgot about.
#
# gzip -n omits the modification time from the header, which is the one part of the output that would
# otherwise differ between builds.
git archive --format=tar --prefix="${NAME}/" "$TAG" | gzip -n -9 > "$OUT/${NAME}.tar.gz"

# The bill of materials describes the dependency tree at that tag, so it is generated from the tag's
# own lock file rather than from whatever is installed here.
if command -v composer >/dev/null 2>&1; then
    WORK=$(mktemp -d)
    git archive --format=tar "$TAG" composer.json composer.lock | tar -x -C "$WORK"

    if [ -f "$WORK/composer.lock" ]; then
        composer --working-dir="$WORK" --no-interaction --quiet \
            require --dev cyclonedx/cyclonedx-php-composer >/dev/null 2>&1 || true

        if [ -x "$WORK/vendor/bin/cyclonedx-php-composer" ]; then
            "$WORK/vendor/bin/cyclonedx-php-composer" make-sbom \
                --output-file="$OUT/sbom.json" --output-format=JSON \
                --omit=dev "$WORK/composer.lock" >/dev/null 2>&1 || true
        fi
    fi

    rm -rf "$WORK"
fi

# One manifest covering every artifact, so verification is a single command rather than one per file.
# Written with the bare filenames so `sha256sum -c` works from the directory it is downloaded into.
cd "$OUT"

if command -v sha256sum >/dev/null 2>&1; then
    SUM="sha256sum"
else
    # macOS ships shasum rather than sha256sum, and a release is sometimes cut from a laptop.
    SUM="shasum -a 256"
fi

# shellcheck disable=SC2086
$SUM "${NAME}.tar.gz" $([ -f sbom.json ] && echo sbom.json) > SHA256SUMS

echo "Built ${NAME} in ${OUT}:"
sed 's/^/  /' SHA256SUMS
