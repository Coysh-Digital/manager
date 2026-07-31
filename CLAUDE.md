# Manager — workspace map

This directory is a **workspace, not a repository**. It holds several independent checkouts side by
side, plus a throwaway Craft install used to exercise the connector.

If you are an assistant working here, read this first. Getting a change into the wrong repository is
the mistake that matters most, because one of them is public and one of them is not, and a push to a
public repository cannot be undone — a force-push removes the ref, not the objects, and not anybody's
fetch, GitHub's cache, or the forks network.

## The two websites

Everything below exists to serve one of these.

| Site | What it is | Served from |
|---|---|---|
| **managerforcraft.com** | The public front end. Marketing, pricing, docs. | `cloud/` → `manager-cloud` |
| **console.managerforcraft.com** | Manager Cloud: the paid, hosted service. | `platform/` → `manager-private`, `console` branch |

Manager Cloud is not a different product. It is the self-hosted control plane plus a hosting layer —
billing, a managed key service, per-organisation storage. The domain logic is identical and lives in
the public core.

## The repositories

Six, and each one has a reason it cannot simply be folded into another.

### `platform/` → `Coysh-Digital/manager` — **PUBLIC**

The Laravel control plane. **This is Manager Self-Hosted**, and it is what people install on their own
infrastructure, free.

It is public on purpose: people are about to trust it with production databases, and they should be
able to read it first. That is also most of the product's security positioning, so making it private
would cost more than it saved.

Two branches matter, and they live on different remotes:

- `origin/main` → the public repository. **Nothing private may ever appear here.**
- `private/main` → a byte-identical mirror in `manager-private`.
- `private/console` → the mirror plus `cloud/`. Deploys console.managerforcraft.com.

`tests/Invariants/NoCloudCodeTest.php` enforces the boundary and has already caught a real leak.
Do not disable it; if it fires, it is right.

### `connector/` → `Coysh-Digital/craft-manager-connector` — **PUBLIC**

The Craft CMS 5 plugin installed on managed sites. Separate because it is installed by Composer into
customers' own servers, and it cannot depend on the whole control plane to do that.

Its most important file is `bin/verify-invariants.php`. It fails the build if the plugin gains the
ability to shell out, accept a destination as a parameter, accept a recovery key the site has not
pinned, register a URL rule, or take a dependency outside a fixed allowlist. If it goes red, the fix
is the code, not the check.

### `protocol/` → `Coysh-Digital/manager-protocol` — **PUBLIC, MIT**

The wire contract: canonical strings, signing, schemas, the backup artifact format.

Separate because both the platform and the plugin need it and they are under different licences.
Vendoring copies into each guarantees drift, and drift in a signing protocol is a security event.

MIT, unlike the rest, so anyone can verify a signature or reimplement either side without asking.

**Schemas are add-only.** Never edit a published one; add `.v2` beside it. The committed signing
fixtures are the change detector — if they stop verifying, that is a wire-format break and a version
bump, not a fixture to regenerate.

### `restore/` → `Coysh-Digital/manager-restore` — **PUBLIC, MIT**

The offline CLI that generates recovery keys and decrypts backups. Depends only on `manager-protocol`.

Separate rather than folded into the protocol package, because the plugin depends on protocol — and
folding it in would put restore code onto every managed Craft site that will never run it.

It has to be able to outlive Coysh Digital. Manager holds ciphertext it cannot decrypt, so if we stop
existing, this plus the protocol spec is what stands between a customer and a permanently unreadable
archive. That is why it is MIT and why it opens no sockets.

### `cloud/` → `Coysh-Digital/manager-cloud` — **PRIVATE**

The marketing site at managerforcraft.com. Laravel and Blade, no database, no queue, no session store.

Private while pricing and roadmap copy are in flux. Nothing in it is sensitive; nobody needs to install
it.

### `Coysh-Digital/manager-private` — **PRIVATE** (no separate checkout)

Not a directory here. It is a second remote on `platform/`, added as `private`.

