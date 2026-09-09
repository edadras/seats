<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved reports and the pages they are arranged on (ADR-0006 §2, §3).
 *
 * A report is a definition, never a result: it is re-run when it is read, so it is never stale and
 * never a copy of data that has since been corrected. That is why there is no results table here
 * and never will be.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            // Which declared dataset it reads. Not a table name — a source key (ADR-0006 §1).
            $table->string('source_key', 60);
            $table->jsonb('definition');
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'source_key']);
        });

        Schema::create('report_pages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('slug', 120);
            // A list of widgets: {type, report_id, title, options}. The same block model the site
            // builder uses, so an organiser learns one editor rather than two (ADR-0006 §3).
            $table->jsonb('widgets')->default('[]');
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_pages');
        Schema::dropIfExists('reports');
    }
};
