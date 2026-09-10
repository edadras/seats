<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The credentials that let a ticket go into a phone's wallet.
 *
 * Not something this platform can do on an organiser's behalf, and the honest thing is to say so
 * in the schema. An Apple Wallet pass is signed with a certificate issued to a named Apple
 * Developer account and carries that account's pass type identifier; a Google Wallet pass is
 * signed with a service-account key belonging to a named Google issuer. Neither can be borrowed:
 * a pass signed by us would say we sold the ticket.
 *
 * So the organiser supplies their own, once, and everything is encrypted at rest — these are keys
 * that can mint passes in their name, and a database backup should not be a way to do that.
 *
 * One row per account rather than per website. An organiser with three sites has one Apple
 * Developer account, and asking them to paste the same certificate three times would be asking
 * them to keep three copies of a secret in step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained()->cascadeOnDelete();

            // ---- Apple Wallet ------------------------------------------------------------
            $table->boolean('apple_enabled')->default(false);
            $table->string('apple_pass_type_id')->nullable();
            $table->string('apple_team_id')->nullable();
            $table->text('apple_certificate')->nullable();
            $table->text('apple_key')->nullable();
            $table->text('apple_key_password')->nullable();
            // Apple's own intermediate. Public, but it changes every few years and a pass signed
            // against the wrong one is a pass that silently will not open.
            $table->text('apple_wwdr')->nullable();

            // ---- Google Wallet -----------------------------------------------------------
            $table->boolean('google_enabled')->default(false);
            $table->string('google_issuer_id')->nullable();
            $table->text('google_service_account')->nullable();

            // ---- How it looks ------------------------------------------------------------
            $table->string('background_colour', 20)->nullable();
            $table->string('text_colour', 20)->nullable();
            $table->string('logo_text', 60)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_settings');
    }
};
