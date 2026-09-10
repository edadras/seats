<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A payment taken back after the tickets were sent, and the people who must not buy again.
 *
 * A chargeback is the one money event this platform had no word for, and the gap shows up in the
 * worst place: the bank takes the money back weeks later, nothing on the booking changes, and the
 * person walks through the door on a ticket that scans green. The venue has paid for their evening.
 *
 * Three decisions:
 *
 * **A chargeback is not a refund.** A refund is the organiser deciding; a chargeback is the bank
 * deciding, usually against them, often with a fee, and always after the fact. Sharing the word
 * would mean a settlement report that says an organiser gave money back when in fact it was taken.
 * So it is its own status, its own timestamp, and its own line in the takings.
 *
 * **The seats go back and the ticket stops working.** Not a policy: a booking nobody paid for is
 * not a booking. The allocation is released and the ticket voided in the same transaction, so the
 * chair is on sale again and the door refuses the QR code — which is the half a venue actually
 * cares about on the night.
 *
 * **A block is about a person, not an order.** Repeat chargebacks, a barring order, somebody who
 * must not be in the building: it is an address or a telephone number, a reason somebody typed, and
 * optionally a date it lapses. Checked where a booking is registered rather than at the door, so
 * the answer arrives before the money does — and it is never a silent failure, because a person
 * refused at a checkout with no explanation telephones the box office.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_orders', function (Blueprint $table) {
            $table->timestampTz('charged_back_at')->nullable();

            // What the bank charged for handling it, which is a real cost and belongs in the
            // takings rather than in somebody's memory of a bad month.
            $table->unsignedInteger('chargeback_fee')->default(0);
            $table->string('chargeback_reason', 190)->nullable();
        });

        Schema::create('blocked_buyers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            // One of the two, lower-cased and trimmed on the way in. An address is what a booking
            // is keyed by here; a number is what somebody gives when they have run out of them.
            $table->string('email', 190)->nullable();
            $table->string('phone', 40)->nullable();

            $table->string('reason', 500);

            // Null is "until somebody lifts it". A block with a date on it is the commoner and
            // kinder shape — six months, then they may try again.
            $table->timestampTz('until')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'email']);
            $table->unique(['tenant_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::table('external_orders', fn (Blueprint $table) => $table->dropColumn(
            ['charged_back_at', 'chargeback_fee', 'chargeback_reason']
        ));

        Schema::dropIfExists('blocked_buyers');
    }
};
