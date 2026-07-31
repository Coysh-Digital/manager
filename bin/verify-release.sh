#!/bin/sh
#
# Verifies a downloaded Manager release.
#
# This is the operator's side of invariant 17, and it is a separate script from the build on purpose:
# it must run on a machine that has only the download, with no repository, no toolchain and no trust
# in anything already present.
#
# It answers one question: is this the artifact whose checksum was published?  (integrity)
#
# It does not answer who published it. Somebody who could replace the download could replace the
# checksum file beside it, and there is no longer a signed tag to check that against. Said here
# rather than left to be inferred, because a script called verify-release should not imply more
# than it checks.
#
# Usage:  bin/verify-release.sh [directory-containing-the-download]
set -eu

DIR="${1:-.}"
cd "$DIR"

if [ ! -f SHA256SUMS ]; then
    echo "verify-release: no SHA256SUMS here. Download it from the same release as the archive." >&2
    exit 66
fi

if command -v sha256sum >/dev/null 2>&1; then
    CHECK="sha256sum -c"
else
    CHECK="shasum -a 256 -c"
fi

echo "Checking integrity..."

if ! $CHECK SHA256SUMS; then
    echo "" >&2
    echo "verify-release: FAILED. What you have is not what was published." >&2
    echo "Do not install it. Re-download, and if it fails again say so at hello@coysh.digital." >&2
    exit 1
fi

ARCHIVE=$(awk '{print $2}' SHA256SUMS | grep '\.tar\.gz$' | head -1)
VERSION=$(printf '%s' "$ARCHIVE" | sed 's/^manager-//; s/\.tar\.gz$//')

echo ""
echo "Integrity confirmed: this is the artifact that was published."
echo ""
echo "That is integrity, not authenticity. A checksum proves the download is intact, not that"
echo "it came from Coysh Digital: whoever could alter the archive could alter SHA256SUMS beside it."
