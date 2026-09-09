<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Handing one ticket to somebody else.
 *
 * A booking of four is rarely four people who all arrive together. Until now the only way to give
 * one away was to forward the email, which leaves two people holding the same code and the door
 * turning one of them away.
 *
 * The row is the record, not the mechanism: the ticket itself is reissued at the moment of
 * transfer, so there is exactly one live code at every instant. This exists so that "who did this
 * ticket start with" is answerable — by the box office at the window, and by the organiser when
 * somebody claims they never got it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('external_order_row_id')->constrained('external_orders')->cascadeOnDelete();
            $table->string('from_email', 190);
            $table->string('from_name', 120)->nullable();
            $table->string('to_email', 190);
            $table->string('to_name', 120);
            $table->timestampTz('transferred_at');
            $table->timestamps();

            $table->index(['tenant_id', 'ticket_id']);
            $table->index(['tenant_id', 'to_email']);
        });

        Schema::table('tickets', function (Blueprint $table) {
            // Where to write when this ticket is reissued, once it is no longer the buyer's.
            $table->string('holder_email', 190)->nullable()->after('holder_name');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('holder_email');
        });

        Schema::dropIfExists('ticket_transfers');
    }
};
