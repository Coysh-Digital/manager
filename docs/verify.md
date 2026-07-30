# Verifying a release

Before you install Manager, or upgrade it, check that what you have is what was published. This takes
about thirty seconds and it is the only part of the install that protects you against everything
between our repository and your server.

## What you are checking, and why it is two things

**Integrity** — is this file the one whose checksum was published? A corrupted download or an
altered mirror fails here.

**Authenticity** — was that checksum published by us? A checksum on its own cannot answer this.
Somebody who could replace the archive could replace `SHA256SUMS` beside it, and both would agree.

So integrity is checked against the published checksum, and authenticity against the signature on the
git tag the release was built from. Doing only the first is common and is not enough.

## Checking integrity

Download `manager-<version>.tar.gz` and `SHA256SUMS` from the same release, then:

```bash
sha256sum -c SHA256SUMS
```

On macOS, `shasum -a 256 -c SHA256SUMS`. Expect `OK` for each file. Anything else means do not
install it.

If you have the repository already, `bin/verify-release.sh <download-directory>` does the same and
prints the authenticity step with the version filled in.

## Checking authenticity

```bash
git clone https://github.com/Coysh-Digital/manager.git
cd manager
git config gpg.ssh.allowedSignersFile .github/allowed_signers
git tag -v v1.0.0
```

Expect `Good "git" signature for tim@timcoysh.co.uk`.

**Cross-check the key.** `.github/allowed_signers` lives in the repository it is vouching for, so on
its own it is trust-on-first-use: whoever could replace a tag could replace that file too. Two
independent places to compare it against, both outside this repository:

- <https://github.com/timcoysh.keys> — served by GitHub, not by us.
- The **Verified** badge GitHub shows on the tag, computed from keys held in your account settings.

If the key in `.github/allowed_signers` matches those, the signature means what it appears to mean.

## Reproducing the archive yourself

The release archive is built to be reproducible: entry timestamps come from the commit rather than
from the clock, and gzip is told not to stamp its header. So you can rebuild it and compare, which is
stronger than trusting either the checksum or the signature alone — it shows the published artifact
really was built from the published source.

```bash
git checkout v1.0.0
bin/build-release.sh v1.0.0 /tmp/check
sha256sum /tmp/check/manager-1.0.0.tar.gz
```

That checksum should equal the one in the release's `SHA256SUMS`. If it does not, the published
artifact contains something the tag does not, and we would like to hear about it.

## The bill of materials

Each release carries `sbom.json`, a CycloneDX inventory of the dependency tree at that tag. Useful for
answering "are we affected" when an advisory lands, without installing anything first.

## What CI refuses to publish

The release workflow will not produce artifacts for a tag that is unsigned, or signed by a key that is
not in `.github/allowed_signers`. It also re-runs the security invariant suite against the exact tree
being published, because a tag can be moved after ordinary CI has passed.

That refusal is the part that matters. A release process that *can* publish something unverifiable
eventually will, on the release made in a hurry.

## The connector

The Craft plugin is installed through Composer, so Composer does the integrity check for you against
the hash Packagist records. For authenticity, its tags are signed by the same key:

```bash
git clone https://github.com/Coysh-Digital/craft-manager-connector.git
cd craft-manager-connector
git config gpg.ssh.allowedSignersFile .github/allowed_signers
git tag -v v1.2.0
```

## Reporting a problem

If verification fails and a fresh download does not fix it, please tell us privately at
**hello@coysh.digital** rather than opening a public issue. A failure here could mean a compromised
distribution path, and that is worth a quiet conversation first.
