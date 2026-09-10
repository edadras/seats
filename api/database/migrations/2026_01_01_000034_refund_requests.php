<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Can I have my money back?" — asked, answered, and written down.
 *
 * `refund_policy` already existed and answers a different question: what happens to the *seat*
 * when a refund is made — back on sale, or held out of it. Nothing anywhere said whether a buyer
 * may ask in the first place, so the answer was always the same one: telephone the box office,
 * and hope somebody is there.
 *
 * Three columns say the terms and one table records the asking. `refunds` is the rule — never, up
 * to a number of hours before the doors, or right up to them. `refund_window_hours` is that
 * number. `refund_keeps_fee` is the honest half of it: an organiser who paid a card fee to take
 * the money does not get that back, and a policy that pretended otherwise would be a policy
 * written by somebody who has never been charged one.
 *
 * A request is kept even when it is granted the same second it is made. "Why is this booking
 * refunded" is a question asked months later, and "because they asked on the 4th and the terms
 * allowed it" is a better answer than a status that changed for no recorded reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('refunds', 30)->default('never');
            $table->unsignedInteger('refund_window_hours')->default(48);
            $table->boolean('refund_keeps_fee')->default(true);
        });

        Schema::create('refund_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('external_order_row_id')->constrained('external_orders')->cascadeOnDelete();
            // Null means the whole booking. A buyer who cannot come but whose friend can asks for
            // one seat, and refusing to record that would make them cancel all four.
            $table->jsonb('allocation_ids')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('outcome_reason', 300)->nullable();
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['event_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_requests');

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['refunds', 'refund_window_hours', 'refund_keeps_fee']);
        });
    }
};
