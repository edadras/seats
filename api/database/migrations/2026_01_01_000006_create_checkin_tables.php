<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkin_operators', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('checkin_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('checkin_operator_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            // Pairing codes are short-lived and single-use; only the hash is kept.
            $table->string('pairing_code_hash', 64)->nullable()->index();
            $table->timestamp('pairing_expires_at')->nullable();
            $table->timestamp('paired_at')->nullable();
            $table->string('status')->default('pending'); // pending|active|revoked
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        // A device may only scan the events it is explicitly granted.
        Schema::create('checkin_device_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('checkin_device_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['checkin_device_id', 'event_id']);
        });

        Schema::create('checkins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('checkin_device_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('checkin_operator_id')->nullable()->constrained()->nullOnDelete();
            $table->string('result'); // valid|already_used|cancelled|refunded|wrong_event|invalid
            $table->timestampTz('scanned_at');
            $table->string('client_scan_id')->nullable();
            $table->boolean('offline')->default(false);
            $table->timestamps();
            $table->index(['event_id', 'result', 'scanned_at']);
            // Makes offline batch replay idempotent per device.
            $table->unique(['ticket_id', 'checkin_device_id', 'client_scan_id'], 'checkins_device_scan_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkins');
        Schema::dropIfExists('checkin_device_events');
        Schema::dropIfExists('checkin_devices');
        Schema::dropIfExists('checkin_operators');
    }
};
