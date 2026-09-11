<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's own money.
 *
 * Everything about money in this codebase until now has been the organiser's: their gateways, their
 * refunds, their settlement, their payouts. The platform's own side existed as a price list and a
 * `subscriptions` row with a `current_period_end` that nothing ever looked at. An account signed up,
 * a period was written down, the period passed, and not one thing happened — no invoice, no charge,
 * no notice, no consequence. The plans were a marketing table.
 *
 * Two tables make it real. An invoice is what the platform says is owed, frozen at the moment it
 * was raised. A billing method is how it gets paid — a card the organiser put on file, or nothing
 * at all, which means somebody pays by transfer and a person marks it paid. Neither table ever
 * holds a card number: what is stored is a handle a gateway gave us, and the four digits a human
 * needs to recognise which card they are looking at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            /*
             * A number a person can quote.
             *
             * Sequential and never reused, because an invoice number is a thing that goes into
             * somebody else's accounts and gets asked about by somebody else's auditor. A uuid is
             * unique and useless for that.
             */
            $table->string('number', 40)->unique();

            $table->string('currency', 3);
            $table->date('period_from');
            $table->date('period_to');

            // Frozen. The plan's price and the platform's commission both change; an invoice that
            // was sent is not allowed to change with them.
            $table->unsignedBigInteger('subscription_amount')->default(0);
            $table->unsignedBigInteger('commission_amount')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedInteger('vat_rate')->default(0);

            // What was charged for, in the words the invoice was written in.
            $table->jsonb('lines')->default(DB::raw("'[]'::jsonb"));

            // open — raised and owed. paid — settled, however. uncollectible — the retries ran out
            // and a person has to deal with it. void — raised in error, and it never counted.
            $table->string('status', 16)->default('open');
            $table->date('due_on');
            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('paid_at')->nullable();

            // How the money arrived, so "we paid that by transfer in March" is answerable.
            $table->string('method', 40)->nullable();
            $table->string('reference', 190)->nullable();

            // The dunning ladder's state, on the row it belongs to rather than in a separate table
            // that could disagree with it.
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('last_attempt_at')->nullable();
            $table->text('last_error')->nullable();

            $table->foreignUuid('settled_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->string('void_reason', 200)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'period_from']);
            $table->index(['status', 'next_attempt_at']);
        });

        /*
         * One invoice per account per period, the same rule and the same mechanism as a payout.
         *
         * A billing run that fires twice — a retried job, two workers, an operator clicking while
         * the schedule runs — must not raise the same month twice. Voided invoices are out of the
         * way, because voiding one is how a mistake gets corrected.
         */
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        DB::statement(<<<'SQL'
            ALTER TABLE platform_invoices ADD CONSTRAINT platform_invoices_one_per_period
            EXCLUDE USING gist (
                tenant_id WITH =,
                daterange(period_from, period_to, '[]') WITH &&
            ) WHERE (status <> 'void')
        SQL);

        Schema::create('billing_methods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            // card — a card on file, charged off-session. invoice — a transfer, settled by a
            // person. The second is not the absence of a method; it is a choice, and an account
            // that made it should not be nagged to add a card.
            $table->string('kind', 16);
            $table->string('gateway', 40)->nullable();

            /*
             * Handles, never card data.
             *
             * `customer_reference` is who the gateway thinks this account is; `method_reference` is
             * which card of theirs to charge. Both are opaque strings that are worthless anywhere
             * but that gateway, which is the whole point of storing them instead of a number.
             */
            $table->text('customer_reference')->nullable();
            $table->text('method_reference')->nullable();

            // Only what a person needs to recognise their own card.
            $table->string('brand', 30)->nullable();
            $table->string('last4', 4)->nullable();
            $table->unsignedSmallInteger('exp_month')->nullable();
            $table->unsignedSmallInteger('exp_year')->nullable();

            $table->string('billing_name', 190)->nullable();
            $table->string('billing_email', 190)->nullable();
            $table->text('billing_address')->nullable();
            $table->string('vat_number', 40)->nullable();

            $table->boolean('is_default')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_default']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            /*
             * Past due is its own state, and it is not suspension.
             *
             * An account that has not paid keeps working while somebody sorts it out: the panel
             * says so on every screen and the people who run the platform are told. Cutting a venue
             * off is a separate decision with a box office and a full house on the other end of it,
             * so it stays where it already was — an operator suspending the account.
             */
            $table->timestampTz('past_due_since')->nullable();
            $table->timestampTz('last_invoiced_to')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['past_due_since', 'last_invoiced_to']);
        });

        Schema::dropIfExists('billing_methods');
        Schema::dropIfExists('platform_invoices');
    }
};
