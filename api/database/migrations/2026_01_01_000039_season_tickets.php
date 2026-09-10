<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The same seat, every night of the run, bought once.
 *
 * The decision that shapes everything below: **a season ticket is a way of buying, not a new kind
 * of admission**. A subscriber who turns up on the third Tuesday hands over an ordinary ticket for
 * that night, scanned by an ordinary scanner against an ordinary allocation. Nothing at the door,
 * on the door list, in the availability count or in the settlement learns that a season exists.
 *
 * So a season purchase produces exactly what buying each night separately would have produced —
 * one hold and one order per night — and adds one row above them saying they were one purchase.
 * The alternative, a single order spanning several events, would have been a smaller schema and a
 * much larger catastrophe: `external_orders.event_id` is what per-event revenue, the door list,
 * refunding one night and calling one night off are all keyed on, and a booking that belonged to
 * no single event would have quietly broken every one of them.
 *
 * The money is charged once. The pass's discount is **apportioned across the nights** rather than
 * taken off a group total, so each night's order still adds up on its own and the sum of them is
 * exactly what the buyer's card was charged. A discount that lived only on the group would make
 * every per-event figure in the system wrong by an amount nobody could reconstruct.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * What the organiser offers: a run, a name, and what buying all of it saves.
         *
         * `all` is every night in the series and is the ordinary subscription. `choose` is the
         * flexible pass — any `nights` of the run, the buyer picks which — and it is the same
         * mechanism with one extra rule, not a second feature.
         */
        Schema::create('season_passes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('series_id')->constrained('event_series')->cascadeOnDelete();

            $table->string('name', 160);
            $table->string('description', 400)->nullable();
            $table->string('kind', 10)->default('all');       // all|choose
            // `choose` only: the fewest nights that still count as a season ticket.
            $table->unsignedSmallInteger('nights')->nullable();

            $table->string('discount_kind', 10)->default('percent'); // percent|fixed
            $table->unsignedInteger('discount_value')->default(0);   // percent: 1–100. fixed: minor.
            $table->string('currency', 3);

            // How many seats one person may take on a pass. A subscription is for a household, not
            // for a broker, and the number that makes that true is the organiser's to set.
            $table->unsignedSmallInteger('max_seats')->default(6);

            $table->timestampTz('on_sale_at')->nullable();
            $table->timestampTz('off_sale_at')->nullable();
            $table->string('status', 10)->default('active');  // active|paused
            $table->unsignedSmallInteger('position')->default(0);
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'series_id', 'name']);
            $table->index(['tenant_id', 'status']);
        });

        // A pass that asks for a number of nights has to say which number, and one that does not
        // must not carry a stray one for somebody to wonder about later.
        DB::statement(<<<'SQL'
            ALTER TABLE season_passes ADD CONSTRAINT season_passes_nights_only_when_chosen CHECK (
                (kind = 'choose' AND nights IS NOT NULL AND nights >= 2)
                OR (kind = 'all' AND nights IS NULL)
            )
        SQL);

        /*
         * One purchase, over several nights.
         *
         * The row exists to answer three questions nothing else can: what the buyer paid in one
         * go, which orders that payment covers, and — when a gateway comes back — which set of
         * bookings to confirm. Its `reference` is what the buyer sees and what the gateway is told.
         *
         * `lead_order_id` is the order the gateway conversation is attached to, because the
         * interface takes an order and this purchase has several. It is a pointer, not a
         * privilege: the lead order is charged its own night's price like every other one.
         */
        Schema::create('season_bookings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('season_pass_id')->constrained('season_passes');
            $table->foreignUuid('series_id')->constrained('event_series');
            $table->foreignUuid('site_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reference', 40);
            $table->jsonb('buyer')->default('{}');
            $table->string('currency', 3);
            // The sum of the nights' orders, which is the sum of what the card was charged.
            $table->bigInteger('total_amount')->default(0);
            // What the pass took off, in total. Each night carries its own share of it.
            $table->bigInteger('discount_amount')->default(0);
            $table->unsignedSmallInteger('seats')->default(1);
            $table->unsignedSmallInteger('nights')->default(0);

            $table->string('status', 12)->default('pending'); // pending|confirmed|cancelled
            $table->foreignUuid('lead_order_id')->nullable()
                ->constrained('external_orders')->nullOnDelete();
            $table->string('gateway', 40)->nullable();
            $table->string('payment_reference')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestamps();

            // One reference per account, and it is derived from the first night's hold — so a
            // buyer whose browser retries the submit lands on the purchase they already made.
            $table->unique(['tenant_id', 'reference']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::table('external_orders', function (Blueprint $table) {
            // Which purchase this night belonged to, where it belonged to one. Nullable and
            // ignored by everything that counts seats or money: an order bought on a season ticket
            // is an ordinary order, and this column is the only thing that remembers otherwise.
            $table->foreignUuid('season_booking_id')->nullable()->after('hold_id')
                ->constrained('season_bookings')->nullOnDelete();
            $table->index(['season_booking_id']);
        });
    }

    public function down(): void
    {
        Schema::table('external_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('season_booking_id');
        });

        Schema::dropIfExists('season_bookings');
        Schema::dropIfExists('season_passes');
    }
};
