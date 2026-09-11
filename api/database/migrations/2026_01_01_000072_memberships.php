<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A venue's Friends scheme.
 *
 * Loyalty is earned by coming and a season ticket is one run; this is neither. It is a fee, a
 * period and a set of privileges — the thing a theatre has had since before any of this was
 * software, and the thing its regulars ask about first.
 *
 * A scheme is sold through the machinery that already exists for selling something that is not a
 * seat: an add-on. That is why `addon_id` is here rather than a second checkout — a membership
 * bought beside a ticket goes through the same order, the same gateway, the same totals and the
 * same refund path as a programme or a glass of wine, and nothing downstream has to learn a new
 * shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_schemes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('description', 300)->default('');
            $table->string('currency', 3);
            // What it costs to join, in minor units.
            $table->unsignedInteger('price')->default(0);
            // How long it lasts. Months rather than a date, because a scheme is a length and a
            // membership is a period — somebody who joins in June is a member until next June.
            $table->unsignedSmallInteger('months')->default(12);
            // What a member gets. Percentage off the seats, and nothing off the fee, the tax or
            // the programme — the same rule a discount code follows.
            $table->unsignedTinyInteger('discount_percent')->default(0);
            // Whether this scheme walks past the presale door on a night that lets members in.
            $table->boolean('presale')->default(false);
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            /*
             * The add-on this scheme is sold as, where the organiser sells it online.
             *
             * Nullable: a scheme can exist and be granted at the window without ever being on sale
             * on the website. Null on delete rather than cascade — an add-on removed from sale must
             * not take the membership scheme, and everybody in it, with it.
             */
            $table->foreignUuid('addon_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'enabled']);
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('membership_scheme_id')->constrained()->cascadeOnDelete();
            // Keyed by address, like everything else a buyer owns here: there is no account to
            // belong to, and an address is what a booking and a presale both carry.
            $table->string('email', 190);
            $table->string('name', 120)->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('source', 20)->default('bought'); // bought | granted
            /*
             * The booking it was bought with, where it was bought.
             *
             * `nullOnDelete` and not unique: a renewal is a second row, and one order could in
             * principle carry two schemes. What stops a membership being granted twice for the same
             * order is a lookup on this column, which is why it is indexed.
             */
            $table->foreignUuid('external_order_row_id')->nullable()
                ->constrained('external_orders')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'email']);
            $table->index(['tenant_id', 'ends_at']);
            $table->index(['external_order_row_id']);
        });

        Schema::table('events', function (Blueprint $table) {
            // Whether members get in before the general sale on this night.
            $table->boolean('member_presale')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('member_presale');
        });

        Schema::dropIfExists('memberships');
        Schema::dropIfExists('membership_schemes');
    }
};
