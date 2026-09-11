<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who a buyer's confirmation appears to come from.
 *
 * Every email this platform has ever sent has left from one address — the operator's
 * `MAIL_FROM_ADDRESS` — for every venue on it. A buyer who bought a ticket from Northgate Theatre
 * got a confirmation from a name they have never heard of, and replying to it reached nobody.
 *
 * Three columns and a code. The name needs no proving: it is not something you can receive mail at.
 * The address does, because until somebody has read a code sent to it, it is just a string an
 * organiser typed, and putting a stranger's address in `Reply-To` is a way to send them a venue's
 * customer complaints.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // What a buyer sees the message is from. No proof required: a display name is a label.
            $table->string('sender_name', 120)->nullable();
            // Where a reply should go. Used only once proved.
            $table->string('sender_email', 190)->nullable();
            $table->timestamp('sender_verified_at')->nullable();

            // The code, stored the way every other code here is: hashed, expiring, and capped, so
            // six digits cannot be guessed by a script with an afternoon.
            $table->string('sender_code_hash', 64)->nullable();
            $table->timestamp('sender_code_expires_at')->nullable();
            $table->unsignedSmallInteger('sender_code_attempts')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'sender_name', 'sender_email', 'sender_verified_at',
                'sender_code_hash', 'sender_code_expires_at', 'sender_code_attempts',
            ]);
        });
    }
};