- `main` — mirror of the public repository, kept in step.
- `console` — `main` plus `cloud/`, the hosting layer. Deploys console.managerforcraft.com.
- `archive/overlay` — history of the retired `manager-cloud-overlay` package. Reference only.

The mirror earns its place: diffing `private/main` against `origin/main` shows at a glance that nothing
private has travelled the wrong way.

### `testbed/` — not a repository

A local ddev Craft 5 install for exercising the connector. Gitignored, disposable, rebuild with
`connector/bin/create-testbed.sh`.

## Where a change goes

| Changing | Repository | Watch out for |
|---|---|---|
| Monitoring, findings, backups, UI, migrations | `platform` → public `main` | Must not reference anything private |
| Billing, KMS, per-org storage, provisioning | `platform` → `private/console`, under `cloud/` | Never on `main` |
| What a Craft site reports or does | `connector` | `verify-invariants.php` must stay green |
| Canonical strings, schemas, artifact format | `protocol` | Add-only; fixtures are the contract |
| Key generation, decryption, the restore CLI | `restore` | Must stay offline; there is a test |
| Marketing, pricing, public docs | `cloud` | No real client domains; a test enforces it |

## Rules that are not negotiable

**Never `git add -A` in `platform/` without looking at what it staged.** A `cloud/` directory in that
checkout is excluded by `.gitignore` and `.dockerignore`, but a dependency declaration naming the
private package is not caught by either — that is exactly how the one real leak happened.

**Never push `platform` to `origin` with anything Cloud-shaped in it.** Run
`vendor/bin/pest --testsuite=Invariants` first. It is fast.

**Never regenerate a protocol fixture to make a test pass.** That is the test working.

**Never claim a backup is restorable without a restore test**, in code comments, documentation or UI
copy. Decrypting proves a file is intact, not that the SQL loads.

**Do not weaken a check because it went red.** Both `verify-invariants.php` and the Invariants suite
are written to fail rather than warn, and every one of them exists because something went wrong once.

## Release order

The packages depend on each other, so releases have an order:

1. `protocol` — tag and publish first. Everything else resolves against it. **v1.2.0 is published.**
2. `restore` and `connector` — both require `manager-protocol ^1.2`.
3. `platform` — regenerate `composer.lock`, which cannot satisfy `^1.2` until step 1 is done.
4. `private/console` — merge `main`, resolve the `.gitignore` conflict deliberately, deploy.

No repository verifies a release signature any more. `platform`'s workflow used to require a signed
tag checked against `.github/allowed_signers` and refuse to publish an unsigned one; that gate, the
signer files in all three repositories and the invariants asserting them were removed deliberately.
A release still carries a SHA256 manifest and is built reproducibly, so integrity is checkable;
authorship is not.

Nothing in `platform/` needs a local path repository any more. Before v1.2.0 was published, the
protocol had to be symlinked from a sibling checkout via an uncommitted `repositories` block; that is
gone, and `composer install` resolves everything from Packagist. If you find yourself adding one back,
it means a package needs releasing.

## Currently outstanding

- `connector` 1.7.0 and `manager-restore` 1.0.0 are committed but **not tagged**. `manager-protocol`
  v1.2.0 is published, so nothing blocks them.
- `platform` has no tags at all. Its release workflow requires a signed one.
- `console`'s `composer.lock` does not list the Cloud layer yet. Now unblocked:
  `composer update coysh-digital/manager-cloud-overlay -W` on that branch.

## Local development

```bash
cd platform && ddev start && ddev composer install && ddev artisan migrate
cd ../testbed && ddev start && ddev craft plugin/install manager-connector
```

`testbed/` consumes `connector/` as a symlinked Composer path repository, so editing
`connector/src/*.php` takes effect on the next request. Always use `ddev composer` there, never host
composer — the path repository points at a container-absolute path.

Mutagen is on for `platform/` and deliberately off for `testbed/`. If a file edit does not seem to
reach the container, `ddev mutagen sync`.
