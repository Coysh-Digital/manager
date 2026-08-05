#!/bin/sh
#
# Builds the release artifacts for a tag.
#
# Invariant 17: connector and platform updates must use verifiable release artifacts. "Verifiable"
# needs three things, and this produces all three:
#
#   1. An artifact whose contents are fixed by the tag it is built from.
#   2. A checksum an operator can compare against what they downloaded.
#   3. A bill of materials, so what is inside is a matter of record rather than inspection.
#
# That first line used to say "fixed by a signed tag". Tag signing was removed deliberately - there is
# no allowed_signers file, release.yml verifies nothing, and tests/Invariants/ReleaseArtifactTest.php
# says so outright. Integrity is checkable through the manifest and reproducibility below; authorship
# is not, and claiming otherwise in the script that builds the artifacts is the worst place to.
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
        # Everything below logs instead of discarding, and the log is printed if the SBOM does not
        # appear.
        #
        # This is the second time this step has been wrong in a way nothing reported. The first was a
        # test for `vendor/bin/cyclonedx-php-composer`, a file that has never existed - the package
        # installs no vendor/bin entry, it registers the `CycloneDX:make-sbom` command - so the
        # condition was false on every run and no SBOM was ever built. That was fixed, v1.0.0 was
        # tagged, and the SBOM still did not appear: it generates here and not on the runner, and
        # every command was `>/dev/null 2>&1 || true`, so the reason went with it.
        #
        # The repository's own note about this says: diagnose a red job from its output, and if the
        # output is not to hand, say so rather than inferring. There is no point guessing a third
        # time. The next release that fails will say why.
        SBOM_LOG="$WORK/sbom.log"

        # allow-plugins, because the package is a Composer plugin and a non-interactive install
        # declines to run one it has not been told about - silently, which is its own version of this
        # same problem.
        composer --working-dir="$WORK" --no-interaction \
            config --no-plugins allow-plugins.cyclonedx/cyclonedx-php-composer true >>"$SBOM_LOG" 2>&1 || true

        composer --working-dir="$WORK" --no-interaction \
            require --dev cyclonedx/cyclonedx-php-composer >>"$SBOM_LOG" 2>&1 || true

        composer --working-dir="$WORK" --no-interaction \
            CycloneDX:make-sbom --output-file="$OUT/sbom.json" --output-format=JSON \
            --omit=dev "$WORK/composer.lock" >>"$SBOM_LOG" 2>&1 || true

        if [ ! -f "$OUT/sbom.json" ]; then
            # Not fatal. A missing bill of materials is not worth refusing to publish a release over,
            # and the archive and its checksum - the two things an operator actually verifies - are
            # already built. But it is worth saying loudly, with the reason attached, rather than
            # leaving somebody to notice later that the manifest lists two files where the header of
            # this script promises three.
            echo "warning: the SBOM could not be generated; the release will not carry one." >&2
            echo "--- composer output ---" >&2
            tail -n 30 "$SBOM_LOG" >&2 || true
            echo "--- end composer output ---" >&2
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
