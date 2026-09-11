<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A report that comes to you.
 *
 * The report builder can answer any question an organiser thinks to ask it, and that is the
 * problem: somebody has to think to ask. The Monday figures a marketing manager wanted every Monday
 * were a screen they had to remember to open, and the ones nobody opened were the ones that would
 * have been worth reading.
 *
 * A schedule is a saved report, a cadence and a list of people. Nothing here stores a result —
 * ADR-0006 §2 is explicit that a saved report is a definition and never a copy of numbers that may
 * since have been corrected, and a schedule does not get to break that rule because it is on a
 * timer. Every send re-runs the report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            // Deleting the report takes its schedule with it: a timer pointing at a definition that
            // no longer exists is an email nobody can explain.
            $table->foreignUuid('report_id')->constrained('reports')->cascadeOnDelete();

            $table->string('name', 120)->nullable();

            // daily | weekly | monthly. Hours are as fine as this goes: a report is read by a
            // person with a cup of tea, and one that could fire every ten minutes is an alert.
            $table->string('cadence', 12);
            $table->unsignedTinyInteger('hour')->default(8);
            // 1 = Monday, 7 = Sunday, ISO order, for weekly.
            $table->unsignedTinyInteger('weekday')->nullable();
            /*
             * 1–28 only, for monthly.
             *
             * Not 29, 30 or 31: a schedule set for the 31st would skip February entirely and half
             * the other months besides, and "it did not arrive and nobody knows why" is the worst
             * failure a scheduled report has.
             */
            $table->unsignedTinyInteger('day_of_month')->nullable();

            /*
             * The clock the hour is read in, copied from the account when the schedule is made.
             *
             * Frozen rather than followed: a venue that moves its account timezone in March has not
             * asked for the Monday report to start arriving at a different hour, and a schedule
             * that silently drifts is one somebody has to re-derive to explain.
             */
            $table->string('timezone', 64);
            $table->string('locale', 12);

            $table->jsonb('recipients')->default(DB::raw("'[]'::jsonb"));

            /*
             * Whether the email carries a link to the spreadsheet.
             *
             * The rows themselves are summarised inline; the full set is a signed link back to the
             * export, not an attachment. An attachment is a copy of numbers that may since have
             * been corrected — the thing ADR-0006 exists to prevent — and it is also how a mailbox
             * quota becomes a reporting outage.
             */
            $table->boolean('include_link')->default(true);
            $table->boolean('paused')->default(false);

            $table->timestampTz('next_run_at')->nullable();
            $table->timestampTz('last_sent_at')->nullable();
            $table->unsignedInteger('last_rows')->nullable();
            $table->text('last_error')->nullable();

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'report_id']);
            // The one query the scheduled command makes, every hour, across every account.
            $table->index(['paused', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
    }
};
