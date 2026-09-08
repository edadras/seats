<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modules (ADR-0004).
 *
 * There is deliberately no `modules` table listing what is installed. What exists on this server is
 * a property of the deployment, discovered from the `modules/` directory; putting it in a row would
 * invite a panel that edits it, and a panel that edits which code runs is not a panel.
 *
 * What *is* stored is per tenant: whether an organiser has switched a module on, and the settings
 * they gave it. Installing is an operator's act; enabling is a tenant's. Those are different acts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_modules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            // The manifest key, `vendor/name`. Not a class name: turning organiser input into a
            // class to instantiate is how an admin panel becomes a remote shell.
            $table->string('module_key');

            $table->boolean('enabled')->default(false);

            /*
             * Settings as the organiser gave them. Values whose schema entry is marked secret are
             * encrypted here and never read back out to a panel — the API answers "set" or "not
             * set" and offers to replace (ADR-0004 §3).
             */
            $table->jsonb('settings')->default('{}');

            // Set when the platform disables a module because it kept failing, with the reason the
            // organiser reads. Null when the organiser is the one who turned it off.
            $table->string('disabled_reason')->nullable();
            $table->timestamp('disabled_at')->nullable();

            $table->timestamps();

            // One row per module per tenant, so "is this on" has exactly one answer.
            $table->unique(['tenant_id', 'module_key']);
        });

        /*
         * What went wrong, per module, per tenant.
         *
         * A module that throws must not fail the request that triggered it — but a messaging module
         * that has quietly stopped sending tickets looks exactly like one that is working, so the
         * failure is written down where the organiser can see it (ADR-0004 §5).
         */
        Schema::create('module_failures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('module_key');
            $table->string('extension_point');       // payments|messaging|events|…
            $table->string('operation')->nullable(); // the method or listener that failed
            $table->text('message');
            $table->jsonb('context')->default('{}');
            $table->timestamp('created_at');

            $table->index(['tenant_id', 'module_key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_failures');
        Schema::dropIfExists('tenant_modules');
    }
};
