<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The two things a person can do with a ticket they cannot use, besides asking for their money back.
 *
 * **Exchange** is moving to another night, or to other seats on the same one. It is the thing most
 * often asked for and the thing this platform has been quietly refusing: until now the answer was
 * "we will refund you, then buy again", which loses the seats in between and is how a buyer ends up
 * with neither.
 *
 * **Resale** is putting the ticket back on sale at what they paid for it. A venue that offers it
 * takes the touts' business away from them, and gets a full house instead of an empty seat and a
 * refund. It is also the harder of the two, and the shape of it here is the careful part:
 *
 * A listed seat **keeps its allocation** until somebody else actually buys it. The obvious design —
 * release the seat when it is listed, sell it again, refund the seller — takes the ticket away from
 * somebody who is still going if nobody buys it. So a listing does not move any inventory: it makes
 * the seat *offered* while it is still theirs, and the swap happens in one transaction at the
 * moment of sale, where the old allocation is released, its ticket voided and the seller paid in
 * the same breath as the new allocation is written.
 *
 * The seller is paid in **credit by default**, and the reason is not meanness: a refund to a card
 * that was charged eleven months ago frequently fails, and a voucher is the only form of "your
 * money back" a venue can promise to honour on the spot. An organiser can choose otherwise per
 * event.
 *
 * Both are off by default. A venue that has never thought about either should not discover it has
 * been offering them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // never|until|always — the same three words the refund terms use, because an organiser
            // who has learned one of these has learned the other.
            $table->string('exchanges', 20)->default('never')->after('refund_keeps_fee');
            $table->unsignedSmallInteger('exchange_window_hours')->default(48)->after('exchanges');
            // What the box office keeps for the work of it. Nought on most nights.
            $table->bigInteger('exchange_fee_amount')->default(0)->after('exchange_window_hours');

            // Whether a buyer may offer their seat back to the public.
            $table->boolean('resale')->default(false)->after('exchange_fee_amount');
            // credit|refund. Credit is the default: see the class note.
            $table->string('resale_pays', 10)->default('credit')->after('resale');
        });

        Schema::create('resale_listings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();

            /*
             * The seat being offered, by the allocation that still holds it.
             *
             * Not unique, deliberately: somebody may offer a seat, think better of it, and offer it
             * again a week later, and each of those is a row worth keeping. What must never happen
             * twice at once is said by the partial index below. A listing pointing at an allocation
             * rather than at a seat is what keeps "whose ticket was this" answerable after a swap.
             */
            $table->foreignUuid('allocation_id')->constrained()->cascadeOnDelete();

            // Who is owed the money if it sells, and what they paid for it.
            $table->string('seller_email', 190);
            $table->string('seller_name', 190)->nullable();
            $table->bigInteger('amount');
            $table->string('currency', 3);

            $table->string('state', 12)->default('open'); // open|sold|withdrawn
            $table->timestampTz('listed_at');
            $table->timestampTz('settled_at')->nullable();
            // The voucher the seller was paid with, where they were paid in credit.
            $table->foreignUuid('voucher_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['allocation_id', 'state']);
            $table->index(['event_id', 'state']);
            $table->index(['tenant_id', 'state']);
        });

        /*
         * One *open* listing per seat, said by the database rather than only by the service.
         *
         * Partial, because a seat that was offered, taken down and offered again has three rows and
         * two of them are history. A plain unique here would refuse the second offer; no unique at
         * all would let one seat be on sale twice, which is the failure that ends with two people
         * holding the same chair.
         */
        DB::statement(
            'CREATE UNIQUE INDEX resale_listings_one_open_per_allocation ON resale_listings (allocation_id) '.
            "WHERE state = 'open'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('resale_listings');

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn([
                'exchanges', 'exchange_window_hours', 'exchange_fee_amount', 'resale', 'resale_pays',
            ]);
        });
    }
};
