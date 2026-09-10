<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Things sold alongside a ticket, and money given for nothing at all.
 *
 * These are two shapes, not one, and the difference is worth stating because a system that merged
 * them would get the arithmetic wrong:
 *
 *   an **add-on** is a thing with a price and often a number of them — a programme, a glass of
 *   wine, a parking space. The organiser sets the price; the buyer chooses how many.
 *   a **donation** is an amount the buyer names. There is no stock, no unit price, and at most a
 *   suggestion.
 *
 * What separates them in the books is tax and the booking fee. Both apply to an add-on: a
 * programme is a sale like any other. **Neither applies to a donation** — a booking fee on a
 * donation is charging somebody for the privilege of giving you money, and taxing a gift is a
 * question for the organiser's accountant and not for this table. So a donation is carried as its
 * own column on the order rather than as a line among the add-ons, where it would be swept into a
 * subtotal that fees and VAT are computed from.
 *
 * Neither produces a ticket. An add-on is not an admission: it creates no allocation, no ticket,
 * no row on a door list, and nothing in the check-in path changes because of this migration. The
 * organiser reads what was bought off the order and hands it over at the counter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            // Null means it is offered with every event this account sells — a programme that is
            // the same programme all season.
            $table->foreignUuid('event_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 400)->nullable();
            $table->unsignedBigInteger('price');          // minor units, in the event's currency
            $table->string('currency', 3);

            /*
             * How many one booking may take, and how many exist at all.
             *
             * `stock` null means as many as anybody wants — a downloadable programme, a donation
             * to a raffle. Where it is set it is a sum against a limit, counted rather than
             * decremented, for the same reason every other limit in this system is.
             */
            $table->unsignedInteger('stock')->nullable();
            $table->unsignedSmallInteger('max_per_order')->default(10);

            /*
             * Whether the number offered is free-standing or follows the tickets.
             *
             *   order   "how many programmes?" — the buyer says
             *   ticket  one per ticket, priced per ticket and not chosen: a booking fee that is a
             *           thing rather than a fee, like a compulsory cloakroom charge
             */
            $table->string('per', 10)->default('order');
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('visible')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'event_id', 'position']);
        });

        /*
         * What one booking bought, and at what price it bought it.
         *
         * The price is copied, not joined: an organiser who puts the programme up next month must
         * not change what a booking made this month says it paid, which is the same rule the seat
         * price snapshot follows.
         */
        Schema::create('order_addons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('external_order_row_id')->constrained('external_orders')->cascadeOnDelete();
            $table->foreignUuid('addon_id')->nullable()->constrained()->nullOnDelete();
            // Denormalised at sale time, so a deleted add-on still reads as what it was.
            $table->string('name', 120);
            $table->unsignedSmallInteger('quantity');
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->timestampTz('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'external_order_row_id']);
            $table->index(['tenant_id', 'addon_id']);
        });

        // One line per add-on per booking. A retried submit adds nothing; it re-states.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX order_addons_one_per_order
            ON order_addons (external_order_row_id, addon_id)
            WHERE addon_id IS NOT NULL
        SQL);

        Schema::table('events', function (Blueprint $table) {
            /*
             * Whether this night asks for a donation, and what it suggests.
             *
             * A suggestion is a number in a box the buyer may overwrite, not a price: the whole of
             * a donation is that the giver decides. Zero suggested means "ask, but suggest
             * nothing", which is a real choice a charity makes on purpose.
             */
            $table->boolean('donations')->default(false)->after('on_sale_at');
            $table->string('donation_prompt', 200)->nullable()->after('donations');
            $table->unsignedBigInteger('donation_suggested')->nullable()->after('donation_prompt');
        });

        Schema::table('external_orders', function (Blueprint $table) {
            // Its own column, deliberately outside the subtotal fees and tax are computed from.
            $table->unsignedBigInteger('donation')->default(0)->after('total_amount');
        });
    }

    public function down(): void
    {
        Schema::table('external_orders', function (Blueprint $table) {
            $table->dropColumn('donation');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['donations', 'donation_prompt', 'donation_suggested']);
        });

        Schema::dropIfExists('order_addons');
        Schema::dropIfExists('addons');
    }
};
