<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The zero-knowledge artifact format, alongside the one it replaces.
 *
 * Nothing here changes an existing row. Artifacts taken under `backup.v1` had their key sealed to this
 * platform and re-wrapped for storage, and they stay exactly as they were — readable, retrievable, and
 * honestly labelled as something we could open. `format_version` defaults to `v1` so no backfill is
 * needed and no artifact is silently reinterpreted as something it is not.
 *
 * Three tables' worth of change:
 *
 *  - **`backup_artifact_recipients`** replaces `wrapped_key` for v2 artifacts. One row per recovery key
 *    the artifact was sealed to, holding a sealed blob this platform cannot open.
 *  - **`backup_events`** is a per-artifact timeline, deliberately *not* the audit log. See below.
 *  - **`remote_jobs.backup_recipient_fingerprints`** records what was served at claim time, so a
 *    declaration naming a different set can be refused.
 *
 * On the two new artifact states: `uploaded` is genuinely distinct rather than decoration. When bytes
 * are streamed through the platform they are hashed on the way past, so storage and verification happen
 * in the same instant and the state is skipped entirely. When they go straight to object storage the
 * connector can only report that it finished, and the platform confirms integrity separately — that gap
 * is a real state and pretending otherwise would mean calling an unverified artifact `stored`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_artifacts', function (Blueprint $table): void {
            /*
             | v1 — key sealed to this platform, re-wrapped into `wrapped_key`. We can read it.
             | v2 — key sealed only to the organisation's recovery keys. We cannot.
             |
             | Defaulted to v1 so existing rows are correct without a backfill, and so that a row whose
             | format could not be determined is treated as the weaker claim rather than the stronger.
             */
            $table->string('format_version', 8)->default('v1');

            // The manifest as the connector serialised it, byte for byte. Stored rather than reassembled
            // because it is what the signature covers, and because it is what a customer will decrypt
            // against — re-encoding it here would eventually produce a document that no longer verifies.
            $table->text('manifest')->nullable();
            $table->char('manifest_sha256', 64)->nullable();
            $table->text('manifest_signature')->nullable();

            // Minted by the connector for v2, so an exported file can name itself before any platform
            // has seen it. Distinct from external_id, which is ours.
            $table->char('artifact_id', 26)->nullable();

            // Connector-local counter. A gap, or a repeat, is how a rollback becomes visible.
            $table->unsignedBigInteger('sequence')->nullable();

            // Whole-file hash and size: envelope, manifest, signature and stream. The existing
            // ciphertext_* columns cover the encrypted stream alone, which is a different number.
            $table->char('artifact_sha256', 64)->nullable();
            $table->unsignedBigInteger('artifact_bytes')->nullable();

            // Reported by the connector after the fact. Never instructed: nothing the platform sends
            // names a host.
            $table->string('upload_mode', 16)->nullable();

            /*
             | A claim, where `state` is a fact.
             |
             | Nothing decides anything on this column. It may be stale, it may skip values, and a
             | dropped report leaves it behind — which is precisely why it must not be load-bearing.
             | The distinction belongs in the schema rather than in a convention somebody has to know.
             */
            $table->string('stage', 16)->nullable();
            $table->timestamp('stage_at')->nullable();

            $table->index(['organisation_id', 'format_version']);
        });

        Schema::create('backup_artifact_recipients', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('backup_artifact_id')->constrained()->cascadeOnDelete();

            $table->string('fingerprint', 40);
            $table->string('public_key', 64);

            /*
             | The artifact's data-encryption key, sealed to the public key beside it.
             |
             | Deliberately NOT given an `encrypted` cast, unlike `backup_artifacts.wrapped_key`.
             | Copying that pattern here would look prudent and be actively harmful: this platform
             | cannot open a sealed box either way, so the cast adds no confidentiality at all — and it
             | would make a customer's ability to restore depend on our APP_KEY surviving, quietly
             | recreating the exact dependency the whole format exists to remove.
             */
            $table->string('wrapped_key', 128);

            $table->string('label', 120)->nullable();

            // Which enrolled key this was, when we still have the row. Nullable and nullOnDelete
            // because the artifact must remain describable even if the key record is gone, and because
            // a key can be enrolled by an organisation whose row is later torn down.
            $table->foreignId('recovery_key_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            // An artifact is sealed to a given key exactly once. Two rows would mean two copies of the
            // same key, which is either a bug or somebody adding one.
            $table->unique(['backup_artifact_id', 'fingerprint']);

            // "Which artifacts can this key open" is the question a rotation asks.
            $table->index('fingerprint');
        });

        /*
         | A per-artifact timeline, separate from audit_events on purpose.
         |
         | The audit log is hash-chained per organisation and every append takes a transaction-scoped
         | advisory lock on that organisation. A fleet taking two hundred nightly backups, at half a
         | dozen observations each, would serialise twelve hundred writes behind one lock — against
         | every sign-in and capability change happening at the same time.
         |
         | Two further reasons, either of which would be enough on its own. audit_events is protected by
         | a trigger that refuses UPDATE, DELETE and TRUNCATE, so progress telemetry written there could
         | never be pruned and would grow forever. And `manager:audit:verify` walks the chain to prove
         | nothing was altered; making it walk millions of rows of "dump started" makes the
         | accountability tool slower and less likely to be run.
         |
         | The split rule: decisions and accesses go to audit_events, observations come here. So
         | `backup.declared`, `backup.stored`, `backup.deleted` and every recovery-key action stay in the
         | chain. `dump_started` and `integrity_verified` live here.
         */
        Schema::create('backup_events', function (Blueprint $table): void {
            $table->id();

            // Nullable: 'requested' and 'dump_started' both happen before an artifact row exists.
            $table->foreignId('backup_artifact_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('remote_job_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            $table->string('event', 32);
            $table->string('source', 16);
            $table->string('outcome', 16)->default('success');

            // The reporter's own message, from a closed set of phrasings on the platform side and a
            // sanitised class-and-message on the connector side. Never site content, never a path.
            $table->string('detail')->nullable();

            $table->unsignedBigInteger('bytes')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->ulid('correlation_id')->nullable();

            /*
             | Two clocks, and both are kept.
             |
             | occurred_at is the site's claim about its own clock; recorded_at is ours. Ordering is by
             | recorded_at so a site with a wrong clock cannot reorder a timeline, and display uses
             | occurred_at with the difference surfaced when it is large enough to matter.
             */
            $table->timestamp('occurred_at');
            $table->timestamp('recorded_at');

            $table->index(['backup_artifact_id', 'recorded_at']);
            $table->index(['organisation_id', 'recorded_at']);
            $table->index(['remote_job_id', 'recorded_at']);
        });

        Schema::table('remote_jobs', function (Blueprint $table): void {
            /*
             | The recipient fingerprints served with this job's claim response.
             |
             | Recorded so a declaration can be checked against it. The platform cannot verify a seal it
             | cannot open — that is what zero-knowledge means, not a gap — but it can check that the
             | manifest names exactly the keys it served, which turns the careless case into a rejected
             | declaration rather than a backup nobody can read.
             |
             | It cannot catch a connector that reports the right fingerprints and seals to something
             | else. Nothing on this side can, and the honest control for that is the customer running
             | `manager-restore verify`.
             */
            $table->jsonb('backup_recipient_fingerprints')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('remote_jobs', function (Blueprint $table): void {
            $table->dropColumn('backup_recipient_fingerprints');
        });

        Schema::dropIfExists('backup_events');
        Schema::dropIfExists('backup_artifact_recipients');

        Schema::table('backup_artifacts', function (Blueprint $table): void {
            $table->dropIndex(['organisation_id', 'format_version']);
            $table->dropColumn([
                'format_version',
                'manifest',
                'manifest_sha256',
                'manifest_signature',
                'artifact_id',
                'sequence',
                'artifact_sha256',
                'artifact_bytes',
                'upload_mode',
                'stage',
                'stage_at',
            ]);
        });
    }
};
