<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The people who sell an organiser's tickets on the organiser's behalf.
 *
 * Not a promoter, who posts a link and is paid a percentage of whatever it brings in. An agent is a
 * shop, an agency or a bureau that takes money from the public over its own counter — so the
 * questions are different, and all three of them are about trust: what may this agent sell, how far
 * are they allowed to go before they have paid us, and what is between us today.
 *
 * Three decisions:
 *
 * **An allowance is a list, not a level.** "Can sell events" is not a permission anybody actually
 * grants: a bureau is given the summer festival and not the members' evening. So an agent holds
 * the events they may sell, one row each, with an "everything" flag for the house's own shops that
 * would otherwise need a row per night for ever.
 *
 * **Credit is money that moved, and everything else is counted.** Only what genuinely passes
 * between the two — a payment in, a settlement out, an adjustment somebody signed — is written
 * down. What an agent has sold, what they have refunded and what commission they have earned are
 * read from the allocations every time they are asked for, so a refunded ticket returns its credit
 * without anything having to remember to, and no two columns can disagree.
 *
 * **The rate is stamped on the booking.** Agreeing a new percentage next season must not rewrite
 * what was owed for this one, for the same reason a promoter's rate is copied onto the order at
 * the moment of sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_agents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name', 160);
            // Short, theirs, and unique to the account: two organisers may both have a "Bureau 12".
            $table->string('code', 40);
            $table->string('contact_name', 120)->nullable();
            $table->string('contact_email', 190)->nullable();
            $table->string('contact_phone', 40)->nullable();

            // Who signs in as this agent, where they sell through the panel rather than the API.
            // Nulled rather than cascaded: removing a person must not delete the account they sold
            // against, because that account has money in it.
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            // And the channel they sell through, which is what a quota is written against.
            $table->foreignUuid('api_client_id')->nullable()->constrained('api_clients')->nullOnDelete();

            // Basis points: 750 is 7.5%, the same unit the promoter's rate and the tax rate use.
            $table->unsignedInteger('commission_rate')->default(0);

            /*
             * How far past nought this agent may go.
             *
             * Nought means prepaid: they sell what they have paid for and not a ticket more. A
             * number is a line of credit somebody decided to extend, in the account's own minor
             * unit, and it is the only thing standing between a friendly arrangement and an agency
             * that owes eleven thousand euros nobody noticed.
             */
            $table->unsignedBigInteger('credit_limit')->default(0);

            $table->boolean('all_events')->default(false);
            $table->boolean('active')->default(true);
            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'active']);
        });

        Schema::create('sales_agent_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sales_agent_id')->constrained('sales_agents')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();

            // One row per allowance: granting the same night twice is granting it once.
            $table->unique(['sales_agent_id', 'event_id']);
        });

        Schema::create('agent_credit_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sales_agent_id')->constrained('sales_agents')->cascadeOnDelete();

            /*
             * What moved, and which way.
             *
             * `topup` is the agent paying the organiser in advance; `settlement` is the organiser
             * paying the agent — commission, or money back on a closed account; `adjustment` is
             * anything else somebody was willing to sign their name to. Signed amounts, because a
             * ledger that stores a direction in a second column is a ledger somebody will read
             * without it.
             */
            $table->string('kind', 20);
            $table->bigInteger('amount');
            $table->string('currency', 3);
            $table->string('method', 20)->nullable();
            $table->string('reference', 190)->nullable();
            $table->string('note', 200)->nullable();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['sales_agent_id', 'created_at']);
        });

        Schema::table('external_orders', function (Blueprint $table) {
            $table->foreignUuid('sales_agent_id')->nullable()
                ->constrained('sales_agents')->nullOnDelete();

            // The rate as it was agreed on the day. A copy, deliberately: this is what happened
            // rather than what is.
            $table->unsignedInteger('agent_rate')->default(0);

            $table->index(['sales_agent_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('external_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_agent_id');
            $table->dropColumn('agent_rate');
        });

        Schema::dropIfExists('agent_credit_entries');
        Schema::dropIfExists('sales_agent_events');
        Schema::dropIfExists('sales_agents');
    }
};
