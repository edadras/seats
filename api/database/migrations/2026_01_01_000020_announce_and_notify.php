<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things an organiser asked messaging to do beyond confirming orders: say something to the
 * people who bought, and be told when the platform itself has something to report.
 *
 * The announcement table holds the *instruction*, not the messages. Every message it sends is an
 * ordinary `message_deliveries` row — which is where "did they get it" is already answered, is
 * already on a screen, and is already retried by `messages:retry`. That is why deliveries gain an
 * `announcement_id` rather than announcements gaining a recipients table: a second copy of every
 * buyer's address is exactly what this platform keeps deciding not to build.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            // Null means everybody who has ever bought from this account.
            $table->foreignUuid('event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('audience', 20);          // event|everyone
            $table->jsonb('channels');               // ['email', 'sms.kavenegar', …]
            $table->string('locale', 12);
            $table->string('subject', 200)->nullable(); // email only; an SMS has no subject line
            $table->text('body');
            $table->string('status', 20)->default('draft'); // draft|sending|sent
            $table->unsignedInteger('recipients')->default(0);
            $table->uuid('created_by')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::table('message_deliveries', function (Blueprint $table) {
            $table->foreignUuid('announcement_id')->nullable()->after('external_order_row_id')
                ->constrained()->cascadeOnDelete();
        });

        // The queue an announcement is sent from: its own queued rows, oldest first.
        Schema::table('message_deliveries', function (Blueprint $table) {
            $table->index(['announcement_id', 'status']);
        });

        /*
         * What the platform tells the organiser.
         *
         * One row per thing that happened, for the whole account, and a separate row per person
         * who has read it. Fanning a notification out per user instead would mean an account that
         * adds somebody on Tuesday shows them Monday's news as unread, or does not show it at all
         * depending on which way the copying went; a shared row and a read mark has neither
         * problem. Who may *see* each row is decided by the kind's permission at read time, not
         * baked in here, because roles change after the fact.
         */
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 60);   // App\Domain\Notifications\NotificationKinds
            $table->string('level', 12);  // info|warn|danger
            // Values for the kind's own sentence. Never a message: the wording is translated at
            // read time, in the reader's language, from a key this platform owns.
            $table->jsonb('params')->default('{}');
            $table->string('subject_type', 60)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->string('subject_label')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('notification_reads', function (Blueprint $table) {
            $table->foreignUuid('notification_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('read_at');
            $table->primary(['notification_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_reads');
        Schema::dropIfExists('notifications');

        Schema::table('message_deliveries', function (Blueprint $table) {
            $table->dropIndex(['announcement_id', 'status']);
            $table->dropConstrainedForeignId('announcement_id');
        });

        Schema::dropIfExists('announcements');
    }
};
