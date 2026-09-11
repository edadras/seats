<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Taking an account's data, and closing the account.
 *
 * A platform that cannot be left is a platform nobody can safely arrive at. Until now an organiser
 * who wanted out had two options, both of them somebody else's: ask support for a copy of their own
 * history, and ask support to switch the account off. Neither is a thing they could do, and the
 * first is not a thing anybody could do at all — there was no exporter.
 *
 * Two rows' worth of schema, and the reasoning is in what each of them is *for*.
 *
 * `account_exports` exists because an archive is a file, and a file has a life: it is built, it is
 * fetched, and then it stops being available. A link into an inbox with no expiry is a copy of an
 * organiser's entire history handed to whoever the message is forwarded to.
 *
 * The columns on `tenants` are what makes closing reversible for exactly as long as it should be.
 * `closed_at` is when selling stopped; `erase_after` is when there will be nothing left to change
 * their mind about. Keeping both, rather than deleting on the spot, is the difference between an
 * organiser who mis-clicked losing a season and losing an afternoon.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            // Who asked. Nulled rather than cascaded: an archive outlives the colleague who asked
            // for it, and "requested by somebody who has left" is still the truth about it.
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status')->default('building'); // building|ready|failed
            $table->string('path')->nullable();
            $table->unsignedBigInteger('bytes')->default(0);

            // What went into it, table by table. Shown on the screen so an organiser can see that
            // the archive holds what they think it holds before they rely on it.
            $table->jsonb('contents')->default('{}');

            $table->text('error')->nullable();
            $table->timestampTz('ready_at')->nullable();

            /*
             * When the file goes, whether or not anybody fetched it.
             *
             * The link's signature expires on the same instant. Two clocks for one promise would be
             * a link that works against a file that is gone, or a file nobody can reach.
             */
            $table->timestampTz('expires_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['expires_at']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->timestampTz('closed_at')->nullable();
            $table->string('close_reason', 200)->nullable();

            // The instant after which this account is not recoverable, because it will not be here.
            $table->timestampTz('erase_after')->nullable();

            $table->index(['erase_after']);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['erase_after']);
            $table->dropColumn(['closed_at', 'close_reason', 'erase_after']);
        });

        Schema::dropIfExists('account_exports');
    }
};
