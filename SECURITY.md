# Security policy

## Reporting a vulnerability

Email **hello@coysh.digital**. Please do not open a public issue.

Include the Manager version, the edition (Cloud or Self-Hosted), and enough detail to reproduce. If
you have a proof of concept, say so rather than attaching it in the clear.

We aim to acknowledge within two working days and to keep you informed while a fix is prepared. We
will credit you when the advisory is published unless you would rather we did not.

## Supported versions

The current minor release receives security fixes. The previous minor receives them for 90 days
after being superseded.

## Coordinated disclosure

An advisory is published once a fix is available, or after 90 days, whichever comes first. If
something is being actively exploited we move faster and say so.

## Scope

In scope: the platform, the Manager Connector, and the shared protocol package.

Particularly interesting: anything that lets one organisation reach another's data; anything that
lets a connector act beyond its granted capabilities; anything that gets a secret into a log, an
exception or the audit trail; anything that makes a signed request replayable.

Out of scope: findings that require an already-compromised platform host, and reports from automated
scanners without a demonstrated impact.

## Design notes for reviewers

The security model, the runbooks and the reasoning behind each decision are in
[docs/security.md](docs/security.md). The invariant test suite maps to the numbered requirements in
the specification:

```bash
vendor/bin/pest --testsuite=Invariants
```

If you are looking for the interesting parts: `app/Http/Middleware/VerifyConnectorSignature.php`,
`app/Domain/Pairing/PairingService.php`, `app/Domain/Audit/`, and the shared protocol package.
