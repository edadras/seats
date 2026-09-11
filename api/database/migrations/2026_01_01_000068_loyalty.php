<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A reason to come back: points earned by coming, and what they are worth.
 *
 * Everything else on this platform is about one night. This is the first thing that is about the
 * years either side of it — the subscriber who has been in row F since 2011, the person who came
 * to four things last season and might come to six. A box office knows those people; the software
 * did not.
 *
 * Two tables, and the split is the usual one on this platform: a ledger, and the rules it is read
 * under. A balance is never stored. It is the sum of the movements, in the same way a voucher's
 * balance and an agent's credit are, so a points total cannot drift from the events that produced
 * it and there is no counter to repair when one of them is undone.
 *
 * Points belong to an email address rather than to a person, because a buyer on this platform *is*
 * an email address — there is no accounts table and never has been (see CustomerDirectory). That is
 * also why the address is stored beside each movement rather than joined to: an order erased under
 * a privacy request must not take somebody's points with it, and a points row that named nothing
 * would be a balance belonging to nobody.
 *
 * One programme per account, in one currency. A rate of "a point per euro" says nothing useful in
 * an account that also sells in rials, and a single pool fed by two currencies is arithmetic
 * nobody can explain to a customer. The programme names its currency and the takings in any other
 * simply do not earn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_programmes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('name', 80);
            $table->boolean('enabled')->default(false);
            $table->string('currency', 3);

            // Points for one whole unit of that currency — one per euro, one per hundred yen.
            // Minor units would make the rate a number nobody can read off a receipt.
            $table->unsignedInteger('earn_rate')->default(1);

            // And back the other way: how many points buy one whole unit of credit, and the
            // smallest number worth turning in. A minimum exists so that a programme is not a
            // machine for issuing four-cent vouchers.
            $table->unsignedInteger('points_per_unit')->default(100);
            $table->unsignedInteger('min_redeem')->default(500);

            /*
             * The ladder. A list of {key, name, from_points}, read in order.
             *
             * Kept as a list rather than a table because it is a setting rather than a record: it
             * is rewritten whole, nothing points at a rung, and a tier that stops existing should
             * stop existing rather than leave orphans behind.
             */
            $table->jsonb('tiers')->default(DB::raw("'[]'::jsonb"));

            /*
             * How far back a tier looks, and how long silence lasts before points go.
             *
             * A tier from points earned in a rolling window can be lost, which is what makes it
             * mean anything — a "gold" that only ever ratchets up is a label, not a standing.
             * Expiry is on inactivity rather than per point: "use it or lose it" is a rule a
             * customer can hold in their head, and first-in-first-out expiry of individual points
             * is a rule nobody can check against their own receipts.
             */
            $table->unsignedSmallInteger('window_months')->default(12);
            $table->unsignedSmallInteger('inactive_months')->nullable();

            $table->timestamps();
        });

        Schema::create('loyalty_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            // Lower-cased on the way in. Two spellings of one address are two people to a database
            // and one person to everybody else.
            $table->string('email');

            $table->string('kind', 12); // earn | spend | reverse | expire | adjust

            // Signed: what happened to the balance. A reversal is a negative earn rather than a
            // deletion, so the history still says that somebody came and then gave the seat back.
            $table->integer('points');

            $table->foreignUuid('external_order_row_id')->nullable()
                ->constrained('external_orders')->nullOnDelete();

            $table->string('note', 200)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'email']);
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::table('events', function (Blueprint $table) {
            /*
             * The tier that gets in early, where a night has one.
             *
             * Null is the ordinary case and means what it always meant: a presale is for people
             * holding a code. With a tier named, somebody signed in at or above it is let through
             * without one — which is the only thing a tier can do that a label cannot.
             */
            $table->string('tier_presale', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('tier_presale');
        });

        Schema::dropIfExists('loyalty_movements');
        Schema::dropIfExists('loyalty_programmes');
    }
};
