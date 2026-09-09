<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email verification codes for accounts that signed themselves up.
 *
 * A separate table rather than a column on `users`, because a code has three properties a column
 * cannot carry honestly: it expires, it is attempted a bounded number of times, and it is stored
 * hashed. A six-digit code in a column would be a six-digit code in a database backup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_verifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash', 64);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('sent_at');
            $table->timestamps();
            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verifications');
    }
};
