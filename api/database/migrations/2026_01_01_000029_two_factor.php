<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A second step at the door of an account that can move money.
 *
 * The secret is encrypted at rest rather than hashed, because unlike a password the server has to
 * be able to read it back: a TOTP code is checked by computing the same code, not by comparing a
 * digest. Encryption is what stops a stolen database being a stolen set of authenticators.
 *
 * Recovery codes are the opposite: they are one-shot passwords, so they are hashed like passwords
 * and shown exactly once, when they are made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('totp_secret')->nullable()->after('password');
            // Set only once a code has actually been typed back correctly. A half-finished
            // enrolment must not lock somebody out of their own account.
            $table->timestampTz('totp_confirmed_at')->nullable()->after('totp_secret');
            $table->jsonb('recovery_codes')->nullable()->after('totp_confirmed_at');
        });

        Schema::table('tenants', function (Blueprint $table) {
            // An owner can require it of everybody. Until they do it is each person's own choice.
            $table->boolean('require_two_factor')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('require_two_factor');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['totp_secret', 'totp_confirmed_at', 'recovery_codes']);
        });
    }
};
