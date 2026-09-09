<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The queue for a sold-out night.
 *
 * Seats come back all the time — a refund, a hold that expired, a block lifted — and until now they
 * went back on sale silently, to whoever happened to be looking. The people who wanted them most
 * had already closed the tab.
 *
 * One row per person per event, in the order they asked. The token is what makes the two links in
 * the email work — the one that takes them to the seats and the one that takes them off the list —
 * without either being a password or an id anybody could guess their way along.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waiting_list_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('phone', 40)->nullable();
            $table->unsignedSmallInteger('quantity')->default(1);
            // What language they were reading the site in when they asked.
            $table->string('locale', 12);
            $table->string('status', 12)->default('waiting'); // waiting|notified|converted|left
            $table->string('token', 64)->unique();
            $table->timestampTz('notified_at')->nullable();
            /*
             * How long their turn lasts.
             *
             * Without it, one person who never opens the email holds the queue for ever. With it,
             * the next round of notifications simply passes them by — they stay on the list and
             * come round again, rather than being thrown off for being asleep.
             */
            $table->timestampTz('claim_expires_at')->nullable();
            $table->timestampTz('left_at')->nullable();
            $table->timestamps();

            // One place in the queue per person per event: asking twice is not asking harder.
            $table->unique(['event_id', 'email']);
            $table->index(['tenant_id', 'event_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waiting_list_entries');
    }
};
