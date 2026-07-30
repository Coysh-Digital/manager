# Invariant coverage

One file per numbered requirement in the specification's *Security invariants* section, so a
reviewer can map the suite to the document rather than taking a summary's word for it.

```bash
vendor/bin/pest --testsuite=Invariants
```

CI runs this as its own step, before the full suite, so a security regression is never buried in
ordinary test output.

| # | Invariant | Where |
|---|---|---|
| 1 | Never require a Craft administrator password | `NoStoredCredentialsTest` |
| 2 | Never require SSH credentials | `NoStoredCredentialsTest` |
| 3 | Never store a website's database password | `NoStoredCredentialsTest` |
| 4 | Connector exposes no inbound management endpoint | `NoRemoteExecutionTest`, plus `bin/verify-invariants.php` in the connector repository |
| 5 | Connections initiated outbound by the connector | As above |
| 6 | Monitoring access read-only by default | `PairingTest`, `NoRemoteExecutionTest` |
| 7 | Backup access requires explicit permission | `BackupPermissionTest`, `BackupPipelineTest`, `DataMinimisationTest` |
| 8 | No arbitrary PHP, shell, console or SQL execution | `NoRemoteExecutionTest`, `RemoteJobRegistryTest`, plus `bin/verify-invariants.php` in the connector repository |
| 9 | Remote jobs use a fixed allowlist of versioned types | `RemoteJobRegistryTest` |
| 10 | Every remote job authenticated, authorised, validated, audited | `RemoteJobRegistryTest` |
| 11 | A compromised site cannot expose another site's credentials | `TenantIsolationTest` |
| 12 | A compromised organisation cannot expose another | `TenantIsolationTest`, `PairingTest` |
| 13 | Secrets never in logs, exceptions, analytics or exports | `AuditLogIntegrityTest`, `AccountSecurityTest`, `DataMinimisationTest`, `OutboundDestinationTest` |
| 14 | Removing a site immediately revokes its credentials | `NoRemoteExecutionTest` |
| 15 | Security-sensitive actions fail closed | `ConnectorSignatureTest`, `SelfHostedHardeningTest` |
| 16 | Retries must not cause an action to run twice | `ConnectorSignatureTest`, `BackupPipelineTest` |
| 17 | Connector and platform updates use verifiable artifacts | `ReleaseArtifactTest` |
| 18 | No application content or user records collected for monitoring | `DataMinimisationTest` |

The specification's *assumptions* are covered alongside the numbered invariants where they are
testable in their own right. `OutboundDestinationTest` is the one for "a webhook destination may be
malicious": a notification names which site is unpatched, so the destination it goes to is part of
the threat model rather than a configuration detail.

## What is deliberately not covered yet

Every numbered invariant now has a test. Two of them got there late, and how they got there is the
point of this section.

Invariant 7 was uncovered until the backup permission existed, and invariant 17 until releases did.
Neither was given a placeholder test in the meantime. A test asserting that a workflow file exists, or
that a capability string appears in a list, would have turned both rows green while proving nothing —
and a green row is how a gap stops being noticed.

Invariants 9 and 10 were in this list until the job registry landed. They are now covered by
`RemoteJobRegistryTest`, which is worth reading for how the four words in invariant 10 —
authenticated, authorised, validated, audited — are tested separately. A job that were authenticated
and audited but not re-authorised at claim time would satisfy a careless reading and still let a
revoked capability run work.

Where the absence of a feature is itself testable, it is tested — `NoRemoteExecutionTest` fails the
moment somebody adds a route that could run something on a managed site without going through a job
registry.

## Also worth reading

- `AccountSecurityTest` — the platform account is the other way in. A stolen password must not be
  enough, and a password reset must not bypass the second factor.
- `SelfHostedHardeningTest` — that the diagnostics actually notice a misconfiguration, rather than
  merely existing.
- `RemoteJobRegistryTest` — the job registry, including that no job type or parameter name can name a
  command, a path, a query or a URL.
- `OutboundDestinationTest` — server-side request forgery through notification destinations, including
  that a hostname is re-checked on every send and the connection pinned to the address that was
  checked, so DNS cannot change between the two.
- `BackupPermissionTest` — why "explicit permission" needs four separate assertions rather than one.
  A checkbox among the read-only switches would satisfy a careless reading of invariant 7 and miss
  that granting it authorises a copy of every user record on a production site.
- `BackupPipelineTest` — the artifact pipeline with real libsodium rather than doubles, because a
  broken artifact format is only discovered when somebody needs a backup. Worth reading for how the
  upload is authenticated before any of the body is read, and for the division of labour between the
  signature (who sent this) and the checksum (did it survive the journey).
- `ReleaseArtifactTest` — runs the real build and verify scripts rather than asserting they exist,
  including that the archive builds to identical bytes twice and that verification exits non-zero on a
  tampered download. Reproducibility is what lets somebody who is not us confirm a published artifact
  came from the published source; without it a checksum only proves the download was not corrupted.
- The protocol package's own suite, which asserts byte-compatibility of the canonical signing string
  against committed fixtures.
