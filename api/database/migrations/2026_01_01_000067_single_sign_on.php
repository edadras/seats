<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signing in the way a large venue already does, and scoping a key to what it is for.
 *
 * Two things that look unrelated and are the same thing: who may do what, said once rather than
 * implied. A national theatre with a hundred staff does not want a hundred passwords on somebody
 * else's platform — it wants the accounts it already governs, joined and left in one place. And a
 * shop's key, which today can do everything the shop's account can, should be able to sell a ticket
 * without also being able to refund one.
 *
 * The provider row holds what was discovered as well as what was typed. An OpenID Connect issuer
 * publishes its endpoints at a well-known address, and reading them once at save time is the
 * difference between an organiser typing three URLs correctly and an organiser typing one.
 *
 * `required` is the switch that makes single sign-on mean something: with it on, a password is not
 * a way in to this account at all. The way back from a misconfigured provider is deliberately not
 * a password — it is the platform, which can switch the provider off for an account that has
 * locked itself out. A break-glass password would be the thing an attacker looks for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_providers', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // One per account. Two would be a question with no answer — which of them does a
            // person signing in belong to — and no venue has asked it.
            $table->foreignUuid('tenant_id')->unique()->constrained()->cascadeOnDelete();

            // What the button says. "Sign in with Trinity College" is a sentence somebody at
            // Trinity College recognises; "Sign in with OpenID Connect" is not.
            $table->string('label', 80);

            $table->string('issuer');
            $table->string('client_id');

            // Encrypted rather than hashed: the code exchange presents it to the issuer, so it has
            // to be readable. Never returned by any endpoint, and replaced by a marker in an export.
            $table->text('client_secret');

            // Read from the issuer's own well-known document at save time, so an organiser types
            // one address rather than three and a typo is caught while they are looking at it.
            $table->string('authorize_url')->nullable();
            $table->string('token_url')->nullable();
            $table->string('userinfo_url')->nullable();
            $table->timestampTz('discovered_at')->nullable();

            $table->boolean('enabled')->default(true);

            /*
             * Whether a password is still a way in.
             *
             * Off by default and switched on deliberately: an account that turns this on before it
             * has tried signing in through its provider is an account that has locked itself out,
             * and the screen says so before the switch moves.
             */
            $table->boolean('required')->default(false);

            $table->timestamps();
        });

        Schema::table('api_keys', function (Blueprint $table) {
            /*
             * What this key may do. Null means everything, which is what every key issued before
             * today could already do — a migration that silently narrowed live keys would take a
             * working shop off sale at the moment it was deployed.
             */
            $table->jsonb('scopes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn('scopes');
        });

        Schema::dropIfExists('identity_providers');
    }
};
