<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Holds, orders, allocations and tickets — the inventory core.
 *
 * The two partial unique indexes at the bottom are the load-bearing part of this file. Application
 * code takes locks and re-checks state, but the database is what actually guarantees that one seat
 * is sold once (ADR-0002 §2). If they are ever dropped, the concurrency tests fail loudly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('seat_map_version_id')->constrained('seat_map_versions');
            $table->string('token')->unique();
            $table->string('session_id')->nullable();
            $table->string('source')->default('embed'); // embed|api|panel
            $table->foreignUuid('api_client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('active'); // active|released|expired|converted
            $table->timestampTz('expires_at');
            $table->unsignedSmallInteger('extends_used')->default(0);
            $table->string('currency', 3);
            $table->bigInteger('total_amount')->default(0);
            $table->jsonb('price_snapshot')->default('{}');
            $table->string('external_order_id')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->timestampTz('converted_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
            $table->index(['event_id', 'status', 'expires_at']);
            $table->index(['session_id', 'event_id']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('hold_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('hold_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('seat_id')->constrained();
            $table->bigInteger('amount');
            $table->string('zone_key')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->timestamps();
            $table->index(['event_id', 'seat_id']);
            $table->index(['hold_id']);
        });

        // The SaaS-side shadow of a WooCommerce order. Deliberately thin: no line items, no tax,
        // no totals we would have to keep in step with the shop (ADR-0001).
        Schema::create('external_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained();
            $table->foreignUuid('api_client_id')->constrained();
            $table->foreignUuid('hold_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_order_id');
            $table->string('status')->default('pending'); // pending|confirmed|cancelled|refunded|partially_refunded
            $table->string('currency', 3);
            $table->bigInteger('total_amount')->default(0);
            $table->jsonb('buyer')->default('{}');
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('refunded_at')->nullable();
            $table->timestamps();
            $table->unique(['api_client_id', 'external_order_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained();
            $table->foreignUuid('seat_id')->constrained();
            $table->foreignUuid('hold_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('external_order_row_id')->nullable()->constrained('external_orders')->nullOnDelete();
            $table->foreignUuid('api_client_id')->constrained();
            $table->string('external_order_id');
            $table->string('status')->default('active'); // active|released|void
            $table->bigInteger('amount');
            $table->string('currency', 3);
            // Denormalised at sale time so a ticket and a report stay readable even if a later map
            // version renames the section or drops the seat.
            $table->foreignUuid('seat_map_version_id')->constrained('seat_map_versions');
            $table->string('section_name');
            $table->string('row_name');
            $table->string('seat_label');
            $table->timestampTz('allocated_at');
            $table->timestampTz('released_at')->nullable();
            $table->timestamps();
            $table->index(['event_id', 'status']);
            $table->index(['external_order_id']);
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained();
            $table->foreignUuid('allocation_id')->unique()->constrained()->cascadeOnDelete();
            // Only the hash is stored: a database leak must not yield working QR codes.
            $table->string('token_hash', 64)->unique();
            $table->string('token_prefix', 12)->index(); // for support lookup, not for auth
            $table->string('status')->default('issued'); // issued|used|void
            $table->string('holder_name')->nullable();
            $table->timestampTz('issued_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->timestamps();
            $table->index(['event_id', 'status']);
        });

        // --- Integrity guarantees ---------------------------------------------------------
        // Postgres partial unique indexes. These are the reason a race cannot double-sell.

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX allocations_one_active_per_seat
            ON allocations (event_id, seat_id)
            WHERE status = 'active'
        SQL);

        // A retried confirm for the same order cannot mint a second allocation for the same seat,
        // even if it somehow got past the idempotency store.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX allocations_unique_per_external_order_seat
            ON allocations (api_client_id, external_order_id, seat_id)
        SQL);

        // At most one live hold item per seat. Expired-but-unswept rows are reclaimed inside the
        // creating transaction (see HoldService), so this never wedges a seat past its TTL.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX hold_items_one_active_per_seat
            ON hold_items (event_id, seat_id)
            WHERE released_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('allocations');
        Schema::dropIfExists('external_orders');
        Schema::dropIfExists('hold_items');
        Schema::dropIfExists('holds');
    }
};
