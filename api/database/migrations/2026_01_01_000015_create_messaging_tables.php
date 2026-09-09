<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Message templates and what happened to every message sent from them.
 *
 * The delivery log is the point of this pair. "Did the buyer get their confirmation" is asked at a
 * box office window with somebody waiting, and the answer has to be a row, not a guess about
 * whether an SMS provider was up an hour ago.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 40);      // App\Domain\Messaging\MessageKinds
            $table->string('channel', 40);   // email, sms.kavenegar, telegram, …
            $table->string('locale', 12);
            $table->string('subject', 200)->nullable(); // email only; SMS has no subject line
            $table->text('body');
            $table->timestamps();
            $table->unique(['tenant_id', 'kind', 'channel', 'locale']);
        });

        Schema::create('message_channel_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('channel', 40);
            $table->boolean('enabled')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'kind', 'channel']);
        });

        Schema::create('message_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('channel', 40);
            $table->string('recipient', 190);
            $table->string('locale', 12)->nullable();
            $table->string('status', 20); // queued|sent|refused|unavailable
            $table->string('reference')->nullable();  // the provider's own id
            $table->text('reason')->nullable();
            // A short preview, not the message. Enough for "what did we send them" at a window,
            // and not a second copy of every buyer's name and seat numbers.
            $table->string('preview', 200)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('last_attempt_at')->nullable();
            $table->foreignUuid('event_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('external_order_row_id')->nullable()
                ->constrained('external_orders')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'kind', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_deliveries');
        Schema::dropIfExists('message_channel_settings');
        Schema::dropIfExists('message_templates');
    }
};
