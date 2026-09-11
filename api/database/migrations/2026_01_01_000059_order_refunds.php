<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money sent back, and what the gateway called it.
 *
 * Until now a refund released the seats and wrote the books, and nobody's card was ever credited —
 * somebody had to open the gateway's own dashboard and do it by hand, from memory, against a
 * reference they had to find. These rows are the other half: what was sent, to which payment,
 * through which gateway, and what came back.
 *
 * Kept even when nothing could be sent. A booking paid in cash has a row saying so, because "the
 * organiser owes this person money out of the drawer" is a fact worth writing down, and an empty
 * table cannot tell "not refunded" from "refunded at the counter".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('external_order_row_id')->constrained('external_orders')->cascadeOnDelete();

            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);

            // sent — the gateway took it back. manual — there was no gateway to ask, so somebody
            // hands it over. failed — the gateway said no, and nothing else happened either.
            $table->string('status', 16);
            $table->string('gateway', 40)->nullable();
            $table->string('reference', 190)->nullable();
            $table->string('reason', 200)->nullable();
            $table->text('message')->nullable();

            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['external_order_row_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_refunds');
    }
};
