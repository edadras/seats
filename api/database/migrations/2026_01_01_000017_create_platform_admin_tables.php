<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who runs the platform.
 *
 * A table of its own rather than a flag on `users`, and deliberately not a role inside a tenant:
 * an operator is not a member of anybody's account. That separation is the whole security story
 * here — a tenant role that could be escalated into platform access would make every organiser's
 * data one bug away from every other organiser's.
 *
 * `level` is the same distinction every support team ends up needing: somebody who can look, and
 * somebody who can change things.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_admins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('level', 20)->default('support'); // support|operator
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('platform_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 80);
            $table->uuid('tenant_id')->nullable();
            $table->jsonb('context')->default('{}');
            $table->string('ip', 45)->nullable();
            // Six decimal places, because two things an operator did in the same second have an
            // order and a support conversation depends on knowing it.
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->index(['created_at']);
            $table->index(['tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_audit_logs');
        Schema::dropIfExists('platform_admins');
    }
};
