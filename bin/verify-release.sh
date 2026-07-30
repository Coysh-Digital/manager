#!/bin/sh
#
# Verifies a downloaded Manager release.
#
# This is the operator's side of invariant 17, and it is a separate script from the build on purpose:
# it must run on a machine that has only the download, with no repository, no toolchain and no trust
# in anything already present.
#
# It answers two questions, and it is worth being clear that they are different:
#
#   1. Is this the artifact whose checksum was published?  (integrity)
#   2. Was that checksum published by whoever holds the signing key?  (authenticity)
#
# A checksum alone answers only the first. Somebody who could replace the download could replace the
# checksum file beside it, so the second question is answered by the signed tag — which is why this
# tells you how to check that too rather than pretending the checksum is enough.
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
echo "That is only half of it. A checksum proves the download is intact, not that it came"
echo "from Coysh Digital — whoever could alter the archive could alter SHA256SUMS beside it."
echo "To confirm authenticity, verify the signature on the tag it was built from:"
echo ""
echo "  git clone https://github.com/Coysh-Digital/manager.git"
echo "  cd manager"
echo "  git config gpg.ssh.allowedSignersFile .github/allowed_signers"
echo "  git tag -v v${VERSION}"
echo ""
echo "Expect \"Good git signature\". Cross-check the key against"
echo "https://github.com/timcoysh.keys, which is served from outside this repository."
