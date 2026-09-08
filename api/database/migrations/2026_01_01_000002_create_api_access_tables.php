<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a tenant's storefront authenticates, how we stay idempotent under retries, and how every
 * mutation is attributable afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One row per connected storefront (typically one WordPress site).
        Schema::create('api_clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('site_url')->nullable();
            $table->jsonb('allowed_origins')->default('[]');
            $table->string('status')->default('active'); // active|disabled
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        // `key_id` is the public handle sent in the X-Seatmap-Key header. The secret is shown to
        // the tenant exactly once, at creation, and is stored encrypted rather than hashed:
        // verifying an HMAC signature requires the secret itself, so a hash would have to *be*
        // the signing key and a database leak alone would be enough to sign requests. Encrypted
        // with the application key, a leaked database is not.
        Schema::create('api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('api_client_id')->constrained()->cascadeOnDelete();
            $table->string('key_id')->unique();
            $table->text('secret'); // encrypted at rest, never logged, never returned
            $table->string('secret_hint', 8)->nullable(); // last characters, for the UI only
            $table->string('label')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['api_client_id', 'revoked_at']);
        });

        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('api_client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('url');
            // Encrypted (not hashed): we must reproduce it to sign outgoing deliveries.
            $table->text('signing_secret');
            $table->jsonb('event_types')->default('[]');
            $table->string('status')->default('active'); // active|paused|dead
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->jsonb('payload');
            $table->string('status')->default('pending'); // pending|delivered|failed|dead
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->text('response_body')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
        });

        // Replay store for mutating requests. `scope` separates callers so two tenants cannot
        // collide on a generic key like "1".
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->string('scope');
            $table->string('idempotency_key');
            $table->string('request_hash', 64);
            $table->string('endpoint');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['scope', 'idempotency_key']);
            $table->index('expires_at');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('actor_type')->default('system'); // user|api_key|device|system
            $table->string('actor_id')->nullable();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->string('request_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->jsonb('context')->default('{}');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'action', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
        Schema::dropIfExists('api_keys');
        Schema::dropIfExists('api_clients');
    }
};
