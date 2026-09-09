<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Codes that take money off, and a row for every time one was used.
 *
 * The redemption table is not bookkeeping for its own sake: it is how "this code may be used a
 * hundred times" survives two buyers pressing pay in the same second. A counter alone is a
 * read-modify-write and oversells; a unique row per (code, order) plus a conditional increment
 * cannot. It is also the only honest answer to "who used it", which is the first thing an
 * organiser asks about a code they gave to a radio station.
 *
 * Amounts are minor units, like every other amount in this system, and a fixed-amount code carries
 * the currency it is denominated in — a €5 code is meaningless against an event priced in rials,
 * so it is refused rather than converted at a rate nobody agreed to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            // Null means every event this account sells.
            $table->foreignUuid('event_id')->nullable()->constrained()->cascadeOnDelete();
            // Stored upper-cased and trimmed; what the buyer typed is normalised on the way in, so
            // "earlybird" and " EarlyBird " are the same code and not three near-misses.
            $table->string('code', 40);
            $table->string('description', 160)->nullable();
            $table->string('kind', 10);                       // percent|fixed
            $table->unsignedInteger('value');                 // percent: 1–100. fixed: minor units.
            $table->string('currency', 3)->nullable();        // fixed only
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->unsignedInteger('max_uses')->nullable();  // null: as often as anybody likes
            $table->unsignedInteger('used_count')->default(0);
            $table->unsignedSmallInteger('min_seats')->default(1);
            $table->string('status', 10)->default('active');  // active|paused
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            // One meaning per code per account. Two accounts may both have EARLYBIRD.
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('discount_redemptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('discount_code_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('external_order_row_id')->constrained('external_orders')->cascadeOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->timestamps();

            // The idempotency: an order can carry one redemption of one code, however many times
            // the buyer's browser retries the POST.
            $table->unique(['discount_code_id', 'external_order_row_id']);
            $table->index(['tenant_id', 'discount_code_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_redemptions');
        Schema::dropIfExists('discount_codes');
    }
};
