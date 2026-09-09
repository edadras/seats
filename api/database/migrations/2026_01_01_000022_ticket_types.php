<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who the ticket is for, on the seat that was already priced.
 *
 * A concession is not a different seat and not a different zone: row A seat 5 costs one thing, and
 * a child sitting in it pays a stated part of that. So a ticket type is an *adjustment to the seat's
 * own price*, not a second price list — which means an organiser who re-prices the stalls does not
 * have to re-price the children's stalls, the students' stalls and the seniors' stalls as well.
 *
 * An event with no types behaves exactly as it did: `ticket_type_id` stays null everywhere and
 * nothing in the picker, the checkout or the ticket mentions a type at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('description', 200)->nullable();
            // standard: the seat's own price. The other three adjust it.
            $table->string('kind', 12)->default('standard'); // standard|percent_off|amount_off|fixed
            $table->integer('value')->default(0);
            /*
             * Exactly one type per event carries this. It is what a picker offers before the buyer
             * has said anything, and what an integration that knows nothing about types gets — so
             * an older WooCommerce plugin keeps selling full-price tickets and does not have to be
             * taught about concessions to keep working.
             */
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('min_per_order')->default(0);
            $table->unsignedSmallInteger('max_per_order')->nullable();
            // "Bring your student card." Shown at checkout and printed on the ticket.
            $table->string('proof_note', 160)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('status', 10)->default('active'); // active|hidden
            $table->timestamps();

            $table->index(['tenant_id', 'event_id']);
        });

        /*
         * One default per event, enforced by the database rather than by whoever writes next.
         *
         * Written as raw SQL because it is a *partial* index — the constraint is "at most one row
         * per event has is_default true", and a plain unique on (event_id, is_default) would also
         * forbid a second row with it false, which is every other ticket type there is.
         */
        DB::statement(
            'CREATE UNIQUE INDEX ticket_types_one_default ON ticket_types (event_id) WHERE is_default'
        );

        Schema::table('hold_items', function (Blueprint $table) {
            $table->foreignUuid('ticket_type_id')->nullable()->after('capacity_object_id')
                ->constrained()->nullOnDelete();
        });

        Schema::table('allocations', function (Blueprint $table) {
            $table->foreignUuid('ticket_type_id')->nullable()->after('seat_id')
                ->constrained()->nullOnDelete();
            // The name as it was at the moment of sale. Types get renamed — "Under 16" becomes
            // "Child" — and a ticket printed last month must keep saying what it said.
            $table->string('ticket_type_name', 80)->nullable()->after('ticket_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('allocations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ticket_type_id');
            $table->dropColumn('ticket_type_name');
        });

        Schema::table('hold_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ticket_type_id');
        });

        Schema::dropIfExists('ticket_types');
    }
};
