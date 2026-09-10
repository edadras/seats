<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a sale came from, and who is owed for it.
 *
 * Two questions that look like one and are not. "Which post sold these forty tickets" is marketing,
 * answered by whatever the link carried. "What do we owe Maria for the forty she sold" is money,
 * answered by a person with a name, an agreement and an invoice. A platform that only models the
 * first makes the second a spreadsheet somebody maintains by hand.
 *
 * Three decisions:
 *
 * **A promoter is a person, not a parameter.** They have a name, a code that goes in their link,
 * and a percentage. `utm_source=maria` is a string that anybody can type; a promoter is a row an
 * organiser created deliberately, which is what makes it safe to pay against.
 *
 * **Attribution is written on the order, once, and never recomputed.** What a buyer clicked is a
 * fact about that afternoon: a promoter later renamed, deactivated, or given a different rate must
 * not silently rewrite what happened. So the link, the campaign and the promoter are stamped on
 * the booking at the moment it is registered and left alone afterwards.
 *
 * **What is owed is derived, never stored.** Commission is a percentage of what the tickets came
 * to — it moves when a booking is refunded, and a stored number would not. The rate itself is
 * stamped alongside the promoter for exactly the same reason the attribution is: changing today's
 * rate must not change last month's invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promoters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);

            // What goes in the link. Short, theirs, and unique to the account rather than the
            // platform: two theatres may both have a Maria.
            $table->string('code', 40);
            $table->string('contact_email', 190)->nullable();

            // Basis points: 750 is 7.5%. The same unit the tax rate uses, for the same reason —
            // a percentage with a half in it is a percentage somebody will round wrongly.
            $table->unsignedInteger('commission_rate')->default(0);
            $table->boolean('active')->default(true);
            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::table('external_orders', function (Blueprint $table) {
            // Nulled rather than cascaded: deleting a promoter must not delete the record of what
            // they sold, and an order that loses its promoter keeps its attribution below.
            $table->foreignUuid('promoter_id')->nullable()->constrained('promoters')->nullOnDelete();

            // The link as it was: campaign, source, medium, the promoter's code and their rate on
            // the day. A copy, deliberately, because this is what happened rather than what is.
            $table->jsonb('attribution')->nullable();

            $table->index(['promoter_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('external_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('promoter_id');
            $table->dropColumn('attribution');
        });

        Schema::dropIfExists('promoters');
    }
};
