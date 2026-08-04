<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only audit history.
 *
 * Two things make "append-only" mean something here rather than being a naming convention:
 *
 *  - A trigger rejects UPDATE, DELETE and TRUNCATE at the database. This holds even for the table
 *    owner, which is why it is the primary mechanism.
 *  - Deployments are documented to connect as a role without UPDATE or DELETE on this table, as
 *    defence in depth. `manager:doctor` warns when the application connects as a superuser, since
 *    a superuser bypasses privilege checks entirely.
 *
 * Events are hash-chained per organisation so that tampering by anyone who does get around both of
 * the above is still detectable after the fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();

            // Restricted rather than cascading: deleting an organisation must not be a way to
            // delete its audit history. Organisations are archived, and any real purge is a
            // separate, documented operation.
            $table->foreignId('organisation_id')->nullable()->constrained()->restrictOnDelete();

            // Position in this organisation's chain, starting at 1.
            $table->unsignedBigInteger('seq');

            // user | connector | system
            $table->string('actor_type')->default('user');

            // Deliberately *not* foreign keys.
            //
            // A cascade or a null-on-delete would have to UPDATE this table when a user or site is
            // removed, and the append-only trigger below rightly forbids that. Referential
            // integrity is the wrong model for an immutable historical record anyway: these
            // columns say who acted and on what at the time, not who exists now.
            //
            // The labels beside them are what keep a line legible once the row it referred to is
            // gone.
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label')->nullable();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->string('site_label')->nullable();

            // Dotted and stable, e.g. "site.paired", "capability.revoked", "backup.downloaded".
            $table->string('action');
            $table->string('target_type')->nullable();
            $table->string('target_id')->nullable();

            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->ulid('correlation_id')->nullable();

            // Safe before and after values only. Never secrets, never backup content, never a
            // payload that failed validation - a rejected payload is exactly where key material
            // would be if a connector were misbehaving.
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();

            // success | failure. Failures are recorded too: a rejected pairing attempt is more
            // interesting than a successful one.
            $table->string('outcome')->default('success');
            $table->string('failure_reason')->nullable();

            // sha256(prev_hash || canonical json of this event). The first event in a chain uses
            // 64 zeros as its predecessor.
            $table->char('prev_hash', 64);
            $table->char('hash', 64);

            $table->timestamp('created_at');

            $table->index(['organisation_id', 'created_at']);
            $table->index(['site_id', 'created_at']);
            $table->index('correlation_id');
        });

        // NULLS NOT DISTINCT so that platform-level events, which have no organisation, form one
        // chain of their own rather than a series of colliding sequence numbers.
        DB::statement('CREATE UNIQUE INDEX audit_events_chain_position ON audit_events (organisation_id, seq) NULLS NOT DISTINCT');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION manager_audit_events_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_events is append-only; % is not permitted', TG_OP;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_events_reject_mutation
                BEFORE UPDATE OR DELETE ON audit_events
                FOR EACH ROW EXECUTE FUNCTION manager_audit_events_append_only();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_events_reject_truncate
                BEFORE TRUNCATE ON audit_events
                FOR EACH STATEMENT EXECUTE FUNCTION manager_audit_events_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_events_reject_truncate ON audit_events');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_events_reject_mutation ON audit_events');
        DB::unprepared('DROP FUNCTION IF EXISTS manager_audit_events_append_only()');

        Schema::dropIfExists('audit_events');
    }
};
