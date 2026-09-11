<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the webhook tables were missing to be usable by a person.
 *
 * The dispatcher, the signing, the backoff and the delivery log were all written and all correct.
 * What was never built was any way to reach them: no endpoint could be created, none could be seen,
 * and a delivery that failed failed where nobody would ever look. These columns are the difference
 * between a subsystem and a feature — a name to tell two endpoints apart, and the last thing that
 * happened, so a screen can answer "is my integration working" without reading the delivery table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table) {
            // What an organiser calls it. "https://shop.example.com/hooks/seatmap" is an address,
            // not a name, and a list of three of them is unreadable.
            $table->string('name')->default('')->after('api_client_id');
            $table->timestamp('last_delivered_at')->nullable()->after('consecutive_failures');
            $table->timestamp('last_failed_at')->nullable()->after('last_delivered_at');
            // The receiver's own words about what went wrong, which is almost always the answer.
            $table->string('last_error', 500)->nullable()->after('last_failed_at');
            // Why it stopped, in the vocabulary the panel translates. Set when we switch it off
            // ourselves; null when a person paused it, because that needs no explanation.
            $table->string('disabled_reason', 40)->nullable()->after('last_error');

            $table->index(['tenant_id', 'status']);
        });

        Schema::table('webhook_deliveries', function (Blueprint $table) {
            // The log is read newest-first, per tenant and per endpoint.
            $table->index(['tenant_id', 'created_at']);
            $table->index(['webhook_endpoint_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->dropIndex(['webhook_endpoint_id', 'created_at']);
            $table->dropIndex(['tenant_id', 'created_at']);
        });

        Schema::table('webhook_endpoints', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'status']);
            $table->dropColumn([
                'name', 'last_delivered_at', 'last_failed_at', 'last_error', 'disabled_reason',
            ]);
        });
    }
};
