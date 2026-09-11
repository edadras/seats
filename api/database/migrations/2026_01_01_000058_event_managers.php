<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who runs which night.
 *
 * An organiser's account holds a season; a programme manager holds a concert. They are given
 * everything the site's own administrator has *for that night* — the tickets, the plan, the door,
 * the reports — and nothing whatever for any other night, including the knowledge that it exists.
 *
 * A row, not a column on the membership: a manager runs three nights in March and one in June, and
 * a list that has to be one value is a list that becomes a comma-separated string within a year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_managers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            // Who handed it over, so the audit log's "why does she have this" has an answer.
            $table->foreignUuid('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'event_id']);
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_managers');
    }
};
