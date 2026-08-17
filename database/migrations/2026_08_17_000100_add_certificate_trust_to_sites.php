<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the certificate a site presents is one a browser would actually accept.
 *
 * The certificate columns added in `2026_08_05_000400` record what was presented - when it expires,
 * who issued it, what name it carries. Those answer one question well and stay silent on three
 * others, and the silence read as approval: a certificate issued for somebody else's domain, or
 * served without the intermediate joining it to a root, showed on the fleet screen as "expires in 88
 * days" and nothing more, right up until a visitor saw an interstitial.
 *
 * So these are the three judgements, each stored separately rather than reduced to one "valid" flag.
 * They are different faults with different fixes: a hostname mismatch means the wrong certificate was
 * installed, an untrusted chain usually means the intermediate was left out of the bundle, and a
 * self-signed certificate means nobody installed one at all. Collapsing them would tell an operator
 * that something is wrong without telling them what to go and do.
 *
 * **Nullable throughout, and that is load-bearing.** Three states, not two: true, false, and this
 * check could not tell. A row written before this migration existed has never been judged; a host
 * that did not answer produced no judgement to store; and a platform installed on a container with no
 * certificate authorities cannot assess trust at all. Defaulting any of these to false would put a
 * fleet's worth of high-severity findings on the screen the morning after an upgrade, every one of
 * them describing this server rather than the site it names.
 *
 * The chain length is a count and only a count. Reading how many certificates a server sent answers
 * "did it include its intermediate"; storing the certificates themselves would be keeping somebody
 * else's chain on the off chance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->boolean('certificate_hostname_matches')->nullable();
            $table->boolean('certificate_trusted')->nullable();
            $table->boolean('certificate_self_signed')->nullable();
            $table->unsignedSmallInteger('certificate_chain_length')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn([
                'certificate_hostname_matches',
                'certificate_trusted',
                'certificate_self_signed',
                'certificate_chain_length',
            ]);
        });
    }
};
